<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-6 text-center">
        <x-deck::heading size="xl">{{ __('Confirm it\'s you') }}</x-deck::heading>

        @if ($approved)
            <x-deck::text>
                {{ __('Confirmed. Go back to the page that asked: it carries on by itself.') }}
            </x-deck::text>
        @elseif ($pending && ! $pending['approved'])
            {{--
                Name the browser that asked. An owner who didn't ask can tell
                here, before approving a session that isn't theirs (Job #355).
            --}}
            <x-deck::text>
                {{ __('A browser signed in to your account asked to confirm it\'s you:') }}
            </x-deck::text>

            <x-deck::text class="font-mono text-sm break-all">{{ $pending['agent'] ?: __('an unidentified browser') }}</x-deck::text>

            <x-deck::text class="text-sm text-zinc-500">
                {{ __('If that wasn\'t you, don\'t confirm. Close this page and tell the site\'s staff: someone may be signed in as you.') }}
            </x-deck::text>

            <x-deck::button variant="primary" wire:click="approve" class="w-full">
                {{ __('Yes, that was me') }}
            </x-deck::button>
        @else
            <x-deck::text class="text-zinc-500">
                {{ __('This link has already been used or has expired.') }}
            </x-deck::text>
        @endif

        <x-deck::error name="token" />
    </div>
</div>
