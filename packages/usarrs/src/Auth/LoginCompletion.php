<?php

declare(strict_types=1);

namespace Marque\Usarrs\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * The one place an interactive login is finished (Spec #142).
 *
 * Every way in — password, magic link, OAuth, straight after registering —
 * proves who the user is in its own way and then hands the user here. This
 * decides whether a two-factor challenge is due and either starts it or signs
 * the user in.
 *
 * It exists because that decision used to be made by each path separately, and
 * the magic-link and OAuth paths never made it: a user with 2FA confirmed was
 * signed straight in by clicking an emailed link. A new login path cannot
 * forget the challenge if it cannot sign anyone in without coming through here
 * — which is why this is the only interactive Auth::login() in usarrs, and a
 * test holds it to that.
 */
class LoginCompletion
{
    /**
     * Finish a login whose first factor has been proven. Returns where to send
     * the user next: the challenge, or the app.
     */
    public function begin(Authenticatable $user, bool $remember): string
    {
        if ($this->requiresTwoFactorChallenge($user)) {
            session()->put([
                'login.id' => $user->getAuthIdentifier(),
                'login.remember' => $remember,
            ]);

            return route('two-factor.login');
        }

        $this->finish($user, $remember);

        return url('/');
    }

    /**
     * Sign the user in. Called directly only by the two-factor challenge, once
     * the second factor is proven; everything else goes through begin().
     */
    public function finish(Authenticatable $user, bool $remember): void
    {
        Auth::login($user, $remember);

        session()->forget(['login.id', 'login.remember']);
        session()->regenerate();
    }

    private function requiresTwoFactorChallenge(Authenticatable $user): bool
    {
        if (! config('usarrs.two_factor.enabled', false)) {
            return false;
        }

        if (! in_array(TwoFactorAuthenticatable::class, class_uses_recursive($user), true)) {
            return false;
        }

        // Deliberately not Fortify's own hasEnabledTwoFactorAuthentication():
        // that method's "enabled" vs "confirmed" distinction is gated behind
        // Fortify's own features config, which usarrs never populates (usarrs
        // never calls Fortify::routes() or configures config/fortify.php).
        // Checking two_factor_confirmed_at directly is unambiguous regardless
        // of Fortify's feature flags, and is what TwoFactorSetup itself uses
        // to decide "enabled but not yet confirmed" vs "confirmed".
        return ! empty($user->two_factor_secret) && ! empty($user->two_factor_confirmed_at);
    }
}
