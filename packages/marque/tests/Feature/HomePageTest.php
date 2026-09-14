<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Marque\Marque\Install\HomePage;
use Marque\Marque\Tests\TestCase;

/**
 * The finding that made Spec #115 worth writing.
 *
 * The suite registers thirty-odd working routes — announce, login, profile,
 * invites, admin, the API — and `/` is still `routes/web.php:5`, Laravel's
 * welcome page. Everything works and nothing announces itself, so a stranger
 * who follows the README sees an app that looks completely unchanged.
 */
final class HomePageTest extends TestCase
{
    private string $routes;

    private string $views;

    protected function setUp(): void
    {
        parent::setUp();

        $id = uniqid();
        $this->routes = sys_get_temp_dir().'/marque-routes-'.$id.'.php';
        $this->views = sys_get_temp_dir().'/marque-views-'.$id;

        mkdir($this->views, 0o777, true);
        file_put_contents($this->routes, $this->stockWebRoutes());
    }

    protected function tearDown(): void
    {
        foreach ([$this->routes, $this->routes.'.marque-backup'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach (glob($this->views.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->views)) {
            rmdir($this->views);
        }

        parent::tearDown();
    }

    private function stockWebRoutes(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
PHP;
    }

    private function routes(): string
    {
        return (string) file_get_contents($this->routes);
    }

    private function home(): HomePage
    {
        return new HomePage($this->routes, $this->views);
    }

    public function test_leaving_it_alone_changes_nothing(): void
    {
        $before = $this->routes();

        $this->home()->apply(HomePage::LEAVE_ALONE);

        $this->assertSame($before, $this->routes(), 'an app that already owns / must be left alone');
        $this->assertSame([], glob($this->views.'/*') ?: []);
    }

    public function test_the_torrent_index_option_redirects_to_the_listing(): void
    {
        $this->home()->apply(HomePage::TORRENT_INDEX);

        $after = $this->routes();

        // torrents.index is guise's, and it exists — verified against a real
        // route:list rather than assumed.
        $this->assertStringContainsString('torrents.index', $after);
        $this->assertStringNotContainsString("view('welcome')", $after);
    }

    public function test_the_profile_stats_option_redirects_to_the_tracker_stats(): void
    {
        $this->home()->apply(HomePage::PROFILE_STATS);

        // stats, not show: show is name/email/role/bio, stats is ratio,
        // uploaded, downloaded and the announce key — the numbers a tracker
        // user actually opens the site for.
        $this->assertStringContainsString('profile.stats', $this->routes());
        $this->assertStringNotContainsString('profile.show', $this->routes());
    }

    public function test_the_splash_option_publishes_a_view(): void
    {
        $this->home()->apply(HomePage::SPLASH);

        $this->assertFileExists($this->views.'/home.blade.php');
    }

    public function test_the_published_splash_is_the_operators_to_edit(): void
    {
        $this->home()->apply(HomePage::SPLASH);

        $splash = (string) file_get_contents($this->views.'/home.blade.php');

        // Published rather than served from the package: every tracker
        // replaces this immediately, and serving it would make the common case
        // require an override.
        $this->assertStringContainsString('config(', $splash, 'the site name comes from APP_NAME');
        $this->assertStringContainsString('route(', $splash, 'the Enter link goes somewhere real');
    }

    public function test_the_splash_route_renders_the_published_view(): void
    {
        $this->home()->apply(HomePage::SPLASH);

        $this->assertStringContainsString("view('home')", $this->routes());
        $this->assertStringNotContainsString("view('welcome')", $this->routes());
    }

    public function test_it_replaces_the_welcome_route_rather_than_adding_a_second(): void
    {
        $this->home()->apply(HomePage::SPLASH);

        // Two routes for '/' means the first wins silently and the operator
        // cannot tell which one they are looking at.
        $this->assertSame(1, substr_count($this->routes(), "Route::get('/'"));
    }

    public function test_the_result_is_still_valid_php(): void
    {
        $this->home()->apply(HomePage::SPLASH);

        $output = [];
        $status = 0;
        exec('php -l '.escapeshellarg($this->routes).' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
    }

    public function test_it_backs_up_the_routes_file(): void
    {
        $original = $this->routes();

        $this->home()->apply(HomePage::SPLASH);

        $this->assertFileExists($this->routes.'.marque-backup');
        $this->assertSame($original, file_get_contents($this->routes.'.marque-backup'));
    }

    public function test_it_is_idempotent(): void
    {
        $home = $this->home();
        $home->apply(HomePage::SPLASH);
        $after = $this->routes();

        $home->apply(HomePage::SPLASH);

        $this->assertSame($after, $this->routes(), 'a second run must change nothing');
        $this->assertSame(1, substr_count($this->routes(), "Route::get('/'"));
    }

    public function test_it_does_not_overwrite_a_splash_the_operator_has_edited(): void
    {
        $this->home()->apply(HomePage::SPLASH);

        file_put_contents($this->views.'/home.blade.php', '<h1>My own splash</h1>');

        $this->home()->apply(HomePage::SPLASH);

        $this->assertSame(
            '<h1>My own splash</h1>',
            file_get_contents($this->views.'/home.blade.php'),
            'the published view belongs to the operator once it exists',
        );
    }

    public function test_it_reports_whether_anything_is_pending(): void
    {
        $this->assertTrue($this->home()->pending(HomePage::SPLASH));

        $this->home()->apply(HomePage::SPLASH);

        $this->assertFalse($this->home()->pending(HomePage::SPLASH));
    }

    public function test_leave_alone_is_never_pending(): void
    {
        $this->assertFalse($this->home()->pending(HomePage::LEAVE_ALONE));
    }

    public function test_it_leaves_other_routes_untouched(): void
    {
        file_put_contents(
            $this->routes,
            $this->stockWebRoutes()."\n\nRoute::get('/about', fn () => view('about'));\n",
        );

        $this->home()->apply(HomePage::SPLASH);

        $this->assertStringContainsString("Route::get('/about'", $this->routes());
    }
}
