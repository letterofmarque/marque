<?php

declare(strict_types=1);

namespace Marque\Usarrs;

use Illuminate\Auth\Events\Login as LoginEvent;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\Routing;
use Illuminate\Routing\RouteCollection;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkeys;
use Livewire\Livewire;
use Marque\Trove\Contracts\SeedboxesInterface;
use Marque\Trove\Contracts\TrackerStatsInterface;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Enums\Role;
use Marque\Trove\Registry\AdminScreen;
use Marque\Trove\Registry\AdminScreenRegistry;
use Marque\Trove\Registry\DashboardPanel;
use Marque\Trove\Registry\DashboardPanelRegistry;
use Marque\Trove\Registry\NavItem;
use Marque\Trove\Registry\NavRegistry;
use Marque\Usarrs\Auth\SocialiteOAuthProvider;
use Marque\Usarrs\Contracts\InviteServiceInterface;
use Marque\Usarrs\Contracts\OAuthProvider;
use Marque\Usarrs\Enums\UserStatus;
use Marque\Usarrs\Http\Middleware\EnsureUserIsActive;
use Marque\Usarrs\Listeners\RefuseInactiveLogin;
use Marque\Usarrs\Listeners\StampSessionPasswordHash;
use Marque\Usarrs\Livewire\Admin\SeedboxIndex;
use Marque\Usarrs\Livewire\Admin\UserIndex;
use Marque\Usarrs\Livewire\Admin\UserShow;
use Marque\Usarrs\Livewire\Auth\ConfirmOAuthLink;
use Marque\Usarrs\Livewire\Auth\Login;
use Marque\Usarrs\Livewire\Auth\PasswordConfirm;
use Marque\Usarrs\Livewire\Auth\Register;
use Marque\Usarrs\Livewire\Auth\TwoFactorChallenge;
use Marque\Usarrs\Livewire\Dashboard\AnnounceKeyPanel;
use Marque\Usarrs\Livewire\Dashboard\Index as DashboardIndex;
use Marque\Usarrs\Livewire\Dashboard\InvitesPanel;
use Marque\Usarrs\Livewire\Dashboard\SecurityPanel;
use Marque\Usarrs\Livewire\Dashboard\TrackerStatsPanel;
use Marque\Usarrs\Livewire\Invite\InviteCreate;
use Marque\Usarrs\Livewire\Invite\InviteIndex;
use Marque\Usarrs\Livewire\Profile\AnnounceKeyManagement;
use Marque\Usarrs\Livewire\Profile\Edit;
use Marque\Usarrs\Livewire\Profile\PasskeyManagement;
use Marque\Usarrs\Livewire\Profile\SeedboxManagement;
use Marque\Usarrs\Livewire\Profile\SessionManagement;
use Marque\Usarrs\Livewire\Profile\Show;
use Marque\Usarrs\Livewire\Profile\TwoFactorSetup;
use Marque\Usarrs\Models\Invite;
use Marque\Usarrs\Policies\InvitePolicy;
use Marque\Usarrs\Policies\UserPolicy;
use Marque\Usarrs\Services\InviteService;
use Throwable;

class UsarrsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/usarrs.php', 'usarrs');

        $this->app->bind(InviteServiceInterface::class, InviteService::class);

        // Socialite behind a seam usarrs owns (Spec #142): only
        // SocialiteOAuthProvider names Socialite, and tests swap in a fake.
        $this->app->bind(OAuthProvider::class, SocialiteOAuthProvider::class);

        // usarrs is the only thing allowed to register /login, /register, and
        // the rest of the auth surface. Fortify is used as an action library
        // (2FA, passkeys) — never as usarrs' front door. Called here in
        // register(), not boot(), because Fortify's own service provider reads
        // this flag during its boot() to decide whether to bind its routes.
        // Unconditional: this is what actually closes job #10583 — a Fortify
        // route reachable underneath usarrs' own auth_driver checks.
        Fortify::ignoreRoutes();

        // While usarrs manages auth, laravel/passkeys never registers its own
        // routes: usarrs registers the same endpoints in routes/auth.php, with
        // its own middleware. Left to themselves they were at the mercy of
        // whichever Fortify was installed. Fortify 1.39 suppresses them to
        // serve its own, which usarrs suppresses in turn, so the endpoints
        // existed nowhere (#10883). With manage_auth off, routes/auth.php isn't
        // loaded, so laravel/passkeys keeps its own when passkeys are on (Job
        // #141 review), and nothing is exposed when they're off.
        if (config('usarrs.manage_auth', true) || ! config('usarrs.passkeys.enabled', false)) {
            Passkeys::ignoreRoutes();
        }
    }

    /**
     * Run EnsureUserIsActive on every web and API request.
     *
     * The `api` group too (#10920): cennad's routes are ['api', 'auth:api'],
     * and a ban that held on the web but not on a token would leave a banned
     * member reading and writing through the API. On a token route the user
     * is only known once the guard has run, so the check is ordered after
     * authentication in the middleware priority; Laravel sorts a route's
     * stack by it.
     *
     * Through the HTTP kernel rather than the router: any later kernel change
     * re-syncs the router from the kernel's own lists, which would silently
     * drop a group entry or a priority made on the router alone.
     */
    private function enforceActiveUsers(): void
    {
        $kernel = $this->app->make(HttpKernel::class);

        if (! $kernel instanceof FoundationHttpKernel) {
            $this->app['router']->pushMiddlewareToGroup('web', EnsureUserIsActive::class);
            $this->app['router']->pushMiddlewareToGroup('api', EnsureUserIsActive::class);

            return;
        }

        foreach (['web', 'api'] as $group) {
            if (array_key_exists($group, $kernel->getMiddlewareGroups())) {
                $kernel->appendMiddlewareToGroup($group, EnsureUserIsActive::class);
            }
        }

        $kernel->addToMiddlewarePriorityAfter(AuthenticatesRequests::class, EnsureUserIsActive::class);
    }

    public function boot(): void
    {
        // Every sign-in records the password hash it signed in under, so
        // auth.session can end it if that hash rotates (CP #776).
        Event::listen(LoginEvent::class, StampSessionPasswordHash::class);

        // A banned user is refused however they sign in, and a live session
        // ends on its next request to any web page, not just usarrs' (#10857).
        Event::listen(LoginEvent::class, RefuseInactiveLogin::class);
        $this->enforceActiveUsers();

        if (config('usarrs.passkeys.enabled', false)) {
            $this->configurePasskeys();
        }

        // Livewire re-applies a route's middleware to its component updates
        // only from a fixed list, which names Jetstream's AuthenticateSession
        // but not Laravel's. Without this, a tab opened before the hash
        // rotated kept working — every action in it (CP #777).
        Livewire::addPersistentMiddleware([AuthenticateSession::class]);

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
        $this->registerDashboardPanels();

        // Only once the app's own routes have loaded can a route that replaced
        // ours be seen. Uncached, they load as each provider boots, so after
        // boot is enough. A cached table is loaded by an app-booted callback
        // registered after this one, so it is empty here; then ask on the
        // first request, which every cached (production) install gets.
        if ($this->app->routesAreCached()) {
            Event::listen(Routing::class, function (): void {
                static $checked = false;

                if (! $checked) {
                    $checked = true;
                    $this->reportDashboardCollision();
                }
            });
        } else {
            $this->app->booted(fn () => $this->reportDashboardCollision());
        }

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

    /**
     * Set at boot, after Fortify's register() has rewritten laravel/passkeys'
     * config to suit Fortify's own routes (#10883).
     */
    protected function configurePasskeys(): void
    {
        // Fortify resets this to the auth provider's model in its register().
        Passkeys::useUserModel(config('trove.user_model', 'App\\Models\\User'));

        // Its own bucket. An unnamed throttle keys on domain and IP, shared
        // with every other unnamed throttle in the app (Job #141 review).
        // Ten requests a minute is five sign-ins: options, then the assertion.
        RateLimiter::for('usarrs-passkeys', fn (Request $request) => Limit::perMinute(10)
            ->by('usarrs-passkeys|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // A banned user's passkey signs no one in: refused with a 422 before
        // any session exists. The Login listener stays as the backstop.
        Passkeys::authorizeLoginUsing(fn ($request, $user) => UserStatus::refusalFor($user) === null);

        // Where a passkey sign-in lands. Fortify sets this to its own home
        // (/home); every other usarrs sign-in lands on the site root.
        config(['passkeys.redirect' => '/']);
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

        // Only where a tracker keeps seedboxes: the screen 404s otherwise. The
        // tracker binds in its register(), which has run by now (boot).
        if ($this->app->bound(SeedboxesInterface::class)) {
            $registry->register(new AdminScreen(
                identifier: 'usarrs-seedboxes',
                label: 'Seedboxes',
                component: 'usarrs-admin-seedbox-index',
                path: 'admin/seedboxes',
                // SeedboxIndex refuses anyone below admin.
                minimumRole: Role::Admin,
                icon: 'server',
                group: 'Users',
                position: 20,
            ));
        }
    }

    /**
     * Say so when an app route has taken the dashboard's path (#11070).
     *
     * Two routes on one method and URI can't coexist: the one registered later
     * replaces the other in the route table, and the app's routes load after
     * every package's. So a starter kit's /dashboard silently removes usarrs'
     * and the name dashboard.index with it. deck leaves the dead nav link out;
     * this tells the operator why, and the two ways out. Once a day, because
     * it is decided on every boot and the answer doesn't change between them.
     */
    public function reportDashboardCollision(): void
    {
        $path = trim(implode('/', array_filter([config('usarrs.prefix', ''), config('usarrs.dashboard.path', 'dashboard')])), '/');
        $routes = Route::getRoutes();

        // Asks which route is AT the path, not whether the name exists. This
        // runs before the framework refreshes its name lookups (that is an
        // app-booted callback registered after this one), so the lookup still
        // names our replaced route and Route::has() would answer yes. The
        // table keyed by method and URI is already current, and one lookup.
        // A cached route table is compiled, its names already right.
        if ($routes instanceof RouteCollection) {
            $taker = $routes->getRoutesByMethod()['GET'][$path] ?? null;
        } else {
            if (Route::has('dashboard.index')) {
                return;
            }

            $taker = rescue(fn () => $routes->match(Request::create('/'.$path)), null, false);
        }

        // Ours, or nothing there at all: no collision to name.
        if ($taker === null || $taker->getName() === 'dashboard.index') {
            return;
        }

        try {
            if (! Cache::add('usarrs:dashboard-collision-reported', true, 86_400)) {
                return;
            }
        } catch (Throwable) {
            // No cache: report anyway. An extra line beats a silent dashboard.
        }

        $name = $taker->getName() !== null ? "'{$taker->getName()}'" : 'an unnamed route';

        Log::error("usarrs: the dashboard at /{$path} has been replaced by your app's own route there ({$name}, {$taker->getActionName()}), "
            .'so dashboard.index does not exist and nothing links to the dashboard. Either remove your route and move what it showed into '
            .'dashboard panels (see trove\'s README, "Dashboard panels"), or keep it and move usarrs\' dashboard elsewhere with '
            .'usarrs.dashboard.path (USARRS_DASHBOARD_PATH).');
    }

    /**
     * Declare the tracker panels on the user dashboard.
     *
     * usarrs renders them, but only when a tracker is bound: that is the one
     * capability check, answered by the tracker rather than inferred here
     * (Spec #119). Not ratio_mode — nothing reads it (job #10732), and the
     * figures are the same in every mode. Not "is bloodhound installed" either;
     * usarrs names no tracker.
     *
     * Asked in boot() and safe to ask there: a tracker binds in its own
     * register(), and every provider's register() runs before any boot().
     *
     * Whether THIS user has anything to show is per-request, so it lives in the
     * visibility closures.
     */
    protected function registerDashboardPanels(): void
    {
        $registry = $this->app->make(DashboardPanelRegistry::class);

        $this->registerOwnDashboardPanels($registry);

        if (! $this->app->bound(TrackerStatsInterface::class)) {
            return;
        }

        $registry->register(new DashboardPanel(
            identifier: 'usarrs-tracker-stats',
            label: 'Tracker Stats',
            component: 'usarrs-dashboard-tracker-stats',
            position: 10,
            visible: fn (?object $user): bool => $user instanceof UserInterface
                && app(TrackerStatsInterface::class)->statsFor($user) !== null,
        ));

        // Shown whether or not the user has a key yet: the panel is how they get
        // one (Generate), or learn why they can't (verify first). Hiding it
        // until a key existed left an imported or keyless user with nothing on
        // the dashboard at all (#11070).
        $registry->register(new DashboardPanel(
            identifier: 'usarrs-announce-key',
            label: 'Announce Key',
            component: 'usarrs-dashboard-announce-key',
            position: 20,
            visible: fn (?object $user): bool => $user instanceof UserInterface
                && config('usarrs.profile.show_announce_key', true),
        ));
    }

    /**
     * The panels for data usarrs owns outright: account security and invites.
     *
     * Two-factor, passkeys and invites are each switched on by config, and
     * usarrs already reads those flags per request — TwoFactorSetup,
     * PasskeyManagement and InviteIndex all check in mount(). The panels read
     * them the same way, from their visibility closures, rather than freezing
     * a second answer at boot.
     *
     * manage_auth is decided at boot like the rest of the auth surface. The
     * security panel reports Fortify's two-factor columns and usarrs' passkeys,
     * which a fully custom auth implementation has replaced — so under
     * manage_auth=false it would be describing the wrong system. Invites are
     * not auth and register either way.
     */
    protected function registerOwnDashboardPanels(DashboardPanelRegistry $registry): void
    {
        if (config('usarrs.manage_auth', true)) {
            $registry->register(new DashboardPanel(
                identifier: 'usarrs-security',
                label: 'Account Security',
                component: 'usarrs-dashboard-security',
                position: 30,
                visible: fn (?object $user): bool => SecurityPanel::appliesTo($user),
            ));
        }

        $registry->register(new DashboardPanel(
            identifier: 'usarrs-invites',
            label: 'Invites',
            component: 'usarrs-dashboard-invites',
            position: 40,
            visible: fn (?object $user): bool => InvitesPanel::appliesTo($user),
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

        // The dashboard route exists on every install (Spec #118), so the link
        // is always safe to render. First, because it is the page that answers
        // "how am I doing" — the natural place for a signed-in user to start.
        $registry->register(new NavItem(
            identifier: 'usarrs-dashboard',
            label: 'Dashboard',
            route: 'dashboard.index',
            position: 5,
            visible: fn (?object $user): bool => $user !== null,
        ));

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
        Livewire::component('usarrs-confirm-oauth-link', ConfirmOAuthLink::class);
    }

    // Profile, invites, admin — unaffected by manage_auth in either state.
    protected function registerNonAuthLivewireComponents(): void
    {
        Livewire::component('usarrs-dashboard-index', DashboardIndex::class);
        Livewire::component('usarrs-dashboard-tracker-stats', TrackerStatsPanel::class);
        Livewire::component('usarrs-dashboard-announce-key', AnnounceKeyPanel::class);
        Livewire::component('usarrs-dashboard-security', SecurityPanel::class);
        Livewire::component('usarrs-dashboard-invites', InvitesPanel::class);
        Livewire::component('usarrs-profile-show', Show::class);
        Livewire::component('usarrs-profile-edit', Edit::class);
        Livewire::component('usarrs-announce-key-management', AnnounceKeyManagement::class);
        Livewire::component('usarrs-admin-user-index', UserIndex::class);
        Livewire::component('usarrs-admin-user-show', UserShow::class);
        Livewire::component('usarrs-admin-seedbox-index', SeedboxIndex::class);
        Livewire::component('usarrs-seedbox-management', SeedboxManagement::class);
        Livewire::component('usarrs-session-management', SessionManagement::class);
        Livewire::component('usarrs-invite-index', InviteIndex::class);
        Livewire::component('usarrs-invite-create', InviteCreate::class);
    }
}
