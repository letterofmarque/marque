<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Answers to marque:install's questions, written down ahead of time.
 *
 * Debian's preseeding is the model: a file answers the installer, so an
 * install can be repeated exactly, run unattended in CI, or kept beside a
 * deployment as the record of how it was set up. A question the file answers
 * isn't asked; one it doesn't is asked as normal.
 *
 * **Strict on purpose.** A misspelt key that's silently ignored becomes a
 * default nobody chose, which is the classic preseed failure. So an unknown
 * key, a wrong type or an impossible value refuses the whole file, by name,
 * before the installer changes anything.
 */
final class Preseed
{
    /** Each extra, by the name a preseed uses for it. */
    public const EXTRAS = [
        'api' => 'marque/cennad',
        'forums' => 'marque/parley',
        'taxonomy' => 'marque/taxonomy',
        'admin_panel' => 'marque/skipper',
        'staff_management' => 'marque/bosun',
    ];

    private const KEYS = ['tracker', 'extras', 'site_name', 'admin', 'stylesheet', 'user_model', 'home_page'];

    /**
     * @param  array<string, mixed>  $answers
     */
    private function __construct(
        private readonly array $answers,
        public readonly ?string $path,
    ) {}

    /**
     * No file: every question is asked.
     */
    public static function none(): self
    {
        return new self([], null);
    }

    /**
     * @throws InvalidPreseed
     */
    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidPreseed("The preseed file [{$path}] doesn't exist or can't be read.");
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new InvalidPreseed("The preseed file [{$path}] isn't valid YAML: {$e->getMessage()}");
        }

        return self::fromArray($data ?? [], $path);
    }

    /**
     * @throws InvalidPreseed
     */
    public static function fromArray(mixed $data, ?string $path = null): self
    {
        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InvalidPreseed('A preseed is a set of answers by name, e.g. "tracker: private".');
        }

        $problems = [];

        foreach (array_keys($data) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                $problems[] = "\"{$key}\" isn't an installer question. The questions are: ".implode(', ', self::KEYS).'.';
            }
        }

        if (array_key_exists('tracker', $data) && ! in_array($data['tracker'], ['private', 'public'], true)) {
            $problems[] = 'tracker is private or public.';
        }

        if (array_key_exists('extras', $data)) {
            if (! is_array($data['extras']) || ($data['extras'] !== [] && array_is_list($data['extras']))) {
                $problems[] = 'extras is a set of yes/no answers, e.g. "api: false".';
            } else {
                foreach ($data['extras'] as $extra => $answer) {
                    if (! array_key_exists($extra, self::EXTRAS)) {
                        $problems[] = "extras.{$extra} isn't an extra. The extras are: ".implode(', ', array_keys(self::EXTRAS)).'.';
                    } elseif (! is_bool($answer)) {
                        $problems[] = "extras.{$extra} is true or false.";
                    }
                }
            }
        }

        if (array_key_exists('site_name', $data) && ! is_string($data['site_name'])) {
            $problems[] = 'site_name is text; leave it empty to keep the current name.';
        }

        if (array_key_exists('admin', $data)) {
            $admin = $data['admin'];

            if ($admin === false) {
                // No admin account: a deliberate answer.
            } elseif (! is_array($admin) || array_diff(array_keys($admin), ['name', 'email']) !== [] || ! is_string($admin['name'] ?? null) || ! is_string($admin['email'] ?? null)) {
                $problems[] = 'admin is false, or a name and an email: "admin: {name: Ada, email: ada@example.com}".';
            } elseif (($error = (new AdminSeeder)->validate($admin['name'], $admin['email'])) !== null) {
                $problems[] = "admin: {$error}";
            }
        }

        foreach (['stylesheet', 'user_model'] as $flag) {
            if (array_key_exists($flag, $data) && ! is_bool($data[$flag])) {
                $problems[] = "{$flag} is true or false.";
            }
        }

        if (array_key_exists('home_page', $data) && ! array_key_exists((string) $data['home_page'], HomePage::options())) {
            $problems[] = 'home_page is one of: '.implode(', ', array_keys(HomePage::options())).'.';
        }

        if ($problems !== []) {
            throw new InvalidPreseed(($path === null ? 'The preseed' : "The preseed file [{$path}]")." can't be used:\n  - ".implode("\n  - ", $problems));
        }

        return new self($data, $path);
    }

    public function loaded(): bool
    {
        return $this->path !== null || $this->answers !== [];
    }

    /**
     * Whether the file answers this question. Extras are "extras.<name>".
     */
    public function answers(string $question): bool
    {
        if (str_starts_with($question, 'extras.')) {
            return array_key_exists(substr($question, 7), $this->answers['extras'] ?? []);
        }

        return array_key_exists($question, $this->answers);
    }

    public function answer(string $question): mixed
    {
        if (str_starts_with($question, 'extras.')) {
            return $this->answers['extras'][substr($question, 7)] ?? null;
        }

        return $this->answers[$question] ?? null;
    }

    /**
     * The questions this file leaves unanswered. Unattended (--no-interaction)
     * an install needs them all, and is refused up front rather than part way
     * through.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $questions = [
            ...array_diff(self::KEYS, ['extras']),
            ...array_map(fn (string $extra): string => "extras.{$extra}", array_keys(self::EXTRAS)),
        ];

        return array_values(array_filter($questions, fn (string $q): bool => ! $this->answers($q)));
    }
}
