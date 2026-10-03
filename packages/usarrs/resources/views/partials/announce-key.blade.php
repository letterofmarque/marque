{{--
    The announce key with its regenerate button, or the way to get a first one.
    Shared by /profile/stats and the dashboard panel; both components expose
    regenerateAnnounceKey(), so the confirm dialog and the tracker round-trip
    are the same wherever it renders.
--}}
@if ($announceKey !== null)
    <div class="flex items-center gap-4">
        <code class="rounded bg-zinc-100 px-3 py-2 font-mono text-sm dark:bg-zinc-800">{{ $announceKey }}</code>
        @if ($allowRegen)
            <x-deck::button
                variant="ghost"
                size="sm"
                wire:click="regenerateAnnounceKey"
                wire:confirm="{{ __('Are you sure? All active torrents will need to be re-downloaded.') }}"
            >
                {{ __('Regenerate') }}
            </x-deck::button>
        @endif
    </div>
    <x-deck::text class="mt-2 text-sm text-zinc-500">
        {{ __('Your announce key is used in tracker URLs. Do not share it.') }}
    </x-deck::text>
@elseif ($addressUnproven)
    {{-- No key until the address is proven (#10879). --}}
    <x-deck::text class="text-sm text-zinc-500">
        {{ __('Verify your email address to get an announce key.') }}
        <a href="{{ route('verification.notice') }}" class="underline">{{ __('Resend the verification email') }}</a>
    </x-deck::text>
@else
    <x-deck::button size="sm" wire:click="regenerateAnnounceKey">{{ __('Generate announce key') }}</x-deck::button>
@endif

@error('email')
    <x-deck::text class="mt-2 text-sm text-red-600">{{ $message }}</x-deck::text>
@enderror
