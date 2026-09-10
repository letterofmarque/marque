<div class="flex h-full w-full flex-1 flex-col gap-6">
    <x-deck::heading size="xl">{{ __('Two-Factor Challenge') }}</x-deck::heading>

    <x-deck::text>{{ __('Enter the code from your authenticator app, or a recovery code.') }}</x-deck::text>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <x-deck::input wire:model="code" placeholder="{{ __('Authentication code') }}" />
        <x-deck::error name="code" />
        <x-deck::button wire:click="challenge">{{ __('Verify') }}</x-deck::button>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <x-deck::input wire:model="recoveryCode" placeholder="{{ __('Recovery code') }}" />
        <x-deck::error name="recoveryCode" />
        <x-deck::button variant="ghost" wire:click="challengeWithRecoveryCode">{{ __('Use recovery code') }}</x-deck::button>
    </div>
</div>
