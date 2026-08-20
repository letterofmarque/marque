<?php

declare(strict_types=1);

namespace Marque\Parley\Livewire\Forum;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Marque\Parley\Contracts\PostServiceInterface;
use Marque\Parley\Contracts\ThreadServiceInterface;
use Marque\Parley\Livewire\Component as ParleyComponent;
use Marque\Parley\Models\Category;
use Marque\Parley\Models\Thread;

/**
 * A new forum thread: title plus a first post, created together so a thread
 * never exists without at least one post — unlike a comment thread, which
 * is deliberately allowed to sit empty until someone comments (see
 * ThreadService::threadFor()'s lazy creation). A forum thread only exists
 * because someone chose to start one, so there is nothing to create lazily.
 */
#[Title('New Thread')]
class ThreadCreate extends ParleyComponent
{
    public Category $category;

    public string $title = '';

    public string $body = '';

    public function mount(Category $category): void
    {
        $this->authorize('create', Thread::class);

        $this->category = $category;
    }

    public function submit(ThreadServiceInterface $threads, PostServiceInterface $posts): void
    {
        $this->authorize('create', Thread::class);

        $data = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:'.config('parley.format.max_length', 20000)],
        ]);

        $thread = $threads->create(auth()->user(), $this->category, $data['title']);
        $posts->create($thread, auth()->user(), $data['body']);

        $this->redirect(route('parley.forum.threads.show', $thread), navigate: true);
    }

    public function render(): View
    {
        return $this->parleyView('parley::forum.thread-create', [
            'category' => $this->category,
        ]);
    }
}
