<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

use Marque\Bloodhound\Concerns\HasTrackerStats;

/**
 * A consumer User model with an ordinary, explicit $fillable — the common
 * Laravel shape — plus HasTrackerStats. The case where the trait used to widen
 * what a request could write.
 */
class FillableTrackerUser extends TestUser
{
    use HasTrackerStats;

    protected $fillable = ['name', 'email', 'password'];

    protected $guarded = ['*'];
}
