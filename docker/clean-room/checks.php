<?php

declare(strict_types=1);

/**
 * What the clean room checks once Marque is installed: the things that only a
 * real app, installed the way an operator would, can show.
 *
 * Runs inside the installed app (tools/clean-room calls it through run.sh).
 * Requests go through the app's real HTTP kernel, as real users, so routes,
 * middleware, policies, the admin panel and the views are all the installed
 * ones. Exits non-zero if anything fails.
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Marque\Trove\Enums\Role;
use Marque\Usarrs\Services\UserAdminService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

require '/site/vendor/autoload.php';

$app = require '/site/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$preseed = Yaml::parseFile('/preseed.yml');
$users = config('auth.providers.users.model');
$failed = 0;

$check = function (string $name, callable $test) use (&$failed): void {
    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e).': '.$e->getMessage();
    }

    if ($result === true) {
        printf("  \033[32m✓\033[0m %s\n", $name);

        return;
    }

    $failed++;
    printf("  \033[31m✗\033[0m %s\n      %s\n", $name, is_string($result) ? $result : 'failed');
};

/**
 * A request through the installed app's kernel, as $user (or a guest).
 */
$get = function (string $path, $user = null) use ($app): Response {
    $user === null ? Auth::guard('web')->logout() : Auth::guard('web')->setUser($user);

    $kernel = $app->make(HttpKernel::class);
    $response = $kernel->handle(Request::create($path));
    $kernel->terminate(Request::create($path), $response);

    return $response;
};

$sees = fn ($response, string $text): bool|string => $response->getStatusCode() !== 200
    ? "HTTP {$response->getStatusCode()}"
    : (str_contains((string) $response->getContent(), $text) ? true : "the page doesn't contain \"{$text}\"");

$verified = function (string $email, string $role) use ($users) {
    $user = $users::query()->firstOrCreate(['email' => $email], ['name' => ucfirst($role).' '.Str::random(4), 'password' => bcrypt(Str::random(32))]);
    $user->forceFill(['role' => $role, 'email_verified_at' => now()])->save();

    return $user->fresh();
};

$ownerEmail = $preseed['admin']['email'] ?? null;
$owner = $ownerEmail === null ? null : $users::query()->where('email', $ownerEmail)->first();

echo "\n\033[1mThe installed tracker\033[0m\n";

$check('the preseeded admin account exists and is the owner', fn () => $owner === null
    ? "no account for {$ownerEmail}"
    : ($owner->role === Role::Owner ? true : "its role is {$owner->role?->value}"));

$check('the owner was sent a link to set their password', function () use ($ownerEmail) {
    $messages = Http::get('http://mail:8025/api/v1/search', ['query' => "to:{$ownerEmail}"])->json('messages', []);

    return $messages !== [] ? true : "no mail to {$ownerEmail} in Mailpit";
});

// The owner hasn't followed their link yet, so isn't verified, and every
// admin route is behind `verified`. Verified here as if they had.
$owner?->forceFill(['email_verified_at' => now()])->save();
$owner = $owner?->fresh();

$check('usarrs:owner makes another user an owner', function () use ($verified) {
    $second = $verified('second-owner@clean-room.test', 'admin');
    $code = Artisan::call('usarrs:owner', ['email' => $second->email]);

    return $code === 0 && $second->fresh()->role === Role::Owner ? true : 'exit '.$code.', role '.$second->fresh()->role->value;
});

echo "\n\033[1mThe admin panel and bosun's screens\033[0m\n";

$check('the panel lists the Staff and Audit log screens', fn () => $sees($get('/admin', $owner), 'Audit log'));
$check('the Staff screen renders for the owner, with the owner on it', fn () => $sees($get('/admin/staff', $owner), $owner->name));
$check('the Audit log renders for the owner', fn () => $sees($get('/admin/audit-log', $owner), 'Audit log'));

$check('a moderator is refused both', function () use ($get, $verified) {
    $moderator = $verified('moderator@clean-room.test', 'moderator');
    $codes = [$get('/admin/staff', $moderator)->getStatusCode(), $get('/admin/audit-log', $moderator)->getStatusCode()];

    return $codes === [403, 403] ? true : 'HTTP '.implode(' and ', $codes);
});

$check('the Staff screen links each member to their actions in the Audit log', function () use ($get, $owner) {
    $html = (string) $get('/admin/staff', $owner)->getContent();

    return str_contains($html, 'audit-log?actor='.$owner->getKey()) ? true : 'no link to audit-log?actor='.$owner->getKey();
});

$check('a ban is recorded and shown in the Audit log, with its reason', function () use ($get, $sees, $owner, $verified) {
    $member = $verified('member@clean-room.test', 'user');
    app(UserAdminService::class)->ban($member, actor: $owner, reason: 'Clean room ban');

    if ($member->fresh()->status !== 'banned') {
        return 'the member was not banned (status '.$member->fresh()->status.')';
    }

    $response = $get('/admin/audit-log', $owner);

    return ($seen = $sees($response, 'usarrs.user.banned')) !== true ? $seen : $sees($response, 'Clean room ban');
});

echo "\n\033[1mThe tracker itself\033[0m\n";

$check('the torrent listing renders for a member', fn () => $get('/torrents', $verified('reader@clean-room.test', 'user'))->getStatusCode() === 200 ? true : 'HTTP '.$get('/torrents', $verified('reader@clean-room.test', 'user'))->getStatusCode());
$check('the home page answers', fn () => in_array($code = $get('/')->getStatusCode(), [200, 302], true) ? true : "HTTP {$code}");

printf("\n%s\n", $failed === 0 ? "\033[32mEvery check passed.\033[0m" : "\033[31m{$failed} check(s) failed.\033[0m");

exit($failed === 0 ? 0 : 1);
