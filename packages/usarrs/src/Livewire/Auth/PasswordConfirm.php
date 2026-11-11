<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Auth;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmPassword;
use Livewire\Attributes\Title;
use Marque\Usarrs\Auth\EmailStepUp;
use Marque\Usarrs\Auth\LoginCompletion;
use Marque\Usarrs\Auth\VerifiedAddress;
use Marque\Usarrs\Enums\AuthDriver;
use Marque\Usarrs\Livewire\Component;
use Marque\Usarrs\Models\SocialAccount;
use Marque\Usarrs\Notifications\StepUpConfirmationLink;

/**
 * The "confirm it's you" page Laravel's stock 'password.confirm' middleware
 * (RequirePassword) redirects to (job #10602 Gap 7, Spec #96). Fortify's
 * routes are suppressed unconditionally, so usarrs registers this one, using
 * Fortify's own ConfirmPassword action as a library.
 *
 * It offers whatever the member actually has (Job #355). It used to ask only
 * for a password, which a socialite member doesn't know (theirs is random) and
 * a magic-link member never chose, so nothing behind password.confirm could be
 * reached by them at all:
 *
 * - their password, where a password signs in;
 * - a link to their verified address, on every driver (Auth\EmailStepUp);
 * - signing in again with a provider connected to their account, under
 *   socialite (SocialiteController::confirm);
 * - a passkey, where they have one (laravel/passkeys' confirm endpoint).
 *
 * Every one ends in the same session mark, so the stock middleware stays the
 * single gate.
 */
#[Title('Confirm it\'s you')]
class PasswordConfirm extends Component
{
    public string $password = '';

    public function confirm(ConfirmPassword $confirmPassword, StatefulGuard $guard): void
    {
        // Only where a password is a way in. Under magic_link or socialite a
        // password set from the profile never was, and may be a squatter's
        // (#10880).
        if (! $this->driver()->allowsPasswordLogin()) {
            throw ValidationException::withMessages([
                'password' => [__('Passwords aren\'t used to sign in here. Confirm another way.')],
            ]);
        }

        // Five a minute per user (#10856): a hijacked session could otherwise
        // guess the password here without going near the login form.
        $key = 'usarrs.confirm-password:'.auth()->id();

        // Counted before the check, atomically (Job #141 review).
        if (RateLimiter::hit($key) > 5) {
            throw ValidationException::withMessages([
                'password' => [__('Too many attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)])],
            ]);
        }

        $confirmed = $confirmPassword($guard, auth()->user(), $this->password);

        if (! $confirmed) {
            throw ValidationException::withMessages([
                'password' => [__('This password does not match our records.')],
            ]);
        }

        RateLimiter::clear($key);
        $this->confirmed();
    }

    /**
     * Mail a "confirm it's you" link to the member's verified address.
     */
    public function sendLink(): void
    {
        $user = auth()->user();

        // An unproven address is no proof of anything: a thief holding the
        // session can change it to their own (Edit::save resets verification).
        if (VerifiedAddress::missing($user)) {
            $this->addError('link', __('Verify your email address first.'));

            return;
        }

        // One a minute: each one is a mail to the member.
        if (RateLimiter::hit('usarrs.step-up-mail:'.$user->getAuthIdentifier(), 60) > 1) {
            $this->addError('link', __('A link was sent less than a minute ago. Check your email.'));

            return;
        }

        $user->notify(new StepUpConfirmationLink(
            EmailStepUp::request($user, request()->userAgent()),
            (string) request()->userAgent(),
        ));

        session()->flash('status', __('We\'ve emailed you a link. Approve it there and this page carries on.'));
    }

    /**
     * Has the emailed link been approved? The page polls this while a link is
     * out.
     */
    public function checkLink(): void
    {
        if (EmailStepUp::claim(auth()->user())) {
            $this->confirmed();
        }
    }

    private function confirmed(): void
    {
        session()->put('auth.password_confirmed_at', Date::now()->unix());

        // Back to the page that asked, as Laravel's own confirm flow does: the
        // password.confirm middleware records it (Job #141 review).
        $this->redirect(session()->pull('url.intended', LoginCompletion::home()), navigate: true);
    }

    private function driver(): AuthDriver
    {
        return AuthDriver::from(config('usarrs.auth_driver', 'password'));
    }

    /**
     * Providers connected to this member that can confirm: configured ones,
     * under socialite, where the confirm route exists.
     *
     * @return list<string>
     */
    private function providers(): array
    {
        if ($this->driver() !== AuthDriver::Socialite || ! Route::has('socialite.confirm')) {
            return [];
        }

        $connected = SocialAccount::query()
            ->where('user_id', auth()->id())
            ->pluck('provider')
            ->all();

        return array_values(array_intersect(config('usarrs.socialite_providers', []), $connected));
    }

    private function hasPasskey(): bool
    {
        $user = auth()->user();

        return config('usarrs.passkeys.enabled', false)
            && Route::has('passkey.confirm')
            && method_exists($user, 'passkeys')
            && $user->passkeys()->exists();
    }

    public function render(): View
    {
        return $this->usarrsView('usarrs::auth.password-confirm', [
            'offersPassword' => $this->driver()->allowsPasswordLogin(),
            'offersLink' => ! VerifiedAddress::missing(auth()->user()),
            'needsVerification' => VerifiedAddress::missing(auth()->user()) && Route::has('verification.notice'),
            'linkOut' => session()->has(EmailStepUp::SESSION_KEY),
            'providers' => $this->providers(),
            'passkeyUrls' => $this->hasPasskey()
                ? ['options' => route('passkey.confirm-options'), 'confirm' => route('passkey.confirm')]
                : null,
        ]);
    }
}
