<?php

declare(strict_types=1);

namespace Marque\Bosun\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Marque\Bosun\Services\AuditLog as Log;
use Marque\Bosun\Services\Roster;

/**
 * Everything staff have done, newest first, filterable by who, what, to which
 * record and when. Admins and the owner only: moderators don't audit each
 * other.
 */
class AuditLog extends Component
{
    use WithPagination;

    #[Url]
    public string $actor = '';

    #[Url]
    public string $action = '';

    #[Url]
    public string $subjectType = '';

    #[Url]
    public string $subjectId = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render(Log $log, Roster $roster): View
    {
        $this->authorizeAdmin();

        return view('bosun::livewire.audit-log', [
            'entries' => $log->query([
                'actor' => $this->actor,
                'action' => $this->action,
                'subject_type' => $this->subjectType,
                'subject_id' => $this->subjectId,
                'from' => $this->from,
                'to' => $this->to,
            ])->paginate(25),
            'actions' => $log->actions(),
            'staff' => $roster->members(),
        ]);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }
}
