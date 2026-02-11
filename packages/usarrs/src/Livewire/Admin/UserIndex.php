<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Marque\Usarrs\Livewire\Component;
use Marque\Usarrs\Services\UserAdminService;

#[Title('Users')]
class UserIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $role = '';

    #[Url]
    public string $status = '';

    public function mount(): void
    {
        abort_unless(config('usarrs.admin.enabled', true), 404);
        abort_unless(auth()->user()->isModerator(), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(UserAdminService $service): View
    {
        $users = $service->list(
            search: $this->search ?: null,
            role: $this->role ?: null,
            status: $this->status ?: null,
        );

        return $this->usarrsView('usarrs::admin.index', [
            'users' => $users,
        ]);
    }
}
