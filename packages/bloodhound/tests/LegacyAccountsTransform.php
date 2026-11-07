<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

use Illuminate\Http\Request;
use Marque\Bloodhound\Contracts\AnnounceTransform;
use Marque\Bloodhound\Services\AnnounceKeyProvisioner;
use Marque\Bloodhound\Services\LedgerAdjuster;
use Marque\Trove\Contracts\UserInterface;

/**
 * Lazy migration, as a tracker would write it: a key Marque doesn't know yet
 * but the old tracker does provisions its member on first announce, bringing
 * their old upload with them. The "old tracker" is $legacy, keyed by passkey.
 *
 * $carriedBy names the admin recorded as carrying the old figures over; null
 * leaves them behind.
 */
class LegacyAccountsTransform implements AnnounceTransform
{
    /** @var array<string, array{email: string, uploaded: int}> */
    public static array $legacy = [];

    public static int $created = 0;

    public static ?int $carriedBy = null;

    public function __construct(private readonly AnnounceKeyProvisioner $provisioner) {}

    public function apply(Request $request): Request
    {
        return $request;
    }

    public function resolveUser(Request $request): ?UserInterface
    {
        $key = (string) $request->query('passkey');

        return $this->provisioner->provision($key, function () use ($key): ?UserInterface {
            // The gate: a key the old tracker never issued is not ours.
            $old = static::$legacy[$key] ?? null;

            if ($old === null) {
                return null;
            }

            static::$created++;

            return TestUser::create(['name' => $old['email'], 'email' => $old['email'], 'password' => 'p']);
        }, function (UserInterface $user) use ($key): void {
            // After the member is committed, and only by the request that
            // created them: the adjustment commits on its own when the ledger
            // has its own database.
            $uploaded = static::$legacy[$key]['uploaded'] ?? 0;

            if (static::$carriedBy !== null && $uploaded > 0) {
                app(LedgerAdjuster::class)->adjust((int) $user->getAuthIdentifier(), 0, ['uploaded' => $uploaded], by: static::$carriedBy, reason: 'Carried over from the old tracker');
            }
        });
    }
}
