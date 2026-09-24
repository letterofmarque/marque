<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's announce key — the credential every .torrent they download carries.
 *
 * Owned outright by bloodhound (Spec #119). It used to be a column on the
 * consumer's users table, which bloodhound generated, read on every announce
 * and regenerated, on a table it did not own. That column, users.announce_key,
 * is left in place and deprecated: nothing reads it, nothing writes it.
 *
 * Nothing is mass-assignable. A key is written by TrackerStatsService and
 * nothing else, and a credential should never be settable from request data.
 *
 * @property int $id
 * @property int $user_id
 * @property string $key
 * @property Carbon|null $created_at
 */
class AnnounceKey extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'announce_keys';

    protected $guarded = ['*'];
}
