{{--
    One post plus its replies, indented up to config('parley.nesting.indent_depth').

    Past that depth the tree keeps rendering (there is no cap on reply_to_id in
    storage — see the parley_posts migration) but stops indenting further, so a
    very deep sub-thread doesn't run the page off the right edge of the screen.
    Indentation is the only thing depth affects here; every post, at any depth,
    gets the same reply/edit/delete controls.
--}}
@php
    $maxDepth = config('parley.nesting.indent_depth', 5);
    $indentClass = $depth > 0 && $depth <= $maxDepth ? 'ml-6 border-l border-zinc-200 pl-4 dark:border-zinc-700' : '';
    $isDeleted = $post->trashed();

    // Whether editing/deleting is even possible right now — separate from
    // @can('update'/'delete'), which answers "is this user allowed" (policy).
    // This answers "is it currently possible" (service-layer state), same
    // split PostPolicy documents for create() vs PostService::assertUnlocked().
    // Keeping the button hidden when this is false is what stops a locked,
    // lock_blocks_edits-enabled thread from throwing ThreadLockedException at
    // a user who could not have known clicking Edit would fail.
    $editableNow = ! (config('parley.moderation.lock_blocks_edits', false) && $post->thread->locked);
@endphp

<div class="flex flex-col gap-3 {{ $indentClass }}" wire:key="parley-post-{{ $post->id }}">
    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        @if ($isDeleted)
            <x-ise::text class="text-sm text-zinc-400 italic">{{ __('[deleted]') }}</x-ise::text>
        @else
            <div class="flex items-center justify-between gap-2">
                <x-ise::text class="text-sm font-medium">
                    {{ $post->user->name }}
                    <span class="font-normal text-zinc-500">· {{ $post->created_at->diffForHumans() }}</span>
                    @if ($post->updated_at->gt($post->created_at))
                        <span class="font-normal text-zinc-400">({{ __('edited') }})</span>
                    @endif
                </x-ise::text>
            </div>

            @if ($editingPost === $post->id)
                <form wire:submit="saveEdit" class="mt-2 flex flex-col gap-2">
                    <x-ise::field name="editingBody">
                        <x-ise::textarea wire:model="editingBody" rows="3" />
                    </x-ise::field>

                    <div class="flex items-center gap-2">
                        <x-ise::button type="submit" variant="primary" size="sm">{{ __('Save') }}</x-ise::button>
                        <x-ise::button variant="ghost" size="sm" wire:click="cancelEdit">{{ __('Cancel') }}</x-ise::button>
                    </div>
                </form>
            @else
                {{-- HtmlRenderer only emits nodes the schema permitted, so this is
                     safe to print unescaped — the guarantee is squidink's, not this
                     template's. See Post::renderedBody(). --}}
                <div class="prose prose-sm mt-2 max-w-none dark:prose-invert">
                    {!! $post->renderedBody() !!}
                </div>

                <div class="mt-2 flex items-center gap-3">
                    @if ($canPost && ! $post->thread->locked)
                        <button type="button" wire:click="startReply({{ $post->id }})"
                                class="text-xs font-medium text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
                            {{ __('Reply') }}
                        </button>
                    @endif

                    @if ($editableNow)
                        @can('update', $post)
                            <button type="button" wire:click="startEdit({{ $post->id }})"
                                    class="text-xs font-medium text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
                                {{ __('Edit') }}
                            </button>
                        @endcan

                        @can('delete', $post)
                            <button type="button" wire:click="delete({{ $post->id }})"
                                    wire:confirm="{{ __('Delete this comment?') }}"
                                    class="text-xs font-medium text-red-500 hover:text-red-700 dark:hover:text-red-400">
                                {{ __('Delete') }}
                            </button>
                        @endcan
                    @endif
                </div>
            @endif
        @endif
    </div>

    @if ($replyingTo === $post->id)
        <div class="{{ $depth < $maxDepth ? 'ml-6' : '' }}">
            <form wire:submit="submit" class="flex flex-col gap-2">
                <x-ise::field name="body">
                    <x-ise::textarea wire:model="body" rows="2" placeholder="{{ __('Write a reply…') }}" autofocus />
                </x-ise::field>

                <div class="flex items-center gap-2">
                    <x-ise::button type="submit" variant="primary" size="sm">{{ __('Reply') }}</x-ise::button>
                    <x-ise::button variant="ghost" size="sm" wire:click="cancelReply">{{ __('Cancel') }}</x-ise::button>
                </div>
            </form>
        </div>
    @endif

    @foreach ($post->replies as $reply)
        @include('parley::livewire.partials.post', ['post' => $reply, 'depth' => $depth + 1, 'canPost' => $canPost])
    @endforeach
</div>
