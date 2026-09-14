<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Marque\Marque\Install\UserModelPatch;
use Marque\Marque\Tests\TestCase;

/**
 * The headline fatal from job #10699.
 *
 * A stock Laravel User model has no Marque traits, so /torrents dies with
 * `Call to undefined method App\Models\User::isUploader()` — the product's main
 * page, on a by-the-book install. Documenting the fix has already been tried
 * and did not work: trove's README carries it, and has named the WRONG import
 * for HasTrackerStats since it moved to bloodhound in v2.
 *
 * This is the most invasive thing the installer does. It edits a file the app
 * owns, so every guarantee here is load-bearing: show the diff, back it up,
 * never touch it without a yes, and never write the same thing twice.
 */
final class UserModelPatchTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/marque-user-'.uniqid().'.php';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.marque-backup'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    private function write(string $contents): void
    {
        file_put_contents($this->path, $contents);
    }

    private function read(): string
    {
        return (string) file_get_contents($this->path);
    }

    /** Byte-for-byte the shape Laravel 13 ships. */
    private function stockUserModel(): string
    {
        return <<<'PHP'
<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $hidden = [
        'password',
        'remember_token',
    ];
}
PHP;
    }

    private function patcher(): UserModelPatch
    {
        return new UserModelPatch($this->path);
    }

    public function test_a_stock_model_needs_the_role_trait(): void
    {
        $this->write($this->stockUserModel());

        $pending = $this->patcher()->pending(private: true);

        $this->assertContains('Marque\Trove\Concerns\HasRoles', $pending['traits']);
    }

    public function test_a_stock_model_needs_the_user_interface(): void
    {
        $this->write($this->stockUserModel());

        $pending = $this->patcher()->pending(private: true);

        // /torrents calls isUploader(), which the contract declares and
        // HasRoles supplies. Without the interface, type hints against
        // UserInterface fail even once the trait is present.
        $this->assertContains('Marque\Trove\Contracts\UserInterface', $pending['interfaces']);
    }

    public function test_a_private_tracker_needs_tracker_stats_from_bloodhound(): void
    {
        $this->write($this->stockUserModel());

        $pending = $this->patcher()->pending(private: true);

        // NOT Marque\Trove\Concerns — it moved to bloodhound in v2 and trove's
        // README still names the old path. Copying that README fatals on a
        // trait that does not exist.
        $this->assertContains('Marque\Bloodhound\Concerns\HasTrackerStats', $pending['traits']);
        $this->assertNotContains('Marque\Trove\Concerns\HasTrackerStats', $pending['traits']);
    }

    public function test_a_public_tracker_does_not_get_tracker_stats(): void
    {
        $this->write($this->stockUserModel());

        $pending = $this->patcher()->pending(private: false);

        // HasTrackerStats lives in bloodhound, which a public tracker does not
        // install. Adding it would fatal on a missing class.
        $this->assertNotContains('Marque\Bloodhound\Concerns\HasTrackerStats', $pending['traits']);
        $this->assertContains('Marque\Trove\Concerns\HasRoles', $pending['traits']);
    }

    public function test_pending_does_not_touch_the_file(): void
    {
        $this->write($this->stockUserModel());
        $before = $this->read();

        $this->patcher()->pending(private: true);

        $this->assertSame($before, $this->read());
    }

    public function test_applying_adds_the_traits_to_the_use_statement(): void
    {
        $this->write($this->stockUserModel());

        $this->patcher()->apply(private: true);

        $after = $this->read();

        $this->assertMatchesRegularExpression(
            '/use HasFactory,\s*Notifiable,\s*HasRoles,\s*HasTrackerStats;/',
            $after,
        );
    }

    public function test_applying_adds_the_imports(): void
    {
        $this->write($this->stockUserModel());

        $this->patcher()->apply(private: true);

        $after = $this->read();

        $this->assertStringContainsString('use Marque\Trove\Concerns\HasRoles;', $after);
        $this->assertStringContainsString('use Marque\Bloodhound\Concerns\HasTrackerStats;', $after);
        $this->assertStringContainsString('use Marque\Trove\Contracts\UserInterface;', $after);
    }

    public function test_applying_adds_the_interface_to_the_class_declaration(): void
    {
        $this->write($this->stockUserModel());

        $this->patcher()->apply(private: true);

        $this->assertStringContainsString(
            'class User extends Authenticatable implements UserInterface',
            $this->read(),
        );
    }

    public function test_it_appends_to_an_existing_implements_clause(): void
    {
        $this->write(str_replace(
            'class User extends Authenticatable',
            'class User extends Authenticatable implements MustVerifyEmail',
            $this->stockUserModel(),
        ));

        $this->patcher()->apply(private: true);

        $after = $this->read();

        $this->assertStringContainsString('implements MustVerifyEmail, UserInterface', $after);
    }

    public function test_the_result_is_still_valid_php(): void
    {
        $this->write($this->stockUserModel());

        $this->patcher()->apply(private: true);

        // The single most important property of editing someone's app code:
        // a syntax error here takes the whole application down.
        $output = [];
        $status = 0;
        exec('php -l '.escapeshellarg($this->path).' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
    }

    public function test_it_backs_up_before_writing(): void
    {
        $this->write($this->stockUserModel());
        $original = $this->read();

        $this->patcher()->apply(private: true);

        $this->assertFileExists($this->path.'.marque-backup');
        $this->assertSame($original, file_get_contents($this->path.'.marque-backup'));
    }

    public function test_it_is_idempotent(): void
    {
        $this->write($this->stockUserModel());

        $patch = $this->patcher();
        $patch->apply(private: true);
        $after = $this->read();

        $patch->apply(private: true);

        $this->assertSame($after, $this->read(), 'a second run must change nothing');
    }

    public function test_an_already_patched_model_has_nothing_pending(): void
    {
        $this->write($this->stockUserModel());

        $patch = $this->patcher();
        $patch->apply(private: true);

        $pending = $patch->pending(private: true);

        $this->assertSame([], $pending['traits']);
        $this->assertSame([], $pending['interfaces']);
    }

    public function test_it_offers_only_what_is_missing(): void
    {
        // A model someone half-wired by hand, following the README.
        $this->write(str_replace(
            'use HasFactory, Notifiable;',
            'use HasFactory, Notifiable, HasRoles;',
            str_replace(
                'use Illuminate\Notifications\Notifiable;',
                "use Illuminate\Notifications\Notifiable;\nuse Marque\Trove\Concerns\HasRoles;",
                $this->stockUserModel(),
            ),
        ));

        $pending = $this->patcher()->pending(private: true);

        $this->assertNotContains('Marque\Trove\Concerns\HasRoles', $pending['traits']);
        $this->assertContains('Marque\Bloodhound\Concerns\HasTrackerStats', $pending['traits']);
    }

    public function test_it_produces_a_readable_diff(): void
    {
        $this->write($this->stockUserModel());

        $diff = $this->patcher()->diff(private: true);

        // The operator is being asked to approve a change to their own app
        // code. A list of class names is not enough to judge that.
        $this->assertStringContainsString('+', $diff);
        $this->assertStringContainsString('HasRoles', $diff);
        $this->assertStringContainsString('UserInterface', $diff);
    }

    public function test_the_diff_shows_only_what_changes(): void
    {
        $this->write($this->stockUserModel());

        $diff = $this->patcher()->diff(private: true);

        $added = substr_count($diff, '+');

        // Three imports, the class line and the trait line. Naive line-by-line
        // comparison drifts after an insertion and marks every following line
        // as new, which asks the operator to approve a wall of green for a
        // five-line change.
        $this->assertLessThanOrEqual(
            8,
            $added,
            "the diff should show the change, not the rest of the file:\n".$diff,
        );
    }

    public function test_the_diff_does_not_mark_untouched_lines_as_added(): void
    {
        $this->write($this->stockUserModel());

        $diff = $this->patcher()->diff(private: true);

        $this->assertStringNotContainsString('remember_token', $diff);
    }
}
