<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Marque\Marque\Install\EnvWriter;
use Marque\Marque\Tests\TestCase;

/**
 * .env is the operator's file, not ours.
 *
 * It routinely carries comments, blank-line grouping and hand-written ordering,
 * and none of that may be disturbed to set one key. Rewriting the file
 * wholesale would be the hostile behaviour Spec #115 rules out for app code
 * generally.
 */
final class EnvWriterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/marque-env-'.uniqid().'.env';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
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

    public function test_it_replaces_an_existing_key_in_place(): void
    {
        $this->write("APP_NAME=Laravel\nAPP_ENV=local\n");

        (new EnvWriter($this->path))->set('APP_NAME', 'My Tracker');

        $this->assertStringContainsString('APP_NAME="My Tracker"', $this->read());
        $this->assertStringNotContainsString('APP_NAME=Laravel', $this->read());
    }

    public function test_it_leaves_every_other_line_untouched(): void
    {
        $original = "# Application\nAPP_NAME=Laravel\nAPP_ENV=local\n\n# Database\nDB_CONNECTION=sqlite\n";
        $this->write($original);

        (new EnvWriter($this->path))->set('APP_NAME', 'Tracker');

        $after = $this->read();

        $this->assertStringContainsString('# Application', $after);
        $this->assertStringContainsString('# Database', $after);
        $this->assertStringContainsString('APP_ENV=local', $after);
        $this->assertStringContainsString('DB_CONNECTION=sqlite', $after);
        // The blank line between the groups survives.
        $this->assertStringContainsString("\n\n", $after);
    }

    public function test_it_appends_a_key_that_is_not_there(): void
    {
        $this->write("APP_ENV=local\n");

        (new EnvWriter($this->path))->set('APP_NAME', 'Tracker');

        $this->assertStringContainsString('APP_NAME=Tracker', $this->read());
        $this->assertStringContainsString('APP_ENV=local', $this->read());
    }

    public function test_it_quotes_values_containing_spaces(): void
    {
        $this->write("APP_NAME=Laravel\n");

        (new EnvWriter($this->path))->set('APP_NAME', 'Ten Yard Tracker');

        // Unquoted, everything after the first space is discarded by dotenv.
        $this->assertStringContainsString('APP_NAME="Ten Yard Tracker"', $this->read());
    }

    public function test_it_does_not_quote_a_simple_value(): void
    {
        $this->write("APP_NAME=Laravel\n");

        (new EnvWriter($this->path))->set('APP_NAME', 'Tracker');

        $this->assertStringContainsString("APP_NAME=Tracker\n", $this->read());
    }

    public function test_it_escapes_a_value_containing_quotes(): void
    {
        $this->write("APP_NAME=Laravel\n");

        (new EnvWriter($this->path))->set('APP_NAME', 'Dan\'s "Tracker"');

        $line = $this->read();

        // The written line must survive a round trip rather than terminating
        // the quoted string early.
        $this->assertStringContainsString('\\"Tracker\\"', $line);
    }

    public function test_it_does_not_match_a_key_that_merely_shares_a_prefix(): void
    {
        $this->write("APP_NAME=Laravel\nAPP_NAME_SUFFIX=keepme\n");

        (new EnvWriter($this->path))->set('APP_NAME', 'Tracker');

        $this->assertStringContainsString('APP_NAME_SUFFIX=keepme', $this->read());
    }

    public function test_it_ignores_a_commented_out_key(): void
    {
        $this->write("# APP_NAME=Commented\nAPP_NAME=Laravel\n");

        (new EnvWriter($this->path))->set('APP_NAME', 'Tracker');

        $after = $this->read();

        $this->assertStringContainsString('# APP_NAME=Commented', $after);
        $this->assertStringContainsString('APP_NAME=Tracker', $after);
    }
}
