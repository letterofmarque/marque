<div class="flex flex-col gap-3">
    @if ($twoFactorOn !== null)
        <div class="flex items-center justify-between">
            <x-deck::text>{{ __('Two-factor authentication') }}</x-deck::text>
            <x-deck::text class="{{ $twoFactorOn ? 'text-green-700 dark:text-green-300' : 'text-zinc-500' }}">
                {{ $twoFactorOn ? __('On') : __('Off') }}
            </x-deck::text>
        </div>
    @endif

    @if ($passkeyCount !== null)
        <div class="flex items-center justify-between">
            <x-deck::text>{{ __('Passkeys') }}</x-deck::text>
            <x-deck::text class="{{ $passkeyCount > 0 ? 'text-green-700 dark:text-green-300' : 'text-zinc-500' }}">
                {{ $passkeyCount }}
            </x-deck::text>
        </div>
    @endif
</div>
