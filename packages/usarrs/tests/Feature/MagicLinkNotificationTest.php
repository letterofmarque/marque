<?php

declare(strict_types=1);

// Issue #10839: the magic-link mail named the site from `id.app_name`, a key
// gone since id became deck, and promised a 15-minute link. The link is a
// password-broker token, which lasts what that broker says (Laravel's default
// is 60).

use Marque\Usarrs\Notifications\MagicLinkNotification;
use Marque\Usarrs\Tests\TestUser;

function magicMail(): string
{
    $mail = (new MagicLinkNotification('https://example.test/magic'))->toMail(TestUser::factory()->make());

    return $mail->subject."\n".implode("\n", array_merge($mail->introLines, $mail->outroLines));
}

it('names the site from deck.app_name', function () {
    config()->set('deck.app_name', 'Ten Yard');
    config()->set('app.name', 'Laravel');

    expect(magicMail())->toContain('Login to Ten Yard');
});

it('falls back to app.name when deck sets none', function () {
    config()->set('deck.app_name', null);
    config()->set('app.name', 'Laravel');

    expect(magicMail())->toContain('Login to Laravel');
});

it('states how long the link really lasts', function (int $minutes) {
    config()->set('auth.defaults.passwords', 'users');
    config()->set('auth.passwords.users.expire', $minutes);

    expect(magicMail())->toContain("This link expires in {$minutes} minutes.")
        ->not->toContain('15 minutes');
})->with([60, 30]);

// The link is issued by app('auth.password.broker'), the default broker.
it('reads the expiry of the default broker, which issues the link', function () {
    config()->set('auth.passwords.users.expire', 60);
    config()->set('auth.passwords.members', ['provider' => 'users', 'table' => 'password_reset_tokens', 'expire' => 20]);
    config()->set('auth.defaults.passwords', 'members');

    expect(magicMail())->toContain('This link expires in 20 minutes.');
});
