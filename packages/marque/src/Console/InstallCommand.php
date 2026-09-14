<?php

declare(strict_types=1);

namespace Marque\Marque\Console;

use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use Marque\Marque\Install\ComposerRunner;
use Marque\Marque\Install\EnvironmentCheck;
use Marque\Marque\Install\EnvironmentReport;
use Marque\Marque\Install\EnvWriter;
use Marque\Marque\Install\PackageSelection;
use Marque\Marque\Install\StylesheetWiring;
use RuntimeException;

/**
 * The front door.
 *
 * Stages land one Checkpoint at a time (Build #105). Live now: the environment
 * gate, the interview, composer require, and the Tailwind source wiring. Still
 * to come: the User model edit, the home page, migrations and a
 * self-verification pass. Until those arrive the command says plainly that it
 * has not finished rather than reporting a success it has not performed —
 * which is the precise failure this Build exists to correct.
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

        $this->newLine();
        $this->components->warn('The rest of marque:install is not finished yet.');
        $this->line('  Your packages are installed and your stylesheet knows where their');
        $this->line('  templates are. The User model, the home page and migrations land');
        $this->line('  one stage at a time and are not done, so this command will not');
        $this->line('  claim your tracker is ready.');

        return self::FAILURE;
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

        return $selection;
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
