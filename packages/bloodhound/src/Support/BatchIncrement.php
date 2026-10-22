<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Support;

use InvalidArgumentException;

/**
 * Adds many rows' amounts to their stored totals in one statement.
 *
 * The batch's amounts are joined to the target table as an inline table, and
 * each row adds its own amount to its stored value: one indexed join, the
 * increment applied by the database (atomic, as incrementEach() is). Measured
 * against the alternatives at 200k announces (Job #112):
 *
 * - a SELECT and UPDATE per row: 498k queries;
 * - one UPDATE with a CASE branch per row: every row walks every branch,
 *   quadratic in the batch (93s of a 125s SQLite run);
 * - a work table filled and emptied each batch: on PostgreSQL every batch
 *   leaves dead tuples the next one wades through (69s in the UPDATE alone).
 *
 * Two spellings, and this is the one place that knows them:
 *
 * - PostgreSQL and SQLite: WITH v(...) AS (VALUES ...) UPDATE ... FROM v.
 *   PostgreSQL types a bound VALUES list as text, hence the casts; SQLite
 *   accepts them. SQLite needs 3.33 for UPDATE ... FROM.
 * - MySQL and MariaDB: UPDATE ... JOIN (SELECT ... UNION ALL ...). MariaDB has
 *   no WITH before UPDATE and no named VALUES columns.
 */
final class BatchIncrement
{
    private const ALIAS = 'batch_amounts';

    public function __construct(private readonly string $driver)
    {
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)) {
            throw new InvalidArgumentException("Batched increments are not written for the '{$driver}' database driver.");
        }
    }

    /**
     * The statement and its bindings.
     *
     * Identifiers arrive already wrapped for the connection's grammar; the
     * amounts are integers, keyed by the target row's id.
     *
     * @param  list<string>  $wrappedColumns  the columns to add to, wrapped
     * @param  list<string>  $keys  the same columns' keys in $amountsById
     * @param  array<int, array<string, int>>  $amountsById
     * @param  array{string, mixed}|null  $touch  [wrapped column, value] to set alongside
     * @param  list<string>  $wrappedMaxColumns  columns that keep the larger of stored and given
     *                                           (Job #256: last_seen), wrapped. A given 0 means
     *                                           "nothing to say" and never writes.
     * @param  list<string>  $maxKeys  those columns' keys in $amountsById
     * @return array{string, list<mixed>}
     */
    public function statement(string $table, string $id, array $wrappedColumns, array $keys, array $amountsById, ?array $touch = null, array $wrappedMaxColumns = [], array $maxKeys = []): array
    {
        $bindings = [];

        foreach ($amountsById as $rowId => $amounts) {
            $bindings[] = $rowId;

            foreach ([...$keys, ...$maxKeys] as $key) {
                $bindings[] = (int) ($amounts[$key] ?? 0);
            }
        }

        $alias = $this->quote(self::ALIAS);
        $all = [...$wrappedColumns, ...$wrappedMaxColumns];
        $width = count($all) + 1;

        // The larger of the two, where the batch has one to give. CASE rather
        // than GREATEST/MAX, which the engines spell differently.
        $keepLarger = fn (string $column, string $stored) => "CASE WHEN {$alias}.{$column} > 0 AND ({$stored} IS NULL OR {$stored} < {$alias}.{$column}) THEN {$alias}.{$column} ELSE {$stored} END";

        if ($this->driver === 'mysql' || $this->driver === 'mariadb') {
            $first = 'SELECT ? AS '.$id.', '.implode(', ', array_map(fn ($column) => "? AS {$column}", $all));
            $rest = str_repeat(' UNION ALL SELECT '.implode(', ', array_fill(0, $width, '?')), count($amountsById) - 1);

            $sets = array_map(fn ($column) => "{$table}.{$column} = {$table}.{$column} + {$alias}.{$column}", $wrappedColumns);

            foreach ($wrappedMaxColumns as $column) {
                $sets[] = "{$table}.{$column} = ".$keepLarger($column, "{$table}.{$column}");
            }

            if ($touch !== null) {
                $sets[] = "{$table}.{$touch[0]} = ?";
                $bindings[] = $touch[1];
            }

            return [
                "UPDATE {$table} JOIN ({$first}{$rest}) AS {$alias} ON {$table}.{$id} = {$alias}.{$id} SET ".implode(', ', $sets),
                $bindings,
            ];
        }

        $row = '('.implode(', ', array_fill(0, $width, 'CAST(? AS BIGINT)')).')';
        $values = implode(', ', array_fill(0, count($amountsById), $row));

        $sets = array_map(fn ($column) => "{$column} = {$table}.{$column} + {$alias}.{$column}", $wrappedColumns);

        foreach ($wrappedMaxColumns as $column) {
            $sets[] = "{$column} = ".$keepLarger($column, "{$table}.{$column}");
        }

        if ($touch !== null) {
            $sets[] = "{$touch[0]} = ?";
            $bindings[] = $touch[1];
        }

        return [
            "WITH {$alias} ({$id}, ".implode(', ', $all).") AS (VALUES {$values}) "
            ."UPDATE {$table} SET ".implode(', ', $sets)
            ." FROM {$alias} WHERE {$table}.{$id} = {$alias}.{$id}",
            $bindings,
        ];
    }

    private function quote(string $identifier): string
    {
        return $this->driver === 'mysql' || $this->driver === 'mariadb' ? "`{$identifier}`" : "\"{$identifier}\"";
    }
}
