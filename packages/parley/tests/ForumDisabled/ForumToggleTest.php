<?php

declare(strict_types=1);

use Marque\Parley\Livewire\CommentThread;
use Marque\Parley\Models\Category;
use Marque\Parley\Tests\TestUser;

/**
 * Runs against TestCaseWithForumDisabled — a separate app boot with
 * PARLEY_FORUM off from the start, since route registration happens once at
 * boot and can't be toggled mid-test. See that class's docblock and
 * docs/integration.md-adjacent reasoning: a config toggle that's supposed to
 * remove routes needs its own boot to prove it actually did.
 */
uses(\Marque\Parley\Tests\TestCaseWithForumDisabled::class);

it('genuinely removes the forum routes — 404, not just a hidden link', function () {
    $category = Category::factory()->create();

    expect(fn () => route('parley.forum.categories.index'))
        ->toThrow(\Symfony\Component\Routing\Exception\RouteNotFoundException::class);

    $this->get('/forum')->assertNotFound();
    $this->get('/forum/categories/'.$category->slug)->assertNotFound();
});

it('leaves comments fully functional with the forum switched off', function () {
    // The toggle is forum-only — comments (CommentThread mounted on a
    // subject) must keep working exactly as before, per the checkpoint's own
    // "done when": disabling the toggle removes forum routes while leaving
    // comments untouched.
    $user = TestUser::factory()->create();
    $subject = new class extends \Illuminate\Database\Eloquent\Model {
        protected $table = 'test_subjects';

        protected $guarded = [];
    };
    $subject->save();

    \Livewire\Livewire::actingAs($user)
        ->test(CommentThread::class, ['subject' => $subject])
        ->set('body', 'still works')
        ->call('submit')
        ->assertSee('still works');
});
