<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Marque\Trove\Contracts\UserInterface;

/**
 * Test user model for bloodhound tests.
 */
class TestUser extends Model implements UserInterface
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    public function generatePasskey(): string
    {
        return Str::random(32);
    }
}
