<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Definitions;

/**
 * Strict validation of a raw definition array, before it ever becomes a
 * ContentType.
 *
 * Strictness is v1, resolved with Dan: *"as it's being used as code it would
 * need to be fairly stringent."* A definition is executable configuration —
 * it drives the upload form, the validation and the query builder — so a typo
 * that loads is a typo that breaks a catalogue quietly, which is worse than a
 * refusal.
 *
 * Every problem is collected rather than stopping at the first. An admin
 * fixing one error per run is an admin running the validator five times.
 *
 * Deferred deliberately (ergonomics, not load-bearing): "did you mean
 * 'resolution'?" suggestions and unused-vocabulary warnings.
 */
final class Validator
{
    /**
     * An identifier used as a database scoping key and a form field name.
     * Lowercase, digits and underscores — nothing that needs quoting or
     * escaping downstream.
     */
    private const IDENTIFIER = '/^[a-z][a-z0-9_]*$/';

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string> Every problem found; empty means valid.
     */
    public function validate(array $definition): array
    {
        $errors = [];

        $this->validateName($definition, $errors);
        $this->validateVersion($definition, $errors);
        $this->validateLevels($definition, $errors);
        $this->validateFacets($definition, $errors);

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $errors
     */
    private function validateName(array $definition, array &$errors): void
    {
        $name = $definition['content_type'] ?? null;

        if (! is_string($name) || $name === '') {
            $errors[] = 'Missing required key: content_type.';

            return;
        }

        if (preg_match(self::IDENTIFIER, $name) !== 1) {
            $errors[] = sprintf(
                'Invalid content_type "%s": must start with a letter and contain only lowercase letters, digits and underscores.',
                $name,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $errors
     */
    private function validateVersion(array $definition, array &$errors): void
    {
        if (! array_key_exists('version', $definition)) {
            return;
        }

        $version = $definition['version'];

        // Deliberately int-only: CP5's upgrade machinery compares versions to
        // decide whether a live catalogue needs reshaping, and "2.0" or
        // "draft" makes that comparison meaningless.
        if (! is_int($version) || $version < 1) {
            $errors[] = sprintf(
                'Invalid version "%s": must be a positive integer.',
                is_scalar($version) ? (string) $version : gettype($version),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $errors
     */
    private function validateLevels(array $definition, array &$errors): void
    {
        $levels = $definition['levels'] ?? null;

        if (! is_array($levels) || $levels === []) {
            $errors[] = 'A content type must declare at least one level.';

            return;
        }

        $seen = [];

        foreach ($levels as $entry) {
            if (! is_array($entry)) {
                $errors[] = 'Each level must be a single-key map, e.g. "- season: { type: year }".';

                continue;
            }

            foreach ($entry as $name => $declaration) {
                $name = (string) $name;

                if (preg_match(self::IDENTIFIER, $name) !== 1) {
                    $errors[] = sprintf(
                        'Invalid level name "%s": must start with a letter and contain only lowercase letters, digits and underscores.',
                        $name,
                    );
                }

                if (isset($seen[$name])) {
                    $errors[] = sprintf('Duplicate level "%s" in this content type.', $name);
                }

                $seen[$name] = true;

                $this->validateLevelDeclaration($name, is_array($declaration) ? $declaration : [], $errors);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $declaration
     * @param  list<string>  $errors
     */
    private function validateLevelDeclaration(string $name, array $declaration, array &$errors): void
    {
        $type = $declaration['type'] ?? 'string';

        if (! is_string($type) || ! in_array($type, Level::TYPES, true)) {
            $errors[] = sprintf(
                'Unknown type "%s" on level "%s": expected one of %s.',
                is_scalar($type) ? (string) $type : gettype($type),
                $name,
                implode(', ', Level::TYPES),
            );

            // The range check below reads the type, so stop here rather than
            // reporting a second, confusing error about a level whose type is
            // already known to be wrong.
            return;
        }

        if (! array_key_exists('range', $declaration)) {
            return;
        }

        $range = $declaration['range'];

        if (! in_array($type, Level::RANGEABLE, true)) {
            $errors[] = sprintf(
                'Level "%s" declares a range, which is not meaningful for type "%s".',
                $name,
                $type,
            );

            return;
        }

        if (! is_array($range) || count($range) !== 2 || ! is_numeric($range[0] ?? null) || ! is_numeric($range[1] ?? null)) {
            $errors[] = sprintf('Invalid range on level "%s": expected two numbers, e.g. [1, 22].', $name);

            return;
        }

        if ((int) $range[0] > (int) $range[1]) {
            $errors[] = sprintf('Invalid range on level "%s": %s is greater than %s.', $name, (string) $range[0], (string) $range[1]);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $errors
     */
    private function validateFacets(array $definition, array &$errors): void
    {
        $facets = $definition['facets'] ?? [];

        if (! is_array($facets)) {
            $errors[] = 'facets must be a list of vocabulary names.';

            return;
        }

        $seen = [];

        foreach ($facets as $facet) {
            if (! is_string($facet)) {
                $errors[] = 'Each facet must be a vocabulary name.';

                continue;
            }

            if (preg_match(self::IDENTIFIER, $facet) !== 1) {
                $errors[] = sprintf('Invalid facet name "%s".', $facet);
            }

            if (isset($seen[$facet])) {
                $errors[] = sprintf('Duplicate facet "%s" in this content type.', $facet);
            }

            $seen[$facet] = true;
        }
    }
}
