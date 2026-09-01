<div class="flex h-full w-full flex-1 flex-col gap-6">
    <x-ise::heading size="xl">{{ __('Two-Factor Authentication') }}</x-ise::heading>

    @if (session('status'))
        <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
            <x-ise::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-ise::text>
        </div>
    @endif

    @if (! $enabled)
        <x-ise::button wire:click="enable">{{ __('Enable Two-Factor Authentication') }}</x-ise::button>
    @elseif (! $confirmed)
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            {!! $qrCodeSvg !!}

            <x-ise::input wire:model="code" placeholder="{{ __('Enter code from your authenticator app') }}" />
            <x-ise::error name="code" />

            <x-ise::button wire:click="confirm">{{ __('Confirm') }}</x-ise::button>
        </div>
    @else
        <x-ise::text>{{ __('Two-factor authentication is enabled.') }}</x-ise::text>

        @if ($recoveryCodes)
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <x-ise::heading size="sm" class="mb-4">{{ __('Recovery Codes') }}</x-ise::heading>
                <ul class="space-y-1 font-mono text-sm">
                    @foreach ($recoveryCodes as $recoveryCode)
                        <li>{{ $recoveryCode }}</li>
                    @endforeach
                </ul>
                <x-ise::button variant="ghost" size="sm" wire:click="regenerateRecoveryCodes">
                    {{ __('Regenerate Codes') }}
                </x-ise::button>
            </div>
        @endif

        <x-ise::button variant="ghost" wire:click="disable">{{ __('Disable Two-Factor Authentication') }}</x-ise::button>
    @endif
</div>
