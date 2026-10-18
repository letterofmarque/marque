<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-deck::button variant="ghost" :href="route('profile.stats')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-deck::button>
    </div>

    <div>
        <x-deck::heading size="xl" class="mb-2">{{ __('Sessions') }}</x-deck::heading>
        <x-deck::text class="mb-6">
            {{ __('Each client announcing a torrent is a session. If a client restarted somewhere else and the tracker refuses it, end the old session here. Anything the old session moved since its last announce is not credited.') }}
        </x-deck::text>

        @if (session('status'))
            <div class="mb-6 rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
                <x-deck::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-deck::text>
            </div>
        @endif

        @if (session('error'))
            <div class="mb-6 rounded-lg bg-red-50 p-4 dark:bg-red-900/20">
                <x-deck::text class="text-sm text-red-700 dark:text-red-300">{{ session('error') }}</x-deck::text>
            </div>
        @endif

        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
            <x-deck::table>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($sessions as $session)
                        <tr wire:key="session-{{ $session->id }}">
                            <td class="px-3 py-2 font-medium">{{ $session->torrentName ?? '#'.$session->torrentId }}</td>
                            <td class="px-3 py-2">{{ $session->seeding ? __('Seeding') : __('Leeching') }}</td>
                            <td class="px-3 py-2">{{ $session->client }}</td>
                            <td class="px-3 py-2">{{ $session->ip }}</td>
                            <td class="px-3 py-2">{{ \Illuminate\Support\Carbon::instance($session->lastAnnounceAt)->diffForHumans() }}</td>
                            <td class="px-3 py-2 text-right">
                                <x-deck::button variant="ghost" size="sm" wire:click="end('{{ $session->id }}')" wire:confirm="{{ __('End this session? What it moved since its last announce is not credited.') }}">
                                    {{ __('End') }}
                                </x-deck::button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-3 py-8 text-center">
                                <x-deck::text>{{ __('No live sessions.') }}</x-deck::text>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-deck::table>
        </div>

        <div class="mt-4">
            {{ $sessions->links() }}
        </div>
    </div>
</div>
