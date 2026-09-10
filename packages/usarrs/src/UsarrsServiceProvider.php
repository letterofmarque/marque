<?php

declare(strict_types=1);

namespace Marque\Usarrs;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkeys;
use Livewire\Livewire;
use Marque\Trove\Enums\Role;
use Marque\Trove\Registry\AdminScreen;
use Marque\Trove\Registry\AdminScreenRegistry;
use Marque\Trove\Registry\NavItem;
use Marque\Trove\Registry\NavRegistry;
use Marque\Usarrs\Contracts\InviteServiceInterface;
use Marque\Usarrs\Livewire\Admin\UserIndex;
use Marque\Usarrs\Livewire\Admin\UserShow;
use Marque\Usarrs\Livewire\Auth\Login;
use Marque\Usarrs\Livewire\Auth\PasswordConfirm;
use Marque\Usarrs\Livewire\Auth\Register;
use Marque\Usarrs\Livewire\Auth\TwoFactorChallenge;
use Marque\Usarrs\Livewire\Invite\InviteCreate;
use Marque\Usarrs\Livewire\Invite\InviteIndex;
use Marque\Usarrs\Livewire\Profile\AnnounceKeyManagement;
use Marque\Usarrs\Livewire\Profile\Edit;
use Marque\Usarrs\Livewire\Profile\PasskeyManagement;
use Marque\Usarrs\Livewire\Profile\Show;
use Marque\Usarrs\Livewire\Profile\TwoFactorSetup;
use Marque\Usarrs\Models\Invite;
use Marque\Usarrs\Policies\InvitePolicy;
use Marque\Usarrs\Policies\UserPolicy;
use Marque\Usarrs\Services\InviteService;

class UsarrsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/usarrs.php', 'usarrs');

        $this->app->bind(InviteServiceInterface::class, InviteService::class);

        // usarrs is the only thing allowed to register /login, /register, and
        // the rest of the auth surface. Fortify is used as an action library
        // (2FA, passkeys) — never as usarrs' front door. Called here in
        // register(), not boot(), because Fortify's own service provider reads
        // this flag during its boot() to decide whether to bind its routes.
        // Unconditional: this is what actually closes job #10583 — a Fortify
        // route reachable underneath usarrs' own auth_driver checks.
        Fortify::ignoreRoutes();

        // Unlike Fortify, Passkeys' own routes are pure WebAuthn-ceremony JSON
        // endpoints (/passkeys/login, /user/passkeys/*) with no usarrs
        // equivalent to collide with — they're left registered when the
        // feature is on, since usarrs' own UI calls them directly via JS.
        // Suppressed when the feature is off so nothing is exposed at all.
        if (! config('usarrs.passkeys.enabled', false)) {
            Passkeys::ignoreRoutes();
        } else {
            Passkeys::useUserModel(config('trove.user_model', 'App\\Models\\User'));
        }
    }

    public function boot(): void
    {
        // manage_auth is the one-way escape hatch (Spec #92): routes/auth.php
        // is usarrs' entire login/register/2FA-challenge/password-reset/
        // magic-link/socialite/logout surface, and it's skipped outright when
        // false — not registered and then 404ing via a mount() check the way
        // e.g. admin.enabled does, but genuinely never bound. routes/web.php
        // (profile, invites, admin) is unaffected either way.
        $manageAuth = config('usarrs.manage_auth', true);

        if ($manageAuth) {
            $this->loadRoutesFrom(__DIR__.'/../routes/auth.php');
        }
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'usarrs');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerPolicies();
        $this->registerNavItems();
        $this->registerAdminScreens();

        if (class_exists(Livewire::class)) {
            if ($manageAuth) {
                $this->registerAuthLivewireComponents();
            }
            $this->registerNonAuthLivewireComponents();
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/usarrs.php' => config_path('usarrs.php'),
            ], 'usarrs-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/usarrs'),
            ], 'usarrs-views');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'usarrs-migrations');
        }
    }

    protected function registerPolicies(): void
    {
        Gate::policy(Invite::class, InvitePolicy::class);

        $userModel = config('trove.user_model', 'App\\Models\\User');
        if (class_exists($userModel)) {
            Gate::policy($userModel, UserPolicy::class);
        }
    }

    /**
     * Declare usarrs' admin screens.
     *
     * usarrs keeps owning and binding its own routes — the registry entry makes
     * the screen *discoverable* from an admin panel, it does not take over the
     * routing. That separation is what lets usarrs work identically with no
     * panel installed: an unread registry entry costs nothing.
     *
     * Registered against trove, which usarrs already requires. **usarrs must
     * never depend on marque/skipper** — a screen provider that needed the
     * panel installed would defeat the whole arrangement.
     */
    protected function registerAdminScreens(): void
    {
        // Nothing to advertise when the admin surface is switched off — the
        // components 404 in that state, so listing them would be a dead link.
        if (! config('usarrs.admin.enabled', true)) {
            return;
        }

        $registry = $this->app->make(AdminScreenRegistry::class);

        // Moderator, not Admin: UserIndex::mount() enforces
        // `abort_unless(auth()->user()->isModerator(), 403)`, and a stricter
        // floor here would hide a screen moderators can legitimately use.
        $registry->register(new AdminScreen(
            identifier: 'usarrs-users',
            label: 'Users',
            component: 'usarrs-admin-user-index',
            path: 'admin/users',
            minimumRole: Role::Moderator,
            icon: 'users',
            group: 'Users',
            position: 10,
        ));
    }

    /**
     * Declare usarrs' navigation entries.
     *
     * The shell used to add Profile itself after detecting usarrs, and to render
     * an `admin.index` link for any admin — a route nothing has ever registered.
     * Both now come from here: usarrs owns the routes, so usarrs owns the
     * entries, and the admin entry points at `admin.users.index`, which actually
     * exists.
     */
    protected function registerNavItems(): void
    {
        $registry = $this->app->make(NavRegistry::class);

        $registry->register(new NavItem(
            identifier: 'usarrs-profile',
            label: 'Profile',
            route: 'profile.show',
            icon: 'user',
            position: 50,
            visible: fn (?object $user): bool => $user !== null,
        ));

        // Respects the same admin.enabled flag the rest of the admin surface
        // uses — a disabled admin should not advertise itself in the nav.
        if (config('usarrs.admin.enabled', true)) {
            $registry->register(NavItem::forRole(
                identifier: 'usarrs-admin',
                label: 'Admin',
                route: 'admin.users.index',
                minimumRole: Role::Admin,
                icon: 'cog-6-tooth',
                position: 90,
            ));
        }
    }

    // The Login/Register/2FA-challenge/2FA-setup/passkey-management surface —
    // gated by manage_auth alongside routes/auth.php. TwoFactorSetup and
    // PasskeyManagement have no route of their own (they're mounted wherever
    // the consuming app's own settings UI puts them), but they're still part
    // of "the auth UI" a power-user going fully custom would replace, so they
    // gate with the rest rather than living in the non-auth group below.
    protected function registerAuthLivewireComponents(): void
    {
        Livewire::component('usarrs-login', Login::class);
        Livewire::component('usarrs-register', Register::class);
        Livewire::component('usarrs-two-factor-challenge', TwoFactorChallenge::class);
        Livewire::component('usarrs-two-factor-setup', TwoFactorSetup::class);
        Livewire::component('usarrs-passkey-management', PasskeyManagement::class);
        Livewire::component('usarrs-password-confirm', PasswordConfirm::class);
    }

    // Profile, invites, admin — unaffected by manage_auth in either state.
    protected function registerNonAuthLivewireComponents(): void
    {
        Livewire::component('usarrs-profile-show', Show::class);
        Livewire::component('usarrs-profile-edit', Edit::class);
        Livewire::component('usarrs-announce-key-management', AnnounceKeyManagement::class);
        Livewire::component('usarrs-admin-user-index', UserIndex::class);
        Livewire::component('usarrs-admin-user-show', UserShow::class);
        Livewire::component('usarrs-invite-index', InviteIndex::class);
        Livewire::component('usarrs-invite-create', InviteCreate::class);
    }
}
