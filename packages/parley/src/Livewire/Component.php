<?php

declare(strict_types=1);

namespace Marque\Parley\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Component as LivewireComponent;

/**
 * Same shape as guise's and usarrs's own base component — a layout pulled
 * from config rather than hard-coded, so a consumer publishing parley's views
 * can point them at their own shell.
 */
abstract class Component extends LivewireComponent
{
    protected function parleyLayout(): string
    {
        return config('parley.layout', 'deck::layouts.app');
    }

    protected function parleyView(string $view, array $data = []): View
    {
        return view($view, $data)->layout($this->parleyLayout());
    }

    /**
     * Send a guest to log in, rather than answer a bare 403, when they reach
     * something only a member can do (#10563). The policies deny guests either
     * way; this is the response. Browsing stays open to guests, so the routes
     * don't carry 'auth', and its login redirect never runs.
     *
     * `$intended` is where to come back to. An app with no route named 'login'
     * keeps the 403, since there is nowhere to send them.
     *
     * @return bool whether the guest was sent away
     */
    protected function redirectGuestToLogin(?string $intended = null): bool
    {
        if (auth()->check() || ! Route::has('login')) {
            return false;
        }

        session()->put('url.intended', $intended ?? url()->previous());
        $this->redirect(route('login'));

        return true;
    }
}
