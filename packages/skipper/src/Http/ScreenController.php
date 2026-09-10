<?php

declare(strict_types=1);

namespace Marque\Skipper\Http;

use Illuminate\Http\Request;
use Livewire\Livewire;
use Marque\Trove\Enums\Role;
use Marque\Trove\Registry\AdminScreen;
use Marque\Trove\Registry\AdminScreenRegistry;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves a registered admin screen and renders it.
 *
 * A controller rather than a closure because `route:cache` cannot serialise a
 * closure, and the panel's routes must survive caching (Build #101 CP1).
 *
 * **This is the second of the two enforcement points.** The panel's listing
 * filters by role, and so does this — both reading the same `AdminScreen`.
 * Filtering a menu is not protecting a screen: without the check here, a
 * moderator reaches an admin screen by typing its URL, and the panel looks
 * correct the whole time.
 */
class ScreenController
{
    public function __construct(private readonly AdminScreenRegistry $registry) {}

    public function __invoke(Request $request, string $screen): mixed
    {
        $entry = $this->registry->find($screen);

        // An unknown identifier is a 404 — the screen does not exist, which is
        // a different thing from existing and being off-limits.
        if ($entry === null) {
            throw new NotFoundHttpException("No admin screen [{$screen}].");
        }

        if (! $entry->allows($this->viewerRole($request))) {
            // 403 rather than a redirect to the panel: a redirect reads as
            // "that worked" and hides the refusal from anything automated.
            throw new AccessDeniedHttpException(
                "Insufficient role for admin screen [{$screen}]."
            );
        }

        return $this->render($entry);
    }

    /**
     * Hand off to the screen's own Livewire component, inside the panel layout.
     *
     * The screen owns its content; skipper owns the chrome around it, so a
     * package contributing a screen writes an ordinary Livewire component and
     * gets the panel's layout for free.
     */
    private function render(AdminScreen $entry): mixed
    {
        // Livewire's own full-page mount, so the screen's component renders
        // inside the configured layout exactly as the panel index does. The
        // screen owns its content; skipper owns the chrome around it.
        return Livewire::mount($entry->component);
    }

    /**
     * The requesting user's role.
     *
     * Guests never reach here — the panel's middleware redirects them to login
     * first, which is deliberate: 403 to an anonymous visitor confirms the
     * screen exists. If middleware is ever misconfigured, falling back to the
     * lowest rank fails closed rather than open.
     */
    private function viewerRole(Request $request): Role
    {
        $user = $request->user();

        if ($user === null) {
            return Role::User;
        }

        $role = $user->role ?? null;

        return $role instanceof Role ? $role : Role::User;
    }
}
