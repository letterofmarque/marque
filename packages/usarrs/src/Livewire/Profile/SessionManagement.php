<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;
use Marque\Usarrs\Livewire\Component;
use RuntimeException;

/**
 * A member's live tracker sessions, each with a way to end it (Job #193, D8).
 *
 * A client that restarts somewhere else with a new peer ID is refused by the
 * tracker's location limits while its old session is still live. Ending the
 * old one here frees the member at once; the tracker decides what that costs
 * (bloodhound: whatever the session moved since its last announce).
 */
class SessionManagement extends Component
{
    use WithPagination;

    private const PER_PAGE = 50;

    public function mount(): void
    {
        abort_unless($this->trackerSessions() !== null, 404);
    }

    public function end(string $sessionId): void
    {
        try {
            $ended = $this->trackerSessions()->endSession(auth()->user(), $sessionId);
        } catch (RuntimeException $busy) {
            session()->flash('error', $busy->getMessage());

            return;
        }

        session()->flash('status', $ended
            ? __('Session ended. A client restarted elsewhere can announce now.')
            : __('That session had already ended.'));
    }

    public function render(): View
    {
        // The tracker answers with the whole list (a seedbox's can run to
        // thousands); a page of it is shown.
        $sessions = $this->trackerSessions()->sessionsFor(auth()->user());
        $page = $this->getPage();

        return $this->usarrsView('usarrs::profile.sessions', [
            'sessions' => new LengthAwarePaginator(
                array_slice($sessions, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
                count($sessions),
                self::PER_PAGE,
                $page,
            ),
        ])->title(__('Sessions'));
    }
}
