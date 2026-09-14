<?php

declare(strict_types=1);

namespace Marque\Marque\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use Marque\Marque\Install\AdminSeeder;
use Marque\Marque\Install\ComposerRunner;
use Marque\Marque\Install\EnvironmentCheck;
use Marque\Marque\Install\EnvironmentReport;
use Marque\Marque\Install\EnvWriter;
use Marque\Marque\Install\HomePage;
use Marque\Marque\Install\PackageSelection;
use Marque\Marque\Install\SelfVerification;
use Marque\Marque\Install\StylesheetWiring;
use Marque\Marque\Install\UserModelPatch;
use RuntimeException;

/**
 * The front door.
 *
 * Stages land one Checkpoint at a time (Build #105). Live now: the environment
 * gate, the interview, composer require, the Tailwind source wiring, the User
 * model edit, the home page, config publishing, migrations, the admin account
 * and a self-verification pass.
 *
 * That last stage is the point. Spec #115 exists because "files written" and
 * "working" diverged — the suite registered thirty-odd routes, every file was
 * in place, and the app still showed Laravel's welcome page with a fatal on
 * its main listing. So this command exercises what it built and only reports
 * success when the app actually responds.
 */
class InstallCommand extends Command
{
    /**
     * Packages that arrive with marque/marque itself. They ship most of the
     * app's chrome, so their templates need scanning regardless of what the
     * operator chose.
     *
     * @var list<string>
     */
    private const CORE_PACKAGES = ['marque/deck', 'marque/usarrs'];

    /**
     * Collected in the interview, acted on after migrations. See
     * askAboutAdmin() for why the two are separated.
     */
    private ?string $adminName = null;

    private ?string $adminEmail = null;

    protected $signature = 'marque:install';

    protected $description = 'Install and wire up Marque — interviews you, then configures the app';

    public function handle(EnvironmentCheck $check): int
    {
        // Split deliberately. The database is required no matter what the
        // operator answers, and discovering it is unreachable AFTER a page of
        // questions wastes their time — so it is checked first. Redis is
        // different: whether it is needed depends on whether this deployment
        // announces, which only the interview can say.
        $this->components->info('Checking the environment');

        $report = $check->runUnconditional();

        $this->renderReport($report);

        if ($report->isFatal()) {
            $this->newLine();
            $this->components->error('Nothing has been changed. Fix the above and run marque:install again.');

            return self::FAILURE;
        }

        $this->newLine();
        $selection = $this->interview();

        $check->runConditional($report, $selection->needsRedis());

        if ($report->isFatal()) {
            $this->newLine();
            $this->renderReport($report, only: ['redis']);

            // Deliberately NOT "nothing has been changed": the interview may
            // have written APP_NAME to .env by this point. No packages were
            // installed and no app code was touched, and saying exactly that
            // is worth more than a reassuring sentence that is not quite true.
            $this->components->error('No packages were installed and no app code was touched. '
                .'Fix the above and run marque:install again.');

            return self::FAILURE;
        }

        if (! $this->installPackages($selection)) {
            return self::FAILURE;
        }

        $this->wireStylesheet($selection);
        $this->patchUserModel($selection);
        $this->chooseHomePage();
        $this->publishAndMigrate($selection);
        $this->seedAdmin();

        return $this->selfVerify($selection);
    }

    /**
     * Runs between the gate's two passes: after the unconditional checks, so
     * nobody answers a page of questions only to be told their database is
     * unreachable, and before the Redis check, which needs to know whether
     * this deployment announces.
     *
     * Nothing here writes to disk except the site name, and that only after
     * the operator has typed one — so a refusal on the far side is still
     * honest when it says nothing was changed.
     */
    private function interview(): PackageSelection
    {
        $this->components->info('Setting up Marque');

        $type = select(
            label: 'What kind of tracker is this?',
            options: [
                'private' => 'Private — accounts, invites, ratio tracking',
                'public' => 'Public — open announce, no account needed',
            ],
            default: 'private',
        );

        $selection = $type === 'private'
            ? PackageSelection::private()
            : PackageSelection::public();

        if (confirm(label: 'Add the REST API?', default: false)) {
            $selection = $selection->withApi();
        }

        if (confirm(label: 'Add forums and torrent comments?', default: false)) {
            $selection = $selection->withForums();
        }

        if (confirm(label: 'Add the content taxonomy engine?', default: false)) {
            $selection = $selection->withTaxonomy();
        }

        if (confirm(label: 'Add the admin panel?', default: true)) {
            $selection = $selection->withAdminPanel();
        }

        $this->setSiteName();
        $this->askAboutAdmin();

        return $selection;
    }

    /**
     * Asked here, acted on at the end.
     *
     * The admin cannot be created until migrations have run, which cannot
     * happen until the packages are installed — but the interview is the only
     * place the operator is asked anything, and coming back to find the
     * install stopped on a prompt is the "looks hung" failure the streamed
     * composer output exists to avoid. So the questions live together and the
     * work happens later.
     *
     * No password is asked for. The account is seeded with random bytes and a
     * reset link is emailed, which sets the password through the real flow,
     * proves the address is real and proves the mailer works.
     */
    private function askAboutAdmin(): void
    {
        if (! confirm(label: 'Create an admin account?', default: true)) {
            return;
        }

        $this->adminName = text(
            label: 'What is the admin called?',
            placeholder: 'Your name',
            required: true,
        );

        $this->adminEmail = text(
            label: 'What email address?',
            placeholder: 'you@example.com',
            required: true,
            hint: 'We will email a link to set the password — which also verifies the address.',
        );

        $error = (new AdminSeeder)->validate($this->adminName, $this->adminEmail);

        if ($error !== null) {
            $this->components->warn($error.' Skipping the admin account.');
            $this->adminName = null;
            $this->adminEmail = null;
        }
    }

    /**
     * deck.app_name already reads APP_NAME, so the site name goes there rather
     * than into a new config key nobody would think to look for.
     */
    private function setSiteName(): void
    {
        $current = (string) config('app.name');

        $name = text(
            label: 'What is this tracker called?',
            placeholder: $current,
            default: $current === 'Laravel' ? '' : $current,
            hint: 'Shown in the page title and the app shell.',
        );

        if (trim($name) === '' || $name === $current) {
            return;
        }

        try {
            (new EnvWriter($this->laravel->basePath('.env')))->set('APP_NAME', $name);
            $this->components->task('APP_NAME set to '.$name);
        } catch (RuntimeException $e) {
            // Not fatal. A missing .env is unusual, the rest of the install is
            // still worth doing, and skipping it silently would be worse.
            $this->components->warn('Could not set APP_NAME: '.$e->getMessage());
        }
    }

    private function installPackages(PackageSelection $selection): bool
    {
        $packages = $selection->packages();

        $this->newLine();
        $this->components->info('Installing '.implode(', ', $packages));

        $result = (new ComposerRunner($this->laravel->basePath()))
            ->require($packages, function (string $chunk): void {
                $this->output->write($chunk);
            });

        if (! $result->successful) {
            $this->newLine();
            $this->components->error('composer require failed. Its output is above, verbatim — '
                .'the constraint it names is what needs fixing.');

            return false;
        }

        return true;
    }

    /**
     * Tailwind emits only the classes it can find. Without these lines the
     * packages' templates are never scanned, so none of their classes exist in
     * the built stylesheet and the app renders with browser defaults — which
     * is the entire "dark mode is broken" report, and not a component bug.
     */
    private function wireStylesheet(PackageSelection $selection): void
    {
        $path = $this->laravel->resourcePath('css/app.css');

        if (! is_file($path)) {
            $this->newLine();
            $this->components->warn('No resources/css/app.css — skipping stylesheet wiring.'
                ."\n".'  Point Tailwind at vendor/marque/*/resources/views yourself, or the'
                ."\n".'  packages\' templates will render unstyled.');

            return;
        }

        // Every package that ships templates, not merely the ones chosen:
        // deck and usarrs arrive with marque/marque itself and carry most of
        // the app's chrome.
        $packages = [...self::CORE_PACKAGES, ...$selection->packages()];

        $wiring = new StylesheetWiring($path);
        $pending = $wiring->pending($packages);

        $this->newLine();

        if ($pending === []) {
            $this->components->task('Stylesheet already knows where the templates are');

            return;
        }

        $this->components->info('Your stylesheet needs to know where the packages\' templates are');
        $this->line('  Tailwind only generates classes it can find. Without these, the');
        $this->line('  packages render unstyled. To be added to resources/css/app.css:');
        $this->newLine();

        foreach ($pending as $line) {
            $this->line('    <fg=green>+</> '.$line);
        }

        $this->newLine();

        if (! confirm(label: 'Add them?', default: true)) {
            $this->components->warn('Skipped. The app will render unstyled until those lines exist.');

            return;
        }

        copy($path, $path.'.marque-backup');

        $wiring->wire($packages);

        $this->components->task('resources/css/app.css updated (backup at app.css.marque-backup)');
        $this->line('  Run your asset build (npm run build, or npm run dev) to see it.');
    }

    /**
     * The headline fatal from job #10699: a stock User model has none of the
     * Marque traits, so /torrents — the product's main page — dies with
     * `Call to undefined method App\Models\User::isUploader()`.
     *
     * This edits a file the app owns and did not ask us to touch, so the
     * operator sees the diff and says yes before anything is written, and the
     * original is kept beside it.
     */
    private function patchUserModel(PackageSelection $selection): void
    {
        $path = $this->laravel->basePath('app/Models/User.php');

        if (! is_file($path)) {
            $this->newLine();
            $this->components->warn('No app/Models/User.php — skipping.'
                ."\n".'  Add Marque\Trove\Concerns\HasRoles and implement'
                ."\n".'  Marque\Trove\Contracts\UserInterface on your own user model.');

            return;
        }

        $patch = new UserModelPatch($path);
        $pending = $patch->pending($selection->isPrivate());

        $this->newLine();

        if ($pending['traits'] === [] && $pending['interfaces'] === []) {
            $this->components->task('User model already has what it needs');

            return;
        }

        $this->components->info('Your User model needs the tracker traits');
        $this->line('  Without these, /torrents fails with');
        $this->line('  <fg=red>Call to undefined method App\Models\User::isUploader()</>.');
        $this->newLine();
        $this->line($patch->diff($selection->isPrivate()));
        $this->newLine();

        if (! confirm(label: 'Apply this to app/Models/User.php?', default: true)) {
            $this->components->warn('Skipped. /torrents will fail until your User model '
                .'uses HasRoles and implements UserInterface.');

            return;
        }

        try {
            $patch->apply($selection->isPrivate());
            $this->components->task('app/Models/User.php updated (backup at User.php.marque-backup)');
        } catch (RuntimeException $e) {
            // apply() refuses to write anything that would not parse, so the
            // model is intact — but the operator must be told plainly, since
            // the fatal they came here to fix is still present.
            $this->components->error($e->getMessage());
            $this->components->warn('Your User model was left untouched. Add the traits by hand.');
        }
    }

    /**
     * The suite registers thirty-odd working routes and `/` is still Laravel's
     * welcome page. Everything works and nothing announces itself — which is
     * the finding that made Spec #115 worth writing.
     */
    private function chooseHomePage(): void
    {
        $routes = $this->laravel->basePath('routes/web.php');

        if (! is_file($routes)) {
            $this->newLine();
            $this->components->warn('No routes/web.php — skipping the home page.');

            return;
        }

        $home = new HomePage($routes, $this->laravel->resourcePath('views'));

        $this->newLine();

        if (! $home->pending(HomePage::SPLASH)) {
            $this->components->task('Home page already set');

            return;
        }

        $this->components->info('Your app still opens on Laravel\'s welcome page');

        $choice = select(
            label: 'What should / show?',
            options: HomePage::options(),
            default: HomePage::SPLASH,
        );

        if ($choice === HomePage::LEAVE_ALONE) {
            $this->components->warn('Left alone. / still shows whatever it showed before.');

            return;
        }

        try {
            $home->apply($choice);

            $this->components->task('routes/web.php updated (backup at web.php.marque-backup)');

            if ($choice === HomePage::SPLASH) {
                $this->line('  Published resources/views/home.blade.php — it is yours to edit,');
                $this->line('  and marque:install will never overwrite it.');
            }
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());
            $this->components->warn('routes/web.php was left untouched.');
        }
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

        if ($this->adminEmail !== null) {
            $this->line("  • Check {$this->adminEmail} for the link that sets your password.");
        } else {
            $this->line('  • No admin account was created. Re-run marque:install to add one.');
        }

        // usarrs gates its admin routes on the `verified` middleware, but
        // Laravel's stock User model leaves MustVerifyEmail commented out, so
        // that gate currently passes everybody. Enabling the interface later
        // locks out every existing user at once, since nothing backfills
        // email_verified_at.
        $this->line('  • Email verification is not enforced: App\Models\User does not implement');
        $this->line('    MustVerifyEmail, so the `verified` middleware on /admin passes everyone.');
        $this->line('    Enabling it later locks out existing users until they verify.');
    }

    /**
     * @param  list<string>  $only  Limit to these checks. The gate runs in two
     *                              passes either side of the interview, and the
     *                              second must not reprint the first's lines.
     */
    private function renderReport(EnvironmentReport $report, array $only = ['database', 'mail']): void
    {
        foreach ($only as $item) {
            if (! $report->checked($item)) {
                continue;
            }

            $this->components->twoColumnDetail(
                '  '.$item,
                $report->passed($item) ? '<fg=green>ok</>' : '<fg=red>needs attention</>',
            );
        }

        foreach ($report->messagesFor($only) as $message) {
            $this->newLine();

            $message['fatal']
                ? $this->components->error($message['text'])
                : $this->components->warn($message['text']);
        }
    }
}
