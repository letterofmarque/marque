<?php

declare(strict_types=1);

namespace Marque\Usarrs\Http\Controllers;

use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * job #10602 Gap 7: Fortify's own /email/verify routes are suppressed
 * unconditionally (Fortify::ignoreRoutes(), correct per job #10583), but
 * usarrs never re-registered them under its own name — leaving Laravel's
 * stock 'verified' middleware (used by usarrs' own admin_middleware
 * default) permanently unsatisfiable. See Spec #96.
 *
 * A plain controller, not a Livewire component — matches
 * PasswordResetController's shape. resources/views/auth/verify-email.blade.php
 * already existed (orphaned, pre-dating this fix) as a plain <form> posting
 * to route('verification.send') with a session('status') flash check; this
 * controller follows what that view already assumes rather than rewriting
 * it to a Livewire action.
 */
class EmailVerificationController
{
    public function notice(): View
    {
        return view('usarrs::auth.verify-email')
            ->layout(config('usarrs.layout', 'ise::layouts.app'));
    }

    /**
     * Signed-URL target from Illuminate\Auth\Notifications\VerifyEmail,
     * which hardcodes the route name 'verification.verify' with {id}/{hash}
     * params — this route's name and parameter shape are not usarrs'
     * choice to make, they're what core Laravel's own notification already
     * generates. The `signed` middleware validates the signature itself;
     * the id/hash check below additionally confirms the link belongs to
     * the currently-authenticated user, matching Fortify's own
     * VerifyEmailRequest::authorize() exactly (used as the reference
     * implementation, not reused directly — see Spec #96's Decision on
     * not depending on Fortify's controllers/requests).
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = $request->user();

        abort_unless(
            hash_equals((string) $user->getKey(), $id)
                && hash_equals(sha1($user->getEmailForVerification()), $hash),
            403,
        );

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();

            event(new Verified($user));
        }

        return redirect()->intended('/')->with('status', __('Email verified.'));
    }

    public function send(Request $request): RedirectResponse
    {
        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', __('A new verification link has been sent to your email address.'));
    }
}
