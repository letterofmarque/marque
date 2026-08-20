<div class="flex flex-col gap-6" wire:key="parley-posts-{{ $threadId ?? 'new' }}">
    <x-id::heading size="lg">
        {{ __(':count replies', ['count' => $roots->sum(fn ($post) => 1 + $post->replies->count())]) }}
    </x-id::heading>

    @if ($thread?->locked)
        <x-id::text class="text-sm text-zinc-500">
            {{ __('This thread is locked. New comments are disabled.') }}
        </x-id::text>
    @endif

    @if ($canPost && ! $thread?->locked)
        <form wire:submit="submit" class="flex flex-col gap-2">
            <x-id::field name="body">
                <x-id::textarea wire:model="body" rows="3" placeholder="{{ __('Write a comment…') }}" />
            </x-id::field>

            <div class="flex items-center gap-2">
                <x-id::button type="submit" variant="primary" size="sm">
                    {{ $replyingTo ? __('Reply') : __('Comment') }}
                </x-id::button>

                @if ($replyingTo)
                    <x-id::button variant="ghost" size="sm" wire:click="cancelReply">
                        {{ __('Cancel') }}
                    </x-id::button>
                @endif
            </div>
        </form>
    @endif

    @if ($roots->isEmpty())
        <x-id::text class="text-sm text-zinc-500">{{ __('No comments yet.') }}</x-id::text>
    @else
        <div class="flex flex-col gap-4">
            @foreach ($roots as $post)
                @include('parley::livewire.partials.post', ['post' => $post, 'depth' => 0, 'canPost' => $canPost])
            @endforeach
        </div>
    @endif
</div>
