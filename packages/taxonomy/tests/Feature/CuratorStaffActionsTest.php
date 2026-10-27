<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Marque\Taxonomy\Definitions\Loader;
use Marque\Taxonomy\Exceptions\CurationRefused;
use Marque\Taxonomy\Livewire\TaxonomyAdmin;
use Marque\Taxonomy\Models\FacetValue;
use Marque\Taxonomy\Services\Curator;
use Marque\Taxonomy\Tests\TestUser;
use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Support\StaffAction;

/**
 * Every edit to a catalogue's vocabulary is a staff action (Plan #16): it
 * belongs to the tracker, not to whoever made it. Recorded by Curator, so the
 * admin screen and any other caller are recorded alike.
 */
beforeEach(function () {
    config()->set('taxonomy.definitions.path', __DIR__.'/../fixtures/taxonomies');

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

    $this->admin = TestUser::create(['name' => 'a', 'email' => 'a@example.com', 'password' => 'x', 'role' => 'admin']);
    $this->curator = app(Curator::class);
    $this->nfl = app(Loader::class)->load()['nfl_game'];
});

function recordedActions(array $recorded): array
{
    return array_map(fn (StaffAction $a): string => $a->action, $recorded);
}

it('records adding, relabelling and deleting a term', function () {
    $term = $this->curator->addTerm($this->nfl, 'season', '2006', actor: $this->admin);
    $this->curator->renameTerm($term->id, '2006 season', $this->admin);
    $this->curator->deleteTerm($term->id, $this->admin);

    expect(recordedActions($this->store->recorded))->toBe(['taxonomy.term.added', 'taxonomy.term.renamed', 'taxonomy.term.deleted'])
        ->and($this->store->recorded[0]->subjectId)->toBe($term->id)
        ->and($this->store->recorded[0]->actorId)->toBe($this->admin->id)
        ->and($this->store->recorded[0]->changes)->toBe(['value' => ['from' => null, 'to' => '2006']])
        ->and($this->store->recorded[1]->changes)->toBe(['label' => ['from' => '2006', 'to' => '2006 season']])
        ->and($this->store->recorded[2]->changes)->toBe(['value' => ['from' => '2006', 'to' => null]]);
});

it('records adding and deleting a facet value', function () {
    $value = $this->curator->addFacetValue('resolution', '2160p', $this->admin);
    $this->curator->deleteFacetValue($value->id, $this->admin);

    expect(recordedActions($this->store->recorded))->toBe(['taxonomy.facet_value.added', 'taxonomy.facet_value.deleted'])
        ->and($this->store->recorded[0]->changes)->toBe(['resolution' => ['from' => null, 'to' => '2160p']])
        ->and($this->store->recorded[1]->changes)->toBe(['resolution' => ['from' => '2160p', 'to' => null]]);
});

it('records nothing for a refused edit, or one made with no actor', function () {
    $this->curator->addTerm($this->nfl, 'season', '2007');

    expect(fn () => $this->curator->addTerm($this->nfl, 'season', '2007', actor: $this->admin))->toThrow(CurationRefused::class)
        ->and($this->store->recorded)->toBe([]);
});

it('refuses an actor below admin', function () {
    $member = TestUser::create(['name' => 'm', 'email' => 'm@example.com', 'password' => 'x']);

    $this->curator->addFacetValue('resolution', '480p', $member);
})->throws(AuthorizationException::class);

it('records what the admin screen does', function () {
    Livewire::actingAs($this->admin)
        ->test(TaxonomyAdmin::class)
        ->set('newFacetValue', ['facet' => 'resolution', 'value' => '720p'])
        ->call('addFacetValue')
        ->assertHasNoErrors();

    expect(recordedActions($this->store->recorded))->toBe(['taxonomy.facet_value.added'])
        ->and(FacetValue::query()->where('value', '720p')->exists())->toBeTrue();
});
