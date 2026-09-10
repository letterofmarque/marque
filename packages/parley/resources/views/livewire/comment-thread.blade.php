<div class="flex flex-col gap-6" wire:key="parley-posts-{{ $threadId ?? 'new' }}">
    <x-deck::heading size="lg">
        {{ __(':count replies', ['count' => $roots->sum(fn ($post) => 1 + $post->replies->count())]) }}
    </x-deck::heading>

    @if ($thread?->locked)
        <x-deck::text class="text-sm text-zinc-500">
            {{ __('This thread is locked. New comments are disabled.') }}
        </x-deck::text>
    @endif

    @if ($canPost && ! $thread?->locked)
        <form wire:submit="submit" class="flex flex-col gap-2">
            <x-deck::field name="body">
                <x-deck::textarea wire:model="body" rows="3" placeholder="{{ __('Write a comment…') }}" />
            </x-deck::field>

            <div class="flex items-center gap-2">
                <x-deck::button type="submit" variant="primary" size="sm">
                    {{ $replyingTo ? __('Reply') : __('Comment') }}
                </x-deck::button>

                @if ($replyingTo)
                    <x-deck::button variant="ghost" size="sm" wire:click="cancelReply">
                        {{ __('Cancel') }}
                    </x-deck::button>
                @endif
            </div>
        </form>
    @endif

    @if ($roots->isEmpty())
        <x-deck::text class="text-sm text-zinc-500">{{ __('No comments yet.') }}</x-deck::text>
    @else
        <div class="flex flex-col gap-4">
            @foreach ($roots as $post)
                @include('parley::livewire.partials.post', ['post' => $post, 'depth' => 0, 'canPost' => $canPost])
            @endforeach
        </div>
    @endif
</div>
