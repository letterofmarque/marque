<div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
    <x-deck::heading size="lg">{{ __('Connected accounts') }}</x-deck::heading>
    <x-deck::text class="mt-1 text-sm text-zinc-500">
        {{ __('Each of these can sign in to your account.') }}
    </x-deck::text>

    <div class="mt-4 flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
        @foreach ($connections as $connection)
            <div class="flex items-center justify-between py-2" wire:key="connection-{{ $connection->provider }}">
                <div>
                    <x-deck::text>{{ ucfirst($connection->provider) }}</x-deck::text>
                    <x-deck::text class="text-xs text-zinc-500">
                        {{ __('Connected :date', ['date' => $connection->created_at?->toFormattedDateString() ?? '']) }}
                    </x-deck::text>
                </div>
                <x-deck::button variant="ghost" size="sm" wire:click="disconnect(@js($connection->provider))"
                    wire:confirm="{{ __('Remove :provider? It will no longer sign in to your account.', ['provider' => ucfirst($connection->provider)]) }}">
                    {{ __('Remove') }}
                </x-deck::button>
            </div>
        @endforeach
    </div>

    @if (session('status'))
        <x-deck::text class="mt-2 text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-deck::text>
    @endif

    <x-deck::error name="disconnect" />

    @if ($connectable)
        {{-- A POST, so no other page can start a connection in this browser.
             socialite.connect asks for a confirmed step-up first (#10858). --}}
        <div class="mt-4 flex flex-wrap gap-2">
            @foreach ($connectable as $provider)
                <form method="POST" action="{{ route('socialite.connect', $provider) }}">
                    @csrf
                    <x-deck::button type="submit" variant="outline" size="sm">
                        {{ __('Connect :provider', ['provider' => ucfirst($provider)]) }}
                    </x-deck::button>
                </form>
            @endforeach
        </div>
    @endif
</div>
