<div class="flex flex-col gap-6" wire:key="parley-posts-{{ $threadId ?? 'new' }}">
    <x-ise::heading size="lg">
        {{ __(':count replies', ['count' => $roots->sum(fn ($post) => 1 + $post->replies->count())]) }}
    </x-ise::heading>

    @if ($thread?->locked)
        <x-ise::text class="text-sm text-zinc-500">
            {{ __('This thread is locked. New comments are disabled.') }}
        </x-ise::text>
    @endif

    @if ($canPost && ! $thread?->locked)
        <form wire:submit="submit" class="flex flex-col gap-2">
            <x-ise::field name="body">
                <x-ise::textarea wire:model="body" rows="3" placeholder="{{ __('Write a comment…') }}" />
            </x-ise::field>

            <div class="flex items-center gap-2">
                <x-ise::button type="submit" variant="primary" size="sm">
                    {{ $replyingTo ? __('Reply') : __('Comment') }}
                </x-ise::button>

                @if ($replyingTo)
                    <x-ise::button variant="ghost" size="sm" wire:click="cancelReply">
                        {{ __('Cancel') }}
                    </x-ise::button>
                @endif
            </div>
        </form>
    @endif

    @if ($roots->isEmpty())
        <x-ise::text class="text-sm text-zinc-500">{{ __('No comments yet.') }}</x-ise::text>
    @else
        <div class="flex flex-col gap-4">
            @foreach ($roots as $post)
                @include('parley::livewire.partials.post', ['post' => $post, 'depth' => 0, 'canPost' => $canPost])
            @endforeach
        </div>
    @endif
</div>
