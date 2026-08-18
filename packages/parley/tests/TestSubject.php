<?php

declare(strict_types=1);

namespace Marque\Parley\Tests;

use Illuminate\Database\Eloquent\Model;

/**
 * Something a discussion attaches to.
 *
 * Deliberately NOT a Torrent. Parley's whole premise is that discussion is
 * polymorphic — it attaches to any model, and torrent comments are just the
 * first caller. Testing against a generic subject is what keeps that true;
 * testing against Torrent would let trove-shaped assumptions creep in
 * unnoticed.
 */
class TestSubject extends Model
{
    protected $table = 'test_subjects';

    protected $guarded = [];
}
