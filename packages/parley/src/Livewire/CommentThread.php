<?php

declare(strict_types=1);

namespace Marque\Parley\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Marque\Parley\Contracts\PostServiceInterface;
use Marque\Parley\Contracts\ThreadServiceInterface;
use Marque\Parley\Exceptions\ThreadLockedException;
use Marque\Parley\Exceptions\TooManyPostsException;
use Marque\Parley\Models\Post;
use Marque\Parley\Models\Thread;

/**
 * The posts inside a thread — a torrent's comments, or a forum thread's
 * replies. Both presentations are the same data (Spec #79's whole premise),
 * so this component drives both rather than one of them duplicating the
 * other's tree/reply/edit/delete logic.
 *
 * Two ways to mount it, matching the two presentations:
 *
 *     <livewire:parley-comment-thread :subject="$torrent" />  // comments
 *     <livewire:parley-comment-thread :thread="$thread" />    // forum
 *
 * $subject takes a model and resolves (or lazily creates) ITS comment
 * thread — the thread may not exist yet, and creating it on the first
 * comment rather than alongside the parent record is what keeps a site that
 * never enables comments from accumulating empty threads
 * (HasThreads::comments(), ThreadService::threadFor()).
 *
 * $thread takes a forum thread directly — it always already exists (created
 * explicitly via ThreadCreate), so there is nothing to resolve lazily.
 *
 * The whole tree loads at once — no pagination within a thread, per
 * config('parley.loading') and the "private trackers are a desktop-use
 * stronghold" reasoning recorded there. Replies nest via reply_to_id to
 * arbitrary depth in storage; config('parley.nesting.indent_depth') governs
 * how far the template indents before it stops, a display choice, not a data
 * one — see the parley_posts migration.
 */
class CommentThread extends Component
{
    #[Locked]
    public ?Model $subject = null;

    public ?int $threadId = null;

    public string $body = '';

    public ?int $replyingTo = null;

    public ?int $editingPost = null;

    public string $editingBody = '';

    public function mount(?Model $subject = null, ?Thread $thread = null): void
    {
        if ($subject === null && $thread === null) {
            throw new \InvalidArgumentException(
                'CommentThread needs either $subject (a comment thread\'s owner) or $thread (a forum thread) to mount.',
            );
        }

        $this->subject = $subject;

        // A forum thread always exists already — ThreadCreate makes it before
        // this component ever mounts. A comment thread may not: reading it
        // here never creates one, only posting does (submit()), so a torrent
        // nobody has commented on yet holds no row.
        $this->threadId = $thread?->getKey() ?? $this->existingThread()?->getKey();
    }

    /**
     * @throws ThreadLockedException
     */
    public function submit(): void
    {
        $this->authorize('create', Post::class);

        $data = $this->validateBody();
        $user = auth()->user();

        try {
            if ($this->replyingTo !== null) {
                // A reply's thread already exists — it is the parent post's
                // thread — so this goes through PostService::reply() rather
                // than resolveThread(). Simpler, and correct for both mount
                // shapes without needing to know which one this is.
                $parent = Post::query()->findOrFail($this->replyingTo);
                $post = $this->postService()->reply($parent, $user, $data['body']);
                $this->threadId = $post->thread_id;
            } else {
                $thread = $this->resolveThread();
                $this->postService()->create($thread, $user, $data['body']);
                $this->threadId = $thread->getKey();
            }
        } catch (TooManyPostsException) {
            // Unlike ThreadLockedException (deliberately left to bubble —
            // a pre-existing, separately-tracked gap), this must never
            // surface as a raw exception: flooding is an expected, everyday
            // occurrence for a UGC form once rate limiting is turned on,
            // not an exceptional server error. See Spec #94.
            $this->addError('body', __("You're posting too quickly — try again in a moment."));

            return;
        }

        $this->reset(['body', 'replyingTo']);
    }

    public function startReply(int $postId): void
    {
        $this->replyingTo = $postId;
        $this->editingPost = null;
    }

    public function cancelReply(): void
    {
        $this->reset(['replyingTo', 'body']);
    }

    public function startEdit(int $postId): void
    {
        $post = Post::query()->findOrFail($postId);

        $this->authorize('update', $post);

        $this->editingPost = $postId;
        $this->editingBody = $post->body;
        $this->replyingTo = null;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingPost', 'editingBody']);
    }

    public function saveEdit(): void
    {
        $post = Post::query()->findOrFail($this->editingPost);

        $this->authorize('update', $post);

        $data = $this->validateBody(field: 'editingBody');

        $this->postService()->update($post, $data['editingBody']);

        $this->reset(['editingPost', 'editingBody']);
    }

    public function delete(int $postId): void
    {
        $post = Post::query()->findOrFail($postId);

        $this->authorize('delete', $post);

        $this->postService()->delete($post);
    }

    public function render(): View
    {
        $thread = $this->existingThread();

        return view('parley::livewire.comment-thread', [
            'thread' => $thread,
            'roots' => $thread === null ? collect() : $this->tree($thread),
            'canPost' => Gate::allows('create', Post::class),
        ]);
    }

    /**
     * Top-level posts with their replies attached recursively, so the view
     * walks a tree instead of re-querying per node.
     *
     * Deliberately includes soft-deleted posts: PostService::delete() leaves
     * a trashed row in place specifically so surviving replies keep a parent
     * to nest under (see its docblock), and the partial renders a trashed
     * post as "[deleted]" rather than reproducing its body. Using
     * PostService::forThread() here would silently drop those rows — it
     * excludes trashed posts for callers that want a plain listing — and
     * orphan every reply underneath a deleted post out of the tree entirely.
     *
     * @return Collection<int, Post>
     */
    private function tree(Thread $thread): Collection
    {
        $all = $thread->posts()->withTrashed()->with('user')->oldest()->get();

        $byParent = $all->groupBy('reply_to_id');

        $attach = function (Post $post) use (&$attach, $byParent): Post {
            $post->setRelation(
                'replies',
                ($byParent->get($post->getKey()) ?? collect())->map($attach)->values(),
            );

            return $post;
        };

        return ($byParent->get(null) ?? collect())->map($attach)->values();
    }

    /**
     * @return array{body?: string, editingBody?: string}
     */
    private function validateBody(string $field = 'body'): array
    {
        return $this->validate([
            $field => ['required', 'string', 'max:'.config('parley.format.max_length', 20000)],
        ], attributes: [$field => __('comment')]);
    }

    /**
     * The thread to post into, creating a comment thread lazily if this
     * component was mounted with $subject rather than $thread. A forum
     * thread ($threadId already set from mount()) is never created here — it
     * exists already, or mount() would have thrown.
     */
    private function resolveThread(): Thread
    {
        if ($this->threadId !== null) {
            return Thread::query()->findOrFail($this->threadId);
        }

        $thread = $this->threadService()->threadFor($this->subject, auth()->user());
        $this->threadId = $thread->getKey();

        return $thread;
    }

    private function existingThread(): ?Thread
    {
        if ($this->threadId !== null) {
            return Thread::query()->find($this->threadId);
        }

        if ($this->subject === null) {
            return null;
        }

        return Thread::comments()
            ->where('threadable_type', $this->subject->getMorphClass())
            ->where('threadable_id', $this->subject->getKey())
            ->first();
    }

    private function threadService(): ThreadServiceInterface
    {
        return app(ThreadServiceInterface::class);
    }

    private function postService(): PostServiceInterface
    {
        return app(PostServiceInterface::class);
    }
}
