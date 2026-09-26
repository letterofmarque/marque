{{--
    The announce key with its regenerate button. Shared by /profile/stats and
    the dashboard panel; both components expose regenerateAnnounceKey(), so the
    confirm dialog and the tracker round-trip are the same wherever it renders.
--}}
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
