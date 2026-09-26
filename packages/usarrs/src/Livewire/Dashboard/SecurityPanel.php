<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Dashboard;

use Illuminate\Contracts\View\View;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Marque\Usarrs\Livewire\Component;

/**
 * The dashboard's account security panel: two-factor state and passkey count.
 *
 * Each line appears only when its feature is enabled AND the user model can
 * carry it — the same two checks TwoFactorSetup and PasskeyManagement make
 * before they will mount. Both features are off by default, so on a stock
 * install this panel has nothing to say and does not appear.
 */
class SecurityPanel extends Component
{
    /**
     * Whether any security line applies to this user. The registry's
     * visibility closure asks this, so a panel with no lines never renders.
     */
    public static function appliesTo(?object $user): bool
    {
        return self::showsTwoFactor($user) || self::showsPasskeys($user);
    }

    public function render(): View
    {
        $user = auth()->user();

        return view('usarrs::dashboard.panels.security', [
            // Confirmed, not merely enabled: Fortify writes the secret at
            // enable() and only challenges logins once confirm() stamps this.
            'twoFactorOn' => self::showsTwoFactor($user) ? ! empty($user->two_factor_confirmed_at) : null,
            'passkeyCount' => self::showsPasskeys($user) ? $user->passkeys()->count() : null,
        ]);
    }

    private static function showsTwoFactor(?object $user): bool
    {
        return $user !== null
            && config('usarrs.two_factor.enabled', false)
            && in_array(TwoFactorAuthenticatable::class, class_uses_recursive($user), true);
    }

    private static function showsPasskeys(?object $user): bool
    {
        return $user !== null
            && config('usarrs.passkeys.enabled', false)
            && method_exists($user, 'passkeys');
    }
}
