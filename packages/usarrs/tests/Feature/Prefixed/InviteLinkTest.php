<?php

declare(strict_types=1);

// Job #141 review: the invite email built url('/register?invite=…'), ignoring
// usarrs.prefix, so on a prefixed install the link landed on a 404.

use Marque\Usarrs\Contracts\InviteServiceInterface;
use Marque\Usarrs\Notifications\InviteNotification;
use Marque\Usarrs\Tests\TestUser;

it('links the invite to the registration page wherever usarrs is mounted', function () {
    $invite = app(InviteServiceInterface::class)->create(TestUser::factory()->create());

    $mail = (new InviteNotification($invite))->toMail(new stdClass);

    expect($mail->actionUrl)->toBe(url('/members/register?invite='.$invite->code));
});
