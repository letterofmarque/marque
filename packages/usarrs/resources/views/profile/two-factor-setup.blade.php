<div class="flex h-full w-full flex-1 flex-col gap-6">
    <x-deck::heading size="xl">{{ __('Two-Factor Authentication') }}</x-deck::heading>

    @if (session('status'))
        <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
            <x-deck::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-deck::text>
        </div>
    @endif

    @if (! $enabled)
        <x-deck::button wire:click="enable">{{ __('Enable Two-Factor Authentication') }}</x-deck::button>
    @elseif (! $confirmed)
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            {!! $qrCodeSvg !!}

            <x-deck::input wire:model="code" placeholder="{{ __('Enter code from your authenticator app') }}" />
            <x-deck::error name="code" />

            <x-deck::button wire:click="confirm">{{ __('Confirm') }}</x-deck::button>
        </div>
    @else
        <x-deck::text>{{ __('Two-factor authentication is enabled.') }}</x-deck::text>

        @if ($recoveryCodes)
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <x-deck::heading size="sm" class="mb-4">{{ __('Recovery Codes') }}</x-deck::heading>
                <ul class="space-y-1 font-mono text-sm">
                    @foreach ($recoveryCodes as $recoveryCode)
                        <li>{{ $recoveryCode }}</li>
                    @endforeach
                </ul>
                <x-deck::button variant="ghost" size="sm" wire:click="regenerateRecoveryCodes">
                    {{ __('Regenerate Codes') }}
                </x-deck::button>
            </div>
        @endif

        <x-deck::button variant="ghost" wire:click="disable">{{ __('Disable Two-Factor Authentication') }}</x-deck::button>
    @endif
</div>
