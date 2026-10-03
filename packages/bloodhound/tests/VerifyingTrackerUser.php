<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

use Illuminate\Contracts\Auth\MustVerifyEmail;

/**
 * A tracker user whose app verifies email addresses: the contract, which is
 * what the `verified` middleware and bloodhound both check (Laravel's base User
 * already carries the trait).
 */
class VerifyingTrackerUser extends TrackerUser implements MustVerifyEmail {}
