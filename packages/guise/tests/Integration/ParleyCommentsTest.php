<?php

declare(strict_types=1);

use Livewire\Livewire;
use Marque\Guise\Tests\TestUser;
use Marque\Parley\Livewire\CommentThread;
use Marque\Parley\Models\Post;
use Marque\Parley\Models\Thread;
use Marque\Trove\Models\Torrent;

/**
 * Torrent (trove) deliberately does NOT use parley's HasThreads trait — see
 * docs/integration.md, "attaching to a model you don't own". These tests
 * read the thread back directly rather than through Torrent::comments() /
 * commentCount(), which Torrent has no reason to expose.
 */
function commentThreadFor(Torrent $torrent): ?Thread
{
    return Thread::comments()
        ->where('threadable_type', $torrent->getMorphClass())
        ->where('threadable_id', $torrent->getKey())
        ->first();
}

function commentCountFor(Torrent $torrent): int
{
    return commentThreadFor($torrent)?->posts()->count() ?? 0;
}

/**
 * Checkpoint 5's "done when": a comment can be posted, edited and deleted on
 * a torrent page in guise, formatted text renders through squidink. This
 * file is that check — it runs against TestCaseWithParley, the one guise
 * test app that actually boots ParleyServiceProvider, so the
 * providerIsLoaded() guard in torrent/show.blade.php takes the "present"
 * branch instead of the "absent" branch every other guise test exercises.
 */
beforeEach(function () {
    $this->user = TestUser::factory()->create();
});

test('the comment thread renders on the torrent show page when parley is installed', function () {
    $torrent = Torrent::create([
        'info_hash' => str_repeat('a', 40),
        'name' => 'Test Torrent',
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('torrents.show', $torrent))
        ->assertOk()
        ->assertSeeLivewire(CommentThread::class)
        ->assertSee('No comments yet');
});

test('a comment posted on a torrent shows up rendered through squidink', function () {
    $torrent = Torrent::create([
        'info_hash' => str_repeat('b', 40),
        'name' => 'Test Torrent',
        'user_id' => $this->user->id,
    ]);

    Livewire::actingAs($this->user)
        ->test(CommentThread::class, ['subject' => $torrent])
        ->set('body', 'looks **great**')
        ->call('submit')
        ->assertSee('<strong>great</strong>', escape: false);

    expect(commentCountFor($torrent))->toBe(1);
});

test('editing and deleting a comment works through the torrent page component', function () {
    $torrent = Torrent::create([
        'info_hash' => str_repeat('c', 40),
        'name' => 'Test Torrent',
        'user_id' => $this->user->id,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(CommentThread::class, ['subject' => $torrent])
        ->set('body', 'first draft')
        ->call('submit');

    $post = commentThreadFor($torrent)->posts()->latest()->firstOrFail();

    $component->call('startEdit', $post->id)
        ->set('editingBody', 'final version')
        ->call('saveEdit')
        ->assertSee('final version');

    $component->call('delete', $post->id);

    expect(Post::find($post->id))->toBeNull();
});
