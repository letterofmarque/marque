<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-6 text-center">
        <x-deck::heading size="xl">{{ __('Connect :provider', ['provider' => $providerName]) }}</x-deck::heading>

        @if ($pending)
            {{--
                Name the provider account. A connection the user didn't start is
                recognisable here, before anything happens (Spec #142).
            --}}
            <x-deck::text>
                {{ __('Connect the :provider account', ['provider' => $providerName]) }}
                <strong>{{ $pending['label'] }}</strong>
                {{ __('to your account? After this, signing in with :provider goes straight in.', ['provider' => $providerName]) }}
            </x-deck::text>

            <x-deck::text class="text-sm text-zinc-500">
                {{ __("If you didn't just try to sign in with :provider, don't connect it — close this page and nothing changes.", ['provider' => $providerName]) }}
            </x-deck::text>

            <x-deck::button variant="primary" wire:click="connect" class="w-full">
                {{ __('Connect :provider', ['provider' => $providerName]) }}
            </x-deck::button>
        @else
            <x-deck::text class="text-zinc-500">
                {{ __('This link has already been used or has expired.') }}
            </x-deck::text>
        @endif

        <x-deck::error name="token" />
    </div>
</div>
