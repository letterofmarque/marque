<?php

declare(strict_types=1);

namespace Marque\Usarrs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An OAuth identity linked to an account (Spec #142).
 *
 * Deliberately not mass-assignable: a row here is a way to sign in as
 * `user_id`, so only usarrs creates one, with forceCreate(), after it has
 * decided the link is legitimate.
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $provider_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SocialAccount extends Model
{
    protected $table = 'usarrs_social_accounts';

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('trove.user_model', 'App\\Models\\User'));
    }

    public static function resolve(string $provider, string $providerUserId): ?self
    {
        return self::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();
    }
}
