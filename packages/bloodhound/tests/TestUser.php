<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Marque\Trove\Concerns\HasRoles;
use Marque\Trove\Contracts\UserInterface;

/**
 * Test user model for bloodhound tests.
 */
class TestUser extends Authenticatable implements UserInterface
{
    use HasRoles;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    protected $attributes = [
        'role' => 'user',
    ];

    public function generatePasskey(): string
    {
        return Str::random(32);
    }
}
