<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Marque\Bloodhound\Contracts\SeedboxRegistry;
use Marque\Bloodhound\Events\SeedboxApproved;
use Marque\Bloodhound\Events\SeedboxRegistered;
use Marque\Bloodhound\Events\SeedboxRevoked;
use Marque\Bloodhound\Models\Seedbox;
use Marque\Bloodhound\Support\Network;
use Marque\Trove\Contracts\SeedboxesInterface;
use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Support\Seedbox as SeedboxData;
use Marque\Trove\Support\StaffAction;
use Throwable;

/**
 * Members' seedboxes: what they register, what admins approve, and which
 * announces that puts under the seedbox speed cap.
 *
 * Both sides of one store. SeedboxesInterface (trove's) is the management
 * side a frontend calls; SeedboxRegistry is the question the announce path
 * asks, answered from each member's approved networks, cached so an announce
 * costs no query. Every change to a member's seedboxes forgets their cache,
 * so an approval or a revocation takes effect on the next announce.
 */
final class Seedboxes implements SeedboxesInterface, SeedboxRegistry
{
    /**
     * Seconds a member's approved networks are cached. Changes made here
     * forget the cache at once; this only bounds a change made around it.
     */
    private const CACHE_SECONDS = 300;

    public function requiresApproval(): bool
    {
        return (bool) config('bloodhound.anti_cheat.seedbox.require_approval', true);
    }

    public function forUser(UserInterface $user): array
    {
        return Seedbox::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->orderByDesc('id')
            ->get()
            ->map(fn (Seedbox $seedbox) => $seedbox->toData())
            ->all();
    }

    public function register(UserInterface $user, string $network, ?string $label = null): SeedboxData
    {
        $parsed = Network::parse($network);
        $narrowest = $parsed->isIpv6()
            ? (int) config('bloodhound.anti_cheat.seedbox.min_prefix_v6', 48)
            : (int) config('bloodhound.anti_cheat.seedbox.min_prefix_v4', 24);

        // A seedbox is a machine or a provider's block, not a whole ISP: a
        // range wide enough to cover other customers would hand them the
        // higher cap too.
        if ($parsed->prefix() < $narrowest) {
            throw new InvalidArgumentException("A seedbox range can be at most a /{$narrowest}; \"{$network}\" is wider.");
        }

        $userId = $user->getAuthIdentifier();

        if (Seedbox::query()->where('user_id', $userId)->where('network', $parsed->cidr())->exists()) {
            throw new InvalidArgumentException("{$parsed->cidr()} is already registered.");
        }

        $approved = ! $this->requiresApproval();

        try {
            $seedbox = Seedbox::query()->create([
                'user_id' => $userId,
                'network' => $parsed->cidr(),
                'label' => $label !== null && trim($label) !== '' ? trim($label) : null,
                'status' => $approved ? SeedboxData::APPROVED : SeedboxData::PENDING,
                'approved_at' => $approved ? now() : null,
            ]);
        } catch (QueryException) {
            throw new InvalidArgumentException("{$parsed->cidr()} is already registered.");
        }

        $this->forget($userId);
        event(new SeedboxRegistered($seedbox->toData()));

        return $seedbox->toData();
    }

    public function remove(UserInterface $user, int $seedboxId): void
    {
        $seedbox = Seedbox::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->findOrFail($seedboxId);

        $seedbox->delete();
        $this->forget($seedbox->user_id);
    }

    public function all(?string $status = null): array
    {
        return Seedbox::query()
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->get()
            ->map(fn (Seedbox $seedbox) => $seedbox->toData())
            ->all();
    }

    public function approve(int $seedboxId, UserInterface $admin): SeedboxData
    {
        $seedbox = $this->decide($seedboxId, SeedboxData::APPROVED, $admin);
        event(new SeedboxApproved($seedbox, $admin->getAuthIdentifier()));

        return $seedbox;
    }

    public function revoke(int $seedboxId, UserInterface $admin): SeedboxData
    {
        $seedbox = $this->decide($seedboxId, SeedboxData::REVOKED, $admin);
        event(new SeedboxRevoked($seedbox, $admin->getAuthIdentifier()));

        return $seedbox;
    }

    /**
     * Is this announce from one of the member's approved seedboxes?
     *
     * On the announce path: the member's approved networks come from the
     * cache, and the match is done here.
     */
    public function isSeedbox(int $userId, string $ip): bool
    {
        $approved = fn () => Seedbox::query()
            ->where('user_id', $userId)
            ->where('status', SeedboxData::APPROVED)
            ->pluck('network')
            ->all();

        // From the database if the cache store is down: a cache outage must
        // not become a tracker outage.
        try {
            $networks = Cache::remember($this->cacheKey($userId), self::CACHE_SECONDS, $approved);
        } catch (Throwable) {
            $networks = $approved();
        }

        foreach ($networks as $network) {
            if (Network::parse($network)->contains($ip)) {
                return true;
            }
        }

        return false;
    }

    private function decide(int $seedboxId, string $status, UserInterface $admin): SeedboxData
    {
        $seedbox = DB::transaction(function () use ($seedboxId, $status, $admin): Seedbox {
            $seedbox = Seedbox::query()->findOrFail($seedboxId);
            $from = $seedbox->status;

            // Approving records who and when, and clears a past revocation;
            // revoking records who and when, and keeps who approved it.
            $seedbox->forceFill($status === SeedboxData::APPROVED
                ? ['status' => $status, 'approved_at' => now(), 'approved_by' => $admin->getAuthIdentifier(), 'revoked_at' => null, 'revoked_by' => null]
                : ['status' => $status, 'revoked_at' => now(), 'revoked_by' => $admin->getAuthIdentifier()])->save();

            // A staff decision, recorded whoever's seedbox it is. Resolved here
            // rather than injected: this service is a singleton, and a store
            // bound after it was first built must still get the record.
            app(StaffActionsInterface::class)->record(StaffAction::of(
                $admin,
                $status === SeedboxData::APPROVED ? 'bloodhound.seedbox.approved' : 'bloodhound.seedbox.revoked',
                $seedbox,
                changes: ['status' => ['from' => $from, 'to' => $status]],
            ));

            return $seedbox;
        });

        $this->forget($seedbox->user_id);

        return $seedbox->toData();
    }

    private function forget(int $userId): void
    {
        try {
            Cache::forget($this->cacheKey($userId));
        } catch (Throwable) {
            // A cache that is down holds nothing stale to forget.
        }
    }

    private function cacheKey(int $userId): string
    {
        return "bloodhound:seedboxes:{$userId}";
    }
}
