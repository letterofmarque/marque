<?php

declare(strict_types=1);

namespace Marque\Bosun\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $user_id
 * @property Carbon $last_seen_at
 */
class StaffPresence extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'staff_presence';

    protected $primaryKey = 'user_id';

    protected $fillable = ['user_id', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}
