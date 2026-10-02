<?php

declare(strict_types=1);

namespace Marque\Marque\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use Marque\Marque\Install\AdminSeeder;
use Marque\Marque\Install\ComposerRunner;
use Marque\Marque\Install\EnvironmentCheck;
use Marque\Marque\Install\EnvironmentReport;
use Marque\Marque\Install\EnvWriter;
use Marque\Marque\Install\FreshProcess;
use Marque\Marque\Install\HomePage;
use Marque\Marque\Install\InstallAnswers;
use Marque\Marque\Install\InstalledPackages;
use Marque\Marque\Install\MixedTrackerInstall;
use Marque\Marque\Install\PackageSelection;
use Marque\Marque\Install\StylesheetWiring;
use Marque\Marque\Install\UserModelPatch;
use RuntimeException;

/**
 * The front door.
 *
 * This command runs the environment gate, the interview and composer require.
 * Everything after — the Tailwind source wiring, the User model edit, the home
 * page, config publishing, migrations, the admin account and the
 * self-verification pass — is marque:install:finish, run in a fresh process so
 * the packages just installed are loaded (Job #131).
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

        // What is already installed decides the tracker on a re-run, and an app
        // holding both halves is refused before anyone is asked anything
        // (#10803).
        $installed = $this->laravel->make(InstalledPackages::class);

        try {
            $existing = PackageSelection::fromInstalled($installed);
        } catch (MixedTrackerInstall $e) {
            $this->newLine();
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $selection = $this->interview($existing);

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

        $toRequire = $selection->toRequire($installed);

        if ($toRequire === []) {
            $this->newLine();
            $this->components->info('Nothing new to install — everything chosen is already in place.');
        } elseif (! $this->installPackages($toRequire)) {
            return self::FAILURE;
        }

        // File edits, made here and asked about here. They only write files —
        // nothing they write is loaded by this process.
        $this->wireStylesheet($selection);
        $this->patchUserModel($selection);
        $this->chooseHomePage($selection);

        // The rest needs the installed packages' code and the patched User
        // model loaded, which this process can't have: it booted before both.
        // A fresh one boots with them (Job #131).
        $this->newLine();

        return $this->laravel->make(FreshProcess::class, ['basePath' => $this->laravel->basePath()])
            ->finish(
                new InstallAnswers($selection, $this->adminName, $this->adminEmail),
                function (string $chunk): void {
                    $this->output->write($chunk);
                },
            );
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
    private function interview(?PackageSelection $existing): PackageSelection
    {
        $this->components->info('Setting up Marque');

        // A re-run never re-asks the tracker question. Its default was
        // private, so pressing Enter on a public install used to add
        // bloodhound beside hound (#10803).
        if ($existing !== null) {
            $this->components->info(sprintf(
                'This is already a %s tracker — keeping it. Adding anything else stays on that tracker.',
                $existing->isPrivate() ? 'private' : 'public',
            ));
            $selection = $existing;
        } else {
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
        }

        // Only what isn't installed yet is offered.
        $extras = [
            'marque/cennad' => ['Add the REST API?', false, fn (PackageSelection $s) => $s->withApi()],
            'marque/parley' => ['Add forums and torrent comments?', false, fn (PackageSelection $s) => $s->withForums()],
            'marque/taxonomy' => ['Add the content taxonomy engine?', false, fn (PackageSelection $s) => $s->withTaxonomy()],
            'marque/skipper' => ['Add the admin panel?', true, fn (PackageSelection $s) => $s->withAdminPanel()],
        ];

        foreach ($extras as $package => [$label, $default, $add]) {
            if (! $selection->includes($package) && confirm(label: $label, default: $default)) {
                $selection = $add($selection);
            }
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

    /** @param list<string> $packages */
    private function installPackages(array $packages): bool
    {
        $this->newLine();
        $this->components->info('Installing '.implode(', ', $packages));

        $result = $this->laravel->make(ComposerRunner::class, ['workingDirectory' => $this->laravel->basePath()])
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

        if (in_array('Illuminate\Contracts\Auth\MustVerifyEmail', $pending['interfaces'], true)) {
            $this->line('  MustVerifyEmail makes the `verified` checks real: /admin, and usarrs\'');
            $this->line('  proof that an OAuth sign-in\'s owner holds the inbox.');

            $unverified = $this->unverifiedUsers();

            if ($unverified > 0) {
                $this->line("  <fg=yellow>{$unverified} existing ".($unverified === 1 ? 'user has' : 'users have')
                    .' not verified an address</> and will be asked to before');
                $this->line('  reaching a verified page. The verification mail can be resent from there.');
            }
        }

        $this->newLine();
        $this->line($patch->diff($selection->isPrivate()));
        $this->newLine();

        if (! confirm(label: 'Apply this to app/Models/User.php?', default: true)) {
            $this->components->warn('Skipped. /torrents will fail until your User model '
                .'uses HasRoles and implements UserInterface, and `verified` passes everyone '
                .'until it implements MustVerifyEmail.');

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
    private function chooseHomePage(PackageSelection $selection): void
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
            default: HomePage::defaultFor($selection),
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

    /**
     * How many existing accounts haven't verified an address — zero on a fresh
     * app, and zero when the table doesn't exist yet.
     *
     * Counted from the table, not through the model: this runs before the
     * User model file is patched, and loading the class here would leave the
     * unpatched version in memory for the admin seed and the self-checks.
     */
    private function unverifiedUsers(): int
    {
        try {
            return DB::table('users')->whereNull('email_verified_at')->count();
        } catch (\Throwable) {
            return 0;
        }
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
