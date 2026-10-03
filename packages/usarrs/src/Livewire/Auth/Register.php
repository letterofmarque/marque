<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Marque\Usarrs\Auth\LoginCompletion;
use Marque\Usarrs\Auth\NewAccount;
use Marque\Usarrs\Auth\RegistrationRules;
use Marque\Usarrs\Enums\AuthDriver;
use Marque\Usarrs\Exceptions\InviteAlreadyRedeemed;
use Marque\Usarrs\Livewire\Component;
use Marque\Usarrs\Rules\UniqueEmail;
use Marque\Usarrs\Services\InviteService;

#[Title('Register')]
class Register extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    public string $email = '';

    #[Validate('required|string|min:8|confirmed')]
    public string $password = '';

    public string $password_confirmation = '';

    public string $invite = '';

    /**
     * The address must be unused in any case (CP #777) — a rule object, so it
     * can't sit in an attribute.
     */
    protected function rules(): array
    {
        return ['email' => ['required', 'email', new UniqueEmail]];
    }

    public function mount(RegistrationRules $rules): void
    {
        $this->invite = (string) request()->query('invite', '');

        // Under invite_only the form opens only for someone holding a usable
        // invite. It used to 404 before the code was even read (#10801).
        abort_unless(
            $this->driver()->allowsPasswordRegistration()
                || ($this->driver()->requiresInvite() && $rules->validInvite($this->invite) !== null),
            404,
        );
    }

    public function register(InviteService $inviteService, RegistrationRules $rules): void
    {
        // Checked again here, not only in mount(): a page loaded before the
        // operator changed modes can still be submitted (Spec #142).
        abort_unless($this->driver()->allowsPasswordRegistration() || $this->driver()->requiresInvite(), 404);

        $this->validate();

        // The same rules the OAuth callback asks (Spec #142).
        if (($refusal = $rules->refusal($this->invite)) !== null) {
            $this->addError('invite', $refusal);

            return;
        }

        $invite = $rules->validInvite($this->invite);

        $model = config('trove.user_model', 'App\\Models\\User');

        // One transaction: an invite lost to a concurrent registration takes
        // this account with it (Build #124 CP #763).
        try {
            $user = DB::transaction(function () use ($model, $invite, $inviteService) {
                $user = $model::create([
                    'name' => $this->name,
                    'email' => $this->email,
                    'password' => Hash::make($this->password),
                ]);

                if ($invite !== null) {
                    $inviteService->redeem($invite, $user);
                }

                return $user;
            });
        } catch (InviteAlreadyRedeemed) {
            $this->addError('invite', __('That invite has already been used.'));

            return;
        }

        NewAccount::announce($user);

        $this->redirect(app(LoginCompletion::class)->begin($user, remember: false), navigate: true);
    }

    private function driver(): AuthDriver
    {
        return AuthDriver::from(config('usarrs.auth_driver', 'password'));
    }

    public function render(): View
    {
        return $this->usarrsView('usarrs::auth.register', [
            'inviteRequired' => app(RegistrationRules::class)->inviteRequired(),
        ]);
    }
}
