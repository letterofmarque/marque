<?php

declare(strict_types=1);

use Livewire\Livewire;
use Marque\Parley\Contracts\PostServiceInterface;
use Marque\Parley\Contracts\ThreadServiceInterface;
use Marque\Parley\Livewire\CommentThread;
use Marque\Parley\Livewire\Forum\ThreadShow;
use Marque\Parley\Models\Post;
use Marque\Parley\Models\Thread;
use Marque\Parley\Tests\TestUser;
use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Support\StaffAction;

/**
 * What parley records through trove's StaffActionsInterface (Plan #16):
 * pinning and locking, which are moderation whoever does them, and deleting a
 * thread or editing or deleting a post that isn't the actor's own.
 */
beforeEach(function () {
    $this->store = new class implements StaffActionsInterface
    {
        /** @var list<StaffAction> */
        public array $recorded = [];

        public function record(StaffAction $action): void
        {
            $this->recorded[] = $action;
        }
    };
    app()->instance(StaffActionsInterface::class, $this->store);

    $this->author = TestUser::factory()->create();
    $this->moderator = TestUser::factory()->moderator()->create();
    $this->thread = Thread::factory()->create(['user_id' => $this->author->id, 'title' => 'Week 12 thread']);
    $this->post = app(PostServiceInterface::class)->create($this->thread, $this->author, 'First!');
});

function actions(array $recorded): array
{
    return array_map(fn (StaffAction $a): string => $a->action, $recorded);
}

describe('threads', function () {
    it('records pin, unpin, lock and unlock, with the flag before and after', function () {
        $threads = app(ThreadServiceInterface::class);

        $threads->pin($this->thread, $this->moderator);
        $threads->unpin($this->thread, $this->moderator);
        $threads->lock($this->thread, $this->moderator, 'Flame war');
        $threads->unlock($this->thread, $this->moderator);

        expect(actions($this->store->recorded))->toBe(['parley.thread.pinned', 'parley.thread.unpinned', 'parley.thread.locked', 'parley.thread.unlocked'])
            ->and($this->store->recorded[0]->changes)->toBe(['pinned' => ['from' => false, 'to' => true]])
            ->and($this->store->recorded[2]->reason)->toBe('Flame war')
            ->and($this->store->recorded[2]->subjectId)->toBe($this->thread->id)
            ->and($this->store->recorded[2]->actorId)->toBe($this->moderator->id);
    });

    it('records nothing for a pin that changed nothing', function () {
        $threads = app(ThreadServiceInterface::class);

        $threads->pin($this->thread, $this->moderator);
        $threads->pin($this->thread, $this->moderator);

        expect($this->store->recorded)->toHaveCount(1);
    });

    it('records a moderator deleting or retitling someone else\'s thread, keeping its title', function () {
        $threads = app(ThreadServiceInterface::class);

        $threads->update($this->thread, 'Week 12, 2006', $this->moderator);
        $threads->delete($this->thread, $this->moderator, 'Off topic');

        expect(actions($this->store->recorded))->toBe(['parley.thread.edited', 'parley.thread.deleted'])
            ->and($this->store->recorded[0]->changes)->toBe(['title' => ['from' => 'Week 12 thread', 'to' => 'Week 12, 2006']])
            ->and($this->store->recorded[1]->changes)->toBe(['title' => ['from' => 'Week 12, 2006', 'to' => null]])
            ->and($this->store->recorded[1]->reason)->toBe('Off topic');
    });

    it('records nothing when the author retitles or deletes their own', function () {
        $threads = app(ThreadServiceInterface::class);

        $threads->update($this->thread, 'Renamed', $this->author);
        $threads->delete($this->thread, $this->author);

        expect($this->store->recorded)->toBe([]);
    });

    it('records through the thread screen', function () {
        Livewire::actingAs($this->moderator)
            ->test(ThreadShow::class, ['thread' => $this->thread])
            ->call('lock');

        expect(actions($this->store->recorded))->toBe(['parley.thread.locked']);
    });
});

describe('posts', function () {
    it('records a moderator editing and deleting someone else\'s post, with the body', function () {
        $posts = app(PostServiceInterface::class);

        $posts->update($this->post, 'Edited by staff', actor: $this->moderator, reason: 'Slur');
        $posts->delete($this->post, actor: $this->moderator);

        expect(actions($this->store->recorded))->toBe(['parley.post.edited', 'parley.post.deleted'])
            ->and($this->store->recorded[0]->changes)->toBe(['body' => ['from' => 'First!', 'to' => 'Edited by staff']])
            ->and($this->store->recorded[0]->reason)->toBe('Slur')
            ->and($this->store->recorded[1]->changes)->toBe(['body' => ['from' => 'Edited by staff', 'to' => null]]);
    });

    it('records nothing when the author edits or deletes their own', function () {
        $posts = app(PostServiceInterface::class);

        $posts->update($this->post, 'Typo fixed', actor: $this->author);
        $posts->delete($this->post, actor: $this->author);

        expect(Post::withTrashed()->find($this->post->id)->trashed())->toBeTrue()
            ->and($this->store->recorded)->toBe([]);
    });

    it('records through the comment thread screen', function () {
        Livewire::actingAs($this->moderator)
            ->test(CommentThread::class, ['thread' => $this->thread])
            ->set('editingPost', $this->post->id)
            ->set('editingBody', 'Cleaned up')
            ->call('saveEdit')
            ->call('delete', $this->post->id);

        expect(actions($this->store->recorded))->toBe(['parley.post.edited', 'parley.post.deleted'])
            ->and($this->store->recorded[0]->actorId)->toBe($this->moderator->id);
    });
});
