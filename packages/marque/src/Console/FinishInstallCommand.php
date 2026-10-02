<?php

declare(strict_types=1);

namespace Marque\Marque\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Marque\Marque\Install\AdminSeeder;
use Marque\Marque\Install\InstallAnswers;
use Marque\Marque\Install\PackageSelection;
use Marque\Marque\Install\SelfVerification;

/**
 * The last part of marque:install: config, migrations, the admin account and
 * the self-check — everything that needs the installed packages' code loaded.
 *
 * Its own command because it has to run in a fresh process (Job #131). The
 * process that ran composer require keeps the autoloader and providers it
 * booted with, so on a first run nothing it had just installed was loaded:
 * bloodhound's migrations never ran, guise's routes didn't exist and /torrents
 * 404ed. A cold run from `laravel new` measured exactly that (2026-10-01).
 *
 * The file edits (stylesheet, User model, home page) are made by marque:install
 * *before* this starts, for the same reason: this process loads App\Models\User
 * while it boots, so a User model patched from inside it stays unpatched in
 * memory — the second cold run crashed seeding the admin on isAdmin() exactly
 * so. Booting after the edits, it loads them.
 *
 * It asks nothing, so it needs no terminal. Hidden: marque:install runs it.
 */
class FinishInstallCommand extends Command
{
    protected $signature = 'marque:install:finish {answers : What the interview decided, as marque:install encodes it}';

    protected $description = 'Finish a marque:install in a fresh process (run by marque:install itself)';

    protected $hidden = true;

    /**
     * Packages that arrive with marque/marque itself. They ship most of the
     * app's chrome, so their templates need scanning regardless of what the
     * operator chose.
     *
     * @var list<string>
     */
    private const CORE_PACKAGES = ['marque/deck', 'marque/usarrs'];

    private ?string $adminName = null;

    private ?string $adminEmail = null;

    /** Whether this run sent the admin their set-your-password link. */
    private bool $adminLinkSent = false;

    public function handle(): int
    {
        $answers = InstallAnswers::decode((string) $this->argument('answers'));
        $selection = $answers->selection;
        $this->adminName = $answers->adminName;
        $this->adminEmail = $answers->adminEmail;

        $this->publishAndMigrate($selection);
        $this->seedAdmin();

        return $this->selfVerify($selection);
    }

    private function publishAndMigrate(PackageSelection $selection): void
    {
        $this->newLine();
        $this->components->info('Publishing config and running migrations');

        foreach ([...self::CORE_PACKAGES, ...$selection->packages()] as $package) {
            $tag = str_replace('marque/', '', $package).'-config';

            // Not --force: a published config the operator has edited is
            // theirs, and overwriting it would discard their settings.
            $this->callSilently('vendor:publish', ['--tag' => $tag]);
        }

        $this->call('migrate', ['--force' => true]);
    }

    /**
     * Creates the first admin, then emails a password reset link.
     *
     * Nobody chooses a password: the row is seeded with random bytes and the
     * reset flow sets the real one. That single email verifies the address,
     * exercises the mailer, and avoids an interim credential existing at all.
     */
    private function seedAdmin(): void
    {
        if ($this->adminEmail === null || $this->adminName === null) {
            return;
        }

        $this->newLine();

        $users = $this->laravel['config']->get('auth.providers.users.model');

        if (! is_string($users) || ! class_exists($users)) {
            $this->components->warn('Could not find the user model — skipping the admin account.');

            return;
        }

        $seeder = new AdminSeeder;

        if ($seeder->adminExists(fn (): int => $users::query()->where('role', 'admin')->count())) {
            $this->components->task('An admin already exists — leaving it alone');

            return;
        }

        if ($users::query()->where('email', $this->adminEmail)->exists()) {
            $this->components->warn("A user with {$this->adminEmail} already exists — skipping.");

            return;
        }

        $admin = $users::query()->create($seeder->attributesFor($this->adminName, $this->adminEmail));

        // Assigned rather than mass-assigned: stock Laravel's User declares
        // #[Fillable] without `role`, so passing it to create() drops it
        // silently and yields an "admin" that is not one.
        $admin->role = $seeder->role();
        $admin->save();

        if (! $admin->isAdmin()) {
            $this->components->error('The admin account was created but could not be given the admin role.');
            $this->line('  Set it by hand: UPDATE users SET role = \'admin\' WHERE email = \''.$this->adminEmail.'\';');

            return;
        }

        $this->components->task("Admin account created for {$this->adminEmail}");

        $status = Password::sendResetLink(['email' => $this->adminEmail]);

        if ($status === Password::RESET_LINK_SENT) {
            $this->adminLinkSent = true;
            $this->line('  Sent a link to set your password. It also verifies the address.');

            return;
        }

        // The account exists but is unreachable, which the operator must know
        // about rather than discover at the login screen.
        $this->components->error('The admin account was created, but the password reset email '
            ."could not be sent ({$status}).");
        $this->line('  Check your mail settings, then use Forgot Password on the login page.');
    }

    /**
     * The last thing the installer does, and the reason Spec #115 exists.
     *
     * "Files written" and "working" diverged badly enough to motivate this
     * whole Build: the suite registered thirty-odd routes, every file was in
     * place, and the app still showed Laravel's welcome page with a fatal on
     * its main listing. So the installer exercises what it built instead of
     * inferring success from having finished.
     */
    private function selfVerify(PackageSelection $selection): int
    {
        $this->newLine();
        $this->components->info('Checking that it actually works');

        $verification = new SelfVerification;

        $verification->check('/', fn (): int => $this->statusOf('/'));

        if ($selection->isPrivate()) {
            $verification->check('/login', fn (): int => $this->statusOf('/login'));
        }

        // Authenticated, and a redirect counts as a failure here.
        //
        // Probing /torrents as a guest proves nothing on a private tracker:
        // auth middleware 302s to login before the controller runs, so a
        // broken User model (the exact fatal this installer exists to fix)
        // sails through looking healthy. Found 2026-09-14 by reverting the
        // User model and watching verification pass anyway.
        $admin = $this->firstAdmin();

        $verification->check(
            '/torrents',
            fn (): int => $this->statusOf('/torrents', $admin),
            mustReachApp: $admin !== null,
        );

        // The dashboard exists on every install and renders whatever panels
        // the installed packages registered — so reaching it as the admin
        // exercises the tracker stats binding and every panel's component, not
        // just a route. Resolved by name because usarrs.prefix can move it.
        $verification->check(
            '/dashboard',
            fn (): int => $this->statusOf(route('dashboard.index', absolute: false), $admin),
            mustReachApp: $admin !== null,
        );

        foreach ($verification->results() as $result) {
            $this->components->twoColumnDetail(
                '  '.$result->name,
                $result->passed ? '<fg=green>ok</>' : '<fg=red>'.$result->detail.'</>',
            );
        }

        $this->newLine();

        if ($verification->failed()) {
            $this->components->error('Marque is installed, but not everything responds.');

            foreach ($verification->failures() as $failure) {
                $this->line("  <fg=red>{$failure->name}</> — {$failure->detail}");
            }

            $this->line('  Fix the above and run marque:install again; it is safe to re-run.');

            return self::FAILURE;
        }

        $this->components->info('Marque is installed and responding.');
        $this->reportOutstanding();

        return self::SUCCESS;
    }

    private function statusOf(string $uri, mixed $as = null): int
    {
        if ($as !== null) {
            // As the admin will be once they've followed their emailed link,
            // which verifies the address. The User model implements
            // MustVerifyEmail (Job #131), so an as-yet-unverified admin is
            // bounced off every `verified` page — /torrents included — and the
            // check would prove nothing. Set on this probe's copy, never saved.
            if ($as instanceof MustVerifyEmail && ! $as->hasVerifiedEmail()) {
                $as = (clone $as)->forceFill(['email_verified_at' => now()]);
            }

            Auth::login($as);
        }

        $request = Request::create($uri, 'GET');

        try {
            return $this->laravel->handle($request)->getStatusCode();
        } finally {
            if ($as !== null) {
                Auth::logout();
            }
        }
    }

    /**
     * Someone to make the authenticated checks meaningful. Null on a public
     * tracker or an install that declined the admin account, in which case the
     * guest probe is the best available and is not treated as proof.
     */
    private function firstAdmin(): mixed
    {
        $users = $this->laravel['config']->get('auth.providers.users.model');

        if (! is_string($users) || ! class_exists($users)) {
            return null;
        }

        return $users::query()->where('role', 'admin')->first();
    }

    /**
     * What the installer deliberately did not do. Saying so plainly is the
     * difference between a finished install and one that merely stopped.
     */
    private function reportOutstanding(): void
    {
        $this->newLine();
        $this->line('  Worth knowing:');

        // The cold run from `laravel new` ended with every route 500ing on a
        // missing Vite manifest: the @source lines were written but the assets
        // had never been compiled. Said up front rather than left to be
        // discovered.
        if (! is_file($this->laravel->publicPath('build/manifest.json'))) {
            $this->line('  • Your assets are not built yet — run `npm install && npm run build`');
            $this->line('    (or `npm run dev`), or every page will fail on a missing manifest.');
        }

        if ($this->adminLinkSent) {
            $this->line("  • Check {$this->adminEmail} for the link that sets your password.");
        } elseif ($this->firstAdmin() === null) {
            $this->line('  • There is no admin account yet. Re-run marque:install to add one.');
        }

        // Said only when it's still true: the User model patch adds the
        // interface unless the operator declined it (Job #131).
        $users = $this->laravel['config']->get('auth.providers.users.model');

        if (is_string($users) && class_exists($users) && ! is_subclass_of($users, 'Illuminate\Contracts\Auth\MustVerifyEmail')) {
            $this->line('  • Email verification is not enforced: your User model does not implement');
            $this->line('    MustVerifyEmail, so the `verified` middleware on /admin passes everyone.');
            $this->line('    Enabling it later asks existing unverified users to verify first.');
        }
    }
}
