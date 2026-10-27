<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Livewire;

use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Definitions\Loader;
use Marque\Taxonomy\Exceptions\CurationRefused;
use Marque\Taxonomy\Models\Classification;
use Marque\Taxonomy\Models\Facet;
use Marque\Taxonomy\Models\FacetValue;
use Marque\Taxonomy\Models\InstalledVersion;
use Marque\Taxonomy\Models\Term;
use Marque\Taxonomy\Services\Curator;
use Marque\Taxonomy\Services\Upgrader;
use Marque\Trove\Enums\Role;

/**
 * Where an admin populates a taxonomy — and, deliberately, cannot design one.
 *
 * The Spec's goal was *"the power lives in the package, the admin experience
 * is a form"*. So this screen adds values to levels that a definition already
 * declared, and offers no way whatsoever to add, rename or remove a level.
 *
 * That absence is the design. Hand-built trees produce the 2018 XenForo dump
 * (416 week-nodes at depth 2, 219 at depth 3, "all week 12 games" unwritable);
 * flat lists produce the legacy `categories` table (72 rows, `parent` unused,
 * the season baked into every name). An admin who can only add values cannot
 * reach either failure.
 *
 * Structural change goes through CP5's commands, which report what they will
 * orphan and demand confirmation. This screen *names* those commands rather
 * than reimplementing them: the confirmation is deliberately a command, not a
 * button.
 */
class TaxonomyAdmin extends Component
{
    public string $contentType = '';

    /** @var array<string, mixed> */
    public array $newTerm = ['level' => '', 'value' => '', 'parent' => null];

    /** @var array<string, string> */
    public array $newFacetValue = ['facet' => '', 'value' => ''];

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    /**
     * Editing the shape of a catalogue is an admin action.
     *
     * Checked on mount and again on every write, rather than trusted to
     * whatever route the consumer happens to hang this on — a package cannot
     * assume its host wired middleware correctly.
     */
    protected function authorizeAdmin(): void
    {
        $user = auth()->user();

        if ($user === null || ! method_exists($user, 'hasRoleAtLeast') || ! $user->hasRoleAtLeast(Role::Admin)) {
            throw new AuthorizationException;
        }
    }

    /**
     * @return array<string, ContentType>
     */
    #[Computed]
    public function types(): array
    {
        return app(Loader::class)->load();
    }

    #[Computed]
    public function definition(): ?ContentType
    {
        return $this->types()[$this->contentType] ?? null;
    }

    /**
     * What is installed, from where, at what version — plus any pending
     * upgrade, named as the command that applies it.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function installed(): array
    {
        $pending = collect(app(Upgrader::class)->pending(array_values($this->types())))
            ->keyBy('content_type');

        $rows = [];

        foreach ($this->types() as $name => $type) {
            $rows[] = [
                'name' => $name,
                'label' => $type->label,
                'version' => $type->version,
                'installed' => InstalledVersion::for($name)?->version,
                'levels' => implode(' → ', $type->levelNames()),
                'classified' => Classification::where('content_type', $name)->count(),
                'pending' => $pending->get($name),
            ];
        }

        return $rows;
    }

    /**
     * The terms already declared for the chosen content type, grouped by level.
     *
     * @return array<string, list<Term>>
     */
    #[Computed]
    public function terms(): array
    {
        $type = $this->definition();

        if ($type === null) {
            return [];
        }

        $grouped = [];

        foreach ($type->levels as $level) {
            $grouped[$level->name] = Term::query()
                ->where('content_type', $type->name)
                ->where('level', $level->name)
                ->orderBy('value')
                ->get()
                ->all();
        }

        return $grouped;
    }

    /**
     * Every facet any definition declares, with its current values.
     *
     * Vocabularies are shared across content types, so this is not scoped to
     * the chosen one.
     *
     * @return array<string, list<FacetValue>>
     */
    #[Computed]
    public function vocabularies(): array
    {
        $declared = [];

        foreach ($this->types() as $type) {
            foreach ($type->facets as $facet) {
                $declared[$facet] = true;
            }
        }

        $vocabularies = [];

        foreach (array_keys($declared) as $name) {
            $vocabularies[$name] = Facet::query()
                ->where('name', $name)
                ->first()
                ?->values()
                ->orderBy('value')
                ->get()
                ->all() ?? [];
        }

        return $vocabularies;
    }

    public function addTerm(Curator $curator): void
    {
        $this->authorizeAdmin();

        $type = $this->definition();

        if ($type === null) {
            $this->addError('contentType', 'Choose a content type first.');

            return;
        }

        $parent = $this->newTerm['parent'] ?? null;

        $done = $this->curate(fn () => $curator->addTerm(
            $type,
            (string) ($this->newTerm['level'] ?? ''),
            (string) ($this->newTerm['value'] ?? ''),
            $parent === null || $parent === '' ? null : (int) $parent,
            auth()->user(),
        ));

        if ($done) {
            $this->newTerm = ['level' => '', 'value' => '', 'parent' => null];
        }

        unset($this->terms);
    }

    /**
     * Change a term's display label. Only the label: see Curator::renameTerm().
     */
    public function renameTerm(int $id, string $label, Curator $curator): void
    {
        $this->authorizeAdmin();

        $this->curate(fn () => $curator->renameTerm($id, $label, auth()->user()));

        unset($this->terms);
    }

    /**
     * Delete a term nothing depends on. See Curator::deleteTerm().
     */
    public function deleteTerm(int $id, Curator $curator): void
    {
        $this->authorizeAdmin();

        $this->curate(fn () => $curator->deleteTerm($id, auth()->user()));

        unset($this->terms);
    }

    public function addFacetValue(Curator $curator): void
    {
        $this->authorizeAdmin();

        $done = $this->curate(fn () => $curator->addFacetValue(
            (string) ($this->newFacetValue['facet'] ?? ''),
            (string) ($this->newFacetValue['value'] ?? ''),
            auth()->user(),
        ));

        if ($done) {
            $this->newFacetValue = ['facet' => '', 'value' => ''];
        }

        unset($this->vocabularies);
    }

    public function deleteFacetValue(int $id, Curator $curator): void
    {
        $this->authorizeAdmin();

        $this->curate(fn () => $curator->deleteFacetValue($id, auth()->user()));

        unset($this->vocabularies);
    }

    /**
     * Run an edit, putting a refusal on the field it's about.
     */
    private function curate(callable $edit): bool
    {
        try {
            $edit();
        } catch (CurationRefused $refused) {
            $this->addError($refused->field, $refused->getMessage());

            return false;
        }

        return true;
    }

    public function render()
    {
        return view('taxonomy::livewire.taxonomy-admin');
    }
}
