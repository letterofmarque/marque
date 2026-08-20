<?php

declare(strict_types=1);

namespace Marque\Parley\Livewire\Forum;

use Illuminate\Contracts\View\View;
use Marque\Parley\Contracts\ThreadServiceInterface;
use Marque\Parley\Livewire\Component as ParleyComponent;
use Marque\Parley\Models\Thread;

/**
 * One forum thread: title, moderation controls, and its posts.
 *
 * The posts themselves — listing, replying, editing, deleting — are not
 * reimplemented here. <livewire:parley-comment-thread :thread="$thread" />
 * is the same component guise embeds for torrent comments; a forum thread's
 * replies and a torrent's comments are the same mechanics on the same
 * models (Spec #79), so this page only owns what's actually different:
 * the title and the pin/lock/delete controls.
 */
class ThreadShow extends ParleyComponent
{
    public Thread $thread;

    public function mount(Thread $thread): void
    {
        $this->thread = $thread->load('user', 'category');
    }

    public function pin(ThreadServiceInterface $threads): void
    {
        $this->authorize('pin', $this->thread);

        $this->thread = $threads->pin($this->thread);
    }

    public function unpin(ThreadServiceInterface $threads): void
    {
        $this->authorize('pin', $this->thread);

        $this->thread = $threads->unpin($this->thread);
    }

    public function lock(ThreadServiceInterface $threads): void
    {
        $this->authorize('lock', $this->thread);

        $this->thread = $threads->lock($this->thread);
    }

    public function unlock(ThreadServiceInterface $threads): void
    {
        $this->authorize('lock', $this->thread);

        $this->thread = $threads->unlock($this->thread);
    }

    public function delete(ThreadServiceInterface $threads): void
    {
        $this->authorize('delete', $this->thread);

        $threads->delete($this->thread);

        $this->redirect(route('parley.forum.categories.show', $this->thread->category), navigate: true);
    }

    public function render(): View
    {
        return $this->parleyView('parley::forum.thread-show', [
            'thread' => $this->thread,
        ])->title($this->thread->title ?? __('Thread'));
    }
}
