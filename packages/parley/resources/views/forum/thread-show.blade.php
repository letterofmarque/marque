<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        @if ($thread->category)
            <x-deck::button variant="ghost" :href="route('parley.forum.categories.show', $thread->category)" icon="arrow-left" wire:navigate>
                {{ __('Back to :category', ['category' => $thread->category->name]) }}
            </x-deck::button>
        @endif
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-start justify-between gap-4">
            <div>
                <x-deck::heading size="xl">
                    @if ($thread->pinned)
                        <span class="text-base" title="{{ __('Pinned') }}">📌</span>
                    @endif
                    @if ($thread->locked)
                        <span class="text-base" title="{{ __('Locked') }}">🔒</span>
                    @endif
                    {{ $thread->title }}
                </x-deck::heading>
                <x-deck::text class="mt-1 text-zinc-500">
                    {{ __('Started by :name :time', ['name' => $thread->user->name, 'time' => $thread->created_at->diffForHumans()]) }}
                </x-deck::text>
            </div>

            <div class="flex gap-2">
                @can('pin', $thread)
                    <x-deck::button variant="ghost" size="sm" wire:click="{{ $thread->pinned ? 'unpin' : 'pin' }}">
                        {{ $thread->pinned ? __('Unpin') : __('Pin') }}
                    </x-deck::button>
                @endcan

                @can('lock', $thread)
                    <x-deck::button variant="ghost" size="sm" wire:click="{{ $thread->locked ? 'unlock' : 'lock' }}">
                        {{ $thread->locked ? __('Unlock') : __('Lock') }}
                    </x-deck::button>
                @endcan

                @can('delete', $thread)
                    <x-deck::button variant="danger" size="sm" wire:click="delete" wire:confirm="{{ __('Delete this thread? Its posts go with it.') }}">
                        {{ __('Delete') }}
                    </x-deck::button>
                @endcan
            </div>
        </div>
    </div>

    {{--
        The same component guise embeds for torrent comments — a forum
        thread's replies are the other presentation of the same posts/replies
        mechanics, not a second implementation. See CommentThread's docblock.
    --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <livewire:parley-comment-thread :thread="$thread" :key="'forum-posts-'.$thread->id" />
    </div>
</div>
