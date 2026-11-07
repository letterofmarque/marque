<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceKey;
use Marque\Trove\Contracts\UserInterface;

/**
 * Finds the member an announce key belongs to, and provisions one for a key
 * only another system knows (Job #109, Spec #117: lazy migration).
 *
 * A tracker migrating onto Marque needn't import every member of the old one,
 * most of whom never come back. Its AnnounceTransform calls provision() with
 * the key and a creator: for a key Marque already knows, the member comes back
 * from one lookup and the creator never runs. For an unknown key the creator
 * asks the old tracker, the gate, and creates the member, or returns null for
 * "not one of ours". Null creates nothing.
 *
 * Race-safe: a client announcing to many torrents sends many first announces
 * at once. The member and their key are written in one transaction, and the
 * unique index on announce_keys.key (or the member's own unique columns)
 * refuses all but one; a request that loses rolls its member back and returns
 * the winner's. Never check-then-insert.
 */
class AnnounceKeyProvisioner
{
    /**
     * The member a key belongs to: the one lookup every announce does.
     */
    public function userForKey(string $key): ?UserInterface
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');
        $model = new $userModel;

        return $userModel::query()
            ->whereIn($model->getKeyName(), AnnounceKey::query()->select('user_id')->where('key', $key))
            ->first();
    }

    /**
     * The member a key belongs to, provisioning them with $create when Marque
     * doesn't know the key yet.
     *
     * @param  Closure(): ?UserInterface  $create  asks the old system; creates
     *                                             and returns the member, or
     *                                             returns null for "not ours"
     */
    public function provision(string $key, Closure $create): ?UserInterface
    {
        $known = $this->userForKey($key);

        if ($known !== null) {
            return $known;
        }

        try {
            return DB::transaction(function () use ($key, $create): ?UserInterface {
                $user = $create();

                if ($user === null) {
                    return null;
                }

                // Written as the key's row, not through TrackerStats'
                // regenerate (which mints a new key): the member keeps the key
                // the old tracker gave them, which their .torrent files carry.
                (new AnnounceKey)->forceFill(['user_id' => $user->getAuthIdentifier(), 'key' => $key])->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Another request provisioned this key first: ours is rolled
            // back, and theirs is the member. A collision with no member
            // behind the key is something else (an address already taken by
            // an account that isn't this key's), and stays an error.
            return $this->userForKey($key) ?? throw $e;
        }
    }
}
