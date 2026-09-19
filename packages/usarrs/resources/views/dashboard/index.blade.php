<div class="flex h-full w-full flex-1 flex-col gap-6">
    <x-deck::heading size="xl">{{ __('Dashboard') }}</x-deck::heading>

    @if (empty($panels))
        {{--
            An empty grid reads as a broken page. Saying so plainly is a better
            artifact, and it is the honest state for an install whose packages
            contribute nothing — a catalogue-only deployment, say (Spec #118).
        --}}
        <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
            <x-deck::text class="text-zinc-500">
                {{ __('Nothing to show yet.') }}
            </x-deck::text>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($panels as $panel)
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-4 flex items-center gap-2">
                        @if ($panel->icon)
                            <x-deck::icon :name="$panel->icon" class="h-5 w-5 text-zinc-400" />
                        @endif
                        <x-deck::heading size="sm">{{ $panel->label }}</x-deck::heading>
                    </div>

                    {{--
                        The panel renders itself. usarrs knows the component's
                        name and nothing else about it — which is what lets
                        bloodhound own its ratio figures and a third-party
                        package contribute without usarrs being changed.
                    --}}
                    @livewire($panel->component, key($panel->identifier))
                </div>
            @endforeach
        </div>
    @endif
</div>
