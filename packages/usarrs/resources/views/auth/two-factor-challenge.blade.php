<div class="flex h-full w-full flex-1 flex-col gap-6">
    <x-ise::heading size="xl">{{ __('Two-Factor Challenge') }}</x-ise::heading>

    <x-ise::text>{{ __('Enter the code from your authenticator app, or a recovery code.') }}</x-ise::text>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <x-ise::input wire:model="code" placeholder="{{ __('Authentication code') }}" />
        <x-ise::error name="code" />
        <x-ise::button wire:click="challenge">{{ __('Verify') }}</x-ise::button>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <x-ise::input wire:model="recoveryCode" placeholder="{{ __('Recovery code') }}" />
        <x-ise::error name="recoveryCode" />
        <x-ise::button variant="ghost" wire:click="challengeWithRecoveryCode">{{ __('Use recovery code') }}</x-ise::button>
    </div>
</div>
