<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Marque\Marque\Install\InvalidPreseed;
use Marque\Marque\Install\Preseed;
use Marque\Marque\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A preseed answers marque:install's questions ahead of time, and is refused
 * whole, by name, at the first sign it says something the installer can't
 * act on: a misspelt key silently ignored is a default nobody chose.
 */
final class PreseedTest extends TestCase
{
    /** @return array<string, mixed> */
    private function complete(): array
    {
        return [
            'tracker' => 'private',
            'extras' => ['api' => false, 'forums' => true, 'taxonomy' => false, 'admin_panel' => true, 'staff_management' => true],
            'site_name' => 'Ten Yard',
            'admin' => ['name' => 'Ada', 'email' => 'ada@example.com'],
            'stylesheet' => true,
            'user_model' => true,
            'home_page' => 'dashboard',
        ];
    }

    public function test_a_complete_preseed_answers_every_question(): void
    {
        $preseed = Preseed::fromArray($this->complete());

        $this->assertSame([], $preseed->missing());
        $this->assertSame('private', $preseed->answer('tracker'));
        $this->assertTrue($preseed->answer('extras.staff_management'));
        $this->assertFalse($preseed->answer('extras.api'));
        $this->assertSame('dashboard', $preseed->answer('home_page'));
    }

    public function test_a_partial_preseed_names_what_it_leaves_unanswered(): void
    {
        $preseed = Preseed::fromArray(['tracker' => 'public', 'extras' => ['api' => true]]);

        $this->assertTrue($preseed->answers('extras.api'));
        $this->assertFalse($preseed->answers('extras.forums'));
        $this->assertSame(
            ['site_name', 'admin', 'stylesheet', 'user_model', 'home_page', 'extras.forums', 'extras.taxonomy', 'extras.admin_panel', 'extras.staff_management'],
            $preseed->missing(),
        );
    }

    public function test_no_admin_is_an_answer(): void
    {
        $this->assertFalse(Preseed::fromArray(['admin' => false])->answer('admin'));
        $this->assertTrue(Preseed::fromArray(['admin' => false])->answers('admin'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('refusals')]
    public function test_it_refuses_what_the_installer_cant_act_on(array $data, string $names): void
    {
        try {
            Preseed::fromArray($data);
            $this->fail('The preseed was accepted.');
        } catch (InvalidPreseed $e) {
            $this->assertStringContainsString($names, $e->getMessage());
        }
    }

    /** @return array<string, array{array<string, mixed>|list<mixed>, string}> */
    public static function refusals(): array
    {
        return [
            'a misspelt question' => [['trakcer' => 'private'], '"trakcer" isn\'t an installer question'],
            'an unknown tracker' => [['tracker' => 'semi-private'], 'tracker is private or public'],
            'an unknown extra' => [['extras' => ['wiki' => true]], 'extras.wiki isn\'t an extra'],
            'an extra that isn\'t yes or no' => [['extras' => ['api' => 'yes']], 'extras.api is true or false'],
            'a list where answers belong' => [['private', 'dashboard'], 'a set of answers by name'],
            'an admin without an email' => [['admin' => ['name' => 'Ada']], 'admin is false, or a name and an email'],
            'an admin with a stray key' => [['admin' => ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']], 'admin is false, or a name and an email'],
            'an admin with a bad address' => [['admin' => ['name' => 'Ada', 'email' => 'not-an-address']], 'does not look like an email address'],
            'a stylesheet answer that isn\'t yes or no' => [['stylesheet' => 'please'], 'stylesheet is true or false'],
            'an unknown home page' => [['home_page' => 'forum'], 'home_page is one of'],
            'a site name that isn\'t text' => [['site_name' => ['x']], 'site_name is text'],
        ];
    }

    public function test_it_reports_every_problem_at_once(): void
    {
        try {
            Preseed::fromArray(['trakcer' => 'private', 'home_page' => 'forum']);
            $this->fail('The preseed was accepted.');
        } catch (InvalidPreseed $e) {
            $this->assertStringContainsString('trakcer', $e->getMessage());
            $this->assertStringContainsString('home_page', $e->getMessage());
        }
    }

    public function test_it_reads_yaml_with_comments(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'preseed');
        file_put_contents($path, "# Ten Yard's production install\ntracker: private  # members only\nextras:\n  staff_management: true\n");

        $preseed = Preseed::fromFile($path);
        unlink($path);

        $this->assertSame('private', $preseed->answer('tracker'));
        $this->assertTrue($preseed->answer('extras.staff_management'));
    }

    public function test_it_refuses_a_missing_or_malformed_file(): void
    {
        $this->expectException(InvalidPreseed::class);
        Preseed::fromFile('/nowhere/marque.yml');
    }

    public function test_it_refuses_a_file_that_isnt_yaml(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'preseed');
        file_put_contents($path, "tracker: [unclosed\n");

        try {
            $this->expectException(InvalidPreseed::class);
            $this->expectExceptionMessage("isn't valid YAML");
            Preseed::fromFile($path);
        } finally {
            unlink($path);
        }
    }

    public function test_the_readme_example_is_a_complete_valid_preseed(): void
    {
        // The documented example is what operators copy. If it drifts from
        // what the installer accepts, they find out at install time.
        preg_match('/```yaml\n(.*?)```/s', (string) file_get_contents(__DIR__.'/../../README.md'), $match);

        $this->assertNotEmpty($match, 'the README has a yaml example');

        $path = tempnam(sys_get_temp_dir(), 'preseed');
        file_put_contents($path, $match[1]);

        try {
            $this->assertSame([], Preseed::fromFile($path)->missing());
        } finally {
            unlink($path);
        }
    }
}
