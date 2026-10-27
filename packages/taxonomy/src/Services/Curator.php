<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Definitions\Loader;
use Marque\Taxonomy\Exceptions\CurationRefused;
use Marque\Taxonomy\Models\Classification;
use Marque\Taxonomy\Models\Facet;
use Marque\Taxonomy\Models\FacetValue;
use Marque\Taxonomy\Models\Term;
use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Enums\Role;
use Marque\Trove\Support\StaffAction;

/**
 * Populating a catalogue's declared levels and shared vocabularies: adding,
 * relabelling and removing terms and facet values.
 *
 * Never structure. Levels and facets come from the definition, so nothing here
 * creates a level or a vocabulary, and nothing removes a term or value that
 * something still depends on.
 *
 * Pass the acting admin as `actor`: it must be an admin, and the edit is
 * recorded as a staff action. These edits live here rather than in the admin
 * screen so they're recorded wherever they're made from.
 */
class Curator
{
    public function __construct(
        private readonly Loader $loader,
        private readonly StaffActionsInterface $staffActions,
    ) {}

    /**
     * @throws CurationRefused
     */
    public function addTerm(ContentType $type, string $levelName, string $value, ?int $parentId = null, ?Authenticatable $actor = null): Term
    {
        $this->authorize($actor);

        $level = $type->level($levelName);

        // The guarantee, enforced rather than assumed: a level the definition
        // does not declare cannot be populated, however the request arrived.
        if ($level === null) {
            throw new CurationRefused('newTerm.level', sprintf(
                '"%s" is not a level of %s. Levels are declared in the definition, not added here.',
                $levelName,
                $type->label,
            ));
        }

        $value = trim($value);

        if (! $level->accepts($value)) {
            throw new CurationRefused('newTerm.value', sprintf('%s does not accept "%s".', $level->label, $value));
        }

        $parent = $parentId === null ? null : Term::find($parentId);

        $exists = Term::query()
            ->where('content_type', $type->name)
            ->where('level', $level->name)
            ->where('parent_key', $parent?->getKey() ?? 0)
            ->where('value', $value)
            ->exists();

        if ($exists) {
            throw new CurationRefused('newTerm.value', sprintf('"%s" is already there.', $value));
        }

        return DB::transaction(function () use ($type, $level, $value, $parent, $actor): Term {
            $term = Term::create([
                'content_type' => $type->name,
                'level' => $level->name,
                'value' => $value,
                'label' => $value,
                'parent_id' => $parent?->getKey(),
                'parent_key' => $parent?->getKey() ?? 0,
                'path' => $parent?->pathForChildren() ?? '/',
            ]);

            $this->record($actor, 'taxonomy.term.added', $term, ['value' => ['from' => null, 'to' => $value]]);

            return $term;
        });
    }

    /**
     * Change a term's display label.
     *
     * Only the label: the `value` is what classifications resolve against, so
     * changing it would be a structural edit wearing a cosmetic hat.
     *
     * @throws CurationRefused
     */
    public function renameTerm(int $id, string $label, ?Authenticatable $actor = null): Term
    {
        $this->authorize($actor);

        $term = Term::find($id) ?? throw new CurationRefused('terms', 'That term no longer exists.');

        return DB::transaction(function () use ($term, $label, $actor): Term {
            $from = $term->label;
            $term->update(['label' => trim($label)]);

            if ($from !== $term->label) {
                $this->record($actor, 'taxonomy.term.renamed', $term, ['label' => ['from' => $from, 'to' => $term->label]]);
            }

            return $term;
        });
    }

    /**
     * Delete a term, but only one nothing depends on.
     *
     * Anything with children or classifications is refused and pointed at the
     * definition machinery instead. This never silently orphans.
     *
     * @throws CurationRefused
     */
    public function deleteTerm(int $id, ?Authenticatable $actor = null): void
    {
        $this->authorize($actor);

        $term = Term::find($id) ?? throw new CurationRefused('terms', 'That term no longer exists.');

        $children = Term::where('parent_id', $term->getKey())->count();

        if ($children > 0) {
            throw new CurationRefused('terms', sprintf(
                '"%s" has %d term(s) beneath it. Delete those first — this screen will not orphan them.',
                $term->label ?? $term->value,
                $children,
            ));
        }

        $classified = Classification::where('term_id', $term->getKey())->count();

        if ($classified > 0) {
            throw new CurationRefused('terms', sprintf(
                '"%s" has %d torrent(s) classified under it. Reclassify them first, or change the definition and run marque:taxonomy:upgrade — that path reports what it will orphan before it does anything.',
                $term->label ?? $term->value,
                $classified,
            ));
        }

        DB::transaction(function () use ($term, $actor): void {
            $term->delete();

            $this->record($actor, 'taxonomy.term.deleted', $term, ['value' => ['from' => $term->value, 'to' => null]]);
        });
    }

    /**
     * @throws CurationRefused
     */
    public function addFacetValue(string $facet, string $value, ?Authenticatable $actor = null): FacetValue
    {
        $this->authorize($actor);

        $name = trim($facet);
        $value = trim($value);

        // A vocabulary no definition declares would be structure invented
        // here, which is the same thing as inventing a level.
        if (! in_array($name, $this->declaredFacets(), true)) {
            throw new CurationRefused('newFacetValue.facet', sprintf('No definition declares a "%s" facet.', $name));
        }

        if ($value === '') {
            throw new CurationRefused('newFacetValue.value', 'Give the value a name.');
        }

        return DB::transaction(function () use ($name, $value, $actor): FacetValue {
            $facet = Facet::firstOrCreate(
                ['name' => $name],
                ['label' => ucfirst(str_replace('_', ' ', $name))],
            );

            if ($facet->values()->where('value', $value)->exists()) {
                throw new CurationRefused('newFacetValue.value', sprintf('"%s" is already in %s.', $value, $name));
            }

            $facetValue = FacetValue::create([
                'facet_id' => $facet->getKey(),
                'value' => $value,
                'label' => $value,
            ]);

            $this->record($actor, 'taxonomy.facet_value.added', $facetValue, [$name => ['from' => null, 'to' => $value]]);

            return $facetValue;
        });
    }

    /**
     * @throws CurationRefused
     */
    public function deleteFacetValue(int $id, ?Authenticatable $actor = null): void
    {
        $this->authorize($actor);

        $value = FacetValue::find($id) ?? throw new CurationRefused('facets', 'That value no longer exists.');

        // The schema would cascade the assignments away. That is right for a
        // definition-driven removal with a reported count behind it (CP5), and
        // wrong for a click on an admin screen.
        $assigned = $value->torrentAssignmentCount();

        if ($assigned > 0) {
            throw new CurationRefused('facets', sprintf(
                '"%s" is on %d torrent(s). Removing it here would strip it from all of them.',
                $value->value,
                $assigned,
            ));
        }

        DB::transaction(function () use ($value, $actor): void {
            $facet = $value->facet?->name ?? 'value';
            $value->delete();

            $this->record($actor, 'taxonomy.facet_value.deleted', $value, [$facet => ['from' => $value->value, 'to' => null]]);
        });
    }

    /**
     * Every facet name any definition declares.
     *
     * @return list<string>
     */
    public function declaredFacets(): array
    {
        $declared = [];

        foreach ($this->loader->load() as $type) {
            foreach ($type->facets as $facet) {
                $declared[$facet] = true;
            }
        }

        return array_keys($declared);
    }

    /**
     * Editing the shape of a catalogue is an admin action, checked against
     * whoever acts rather than trusted to the route they came in by.
     */
    private function authorize(?Authenticatable $actor): void
    {
        if ($actor !== null && (! method_exists($actor, 'hasRoleAtLeast') || ! $actor->hasRoleAtLeast(Role::Admin))) {
            throw new AuthorizationException;
        }
    }

    /**
     * Recorded whenever an admin acts: the catalogue's vocabulary belongs to
     * the tracker, so every edit to it is a staff action.
     *
     * @param  array<string, array{from: scalar|null, to: scalar|null}>  $changes
     */
    private function record(?Authenticatable $actor, string $action, Model $subject, array $changes): void
    {
        if ($actor !== null) {
            $this->staffActions->record(StaffAction::of($actor, $action, $subject, changes: $changes));
        }
    }
}
