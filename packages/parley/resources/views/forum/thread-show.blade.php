<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        @if ($thread->category)
            <x-ise::button variant="ghost" :href="route('parley.forum.categories.show', $thread->category)" icon="arrow-left" wire:navigate>
                {{ __('Back to :category', ['category' => $thread->category->name]) }}
            </x-ise::button>
        @endif
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-start justify-between gap-4">
            <div>
                <x-ise::heading size="xl">
                    @if ($thread->pinned)
                        <span class="text-base" title="{{ __('Pinned') }}">📌</span>
                    @endif
                    @if ($thread->locked)
                        <span class="text-base" title="{{ __('Locked') }}">🔒</span>
                    @endif
                    {{ $thread->title }}
                </x-ise::heading>
                <x-ise::text class="mt-1 text-zinc-500">
                    {{ __('Started by :name :time', ['name' => $thread->user->name, 'time' => $thread->created_at->diffForHumans()]) }}
                </x-ise::text>
            </div>

            <div class="flex gap-2">
                @can('pin', $thread)
                    <x-ise::button variant="ghost" size="sm" wire:click="{{ $thread->pinned ? 'unpin' : 'pin' }}">
                        {{ $thread->pinned ? __('Unpin') : __('Pin') }}
                    </x-ise::button>
                @endcan

                @can('lock', $thread)
                    <x-ise::button variant="ghost" size="sm" wire:click="{{ $thread->locked ? 'unlock' : 'lock' }}">
                        {{ $thread->locked ? __('Unlock') : __('Lock') }}
                    </x-ise::button>
                @endcan

                @can('delete', $thread)
                    <x-ise::button variant="danger" size="sm" wire:click="delete" wire:confirm="{{ __('Delete this thread? Its posts go with it.') }}">
                        {{ __('Delete') }}
                    </x-ise::button>
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
