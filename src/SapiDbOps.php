<?php

declare(strict_types=1);

namespace Ephpm\Mysqli;

/**
 * Backend that calls the global `ephpm_db_query()` / `ephpm_db_execute()`
 * functions registered by the ePHPm SAPI. Refuses to construct if those
 * functions aren't present so we fail fast outside the runtime instead of
 * producing "Call to undefined function" errors at request time.
 *
 * Requires ePHPm v0.6.3 or newer (the release the bridge first shipped in,
 * ephpm#257) with `[db.sqlite]` active — without an embedded database the
 * natives exist but throw "no embedded database is active".
 */
final class SapiDbOps implements DbOpsInterface
{
    public function __construct()
    {
        if (!\function_exists('ephpm_db_query')) {
            throw new \RuntimeException(
                'ephpm DB bridge functions (ephpm_db_query/ephpm_db_execute) '
                . 'are not available. This shim only works inside the ePHPm '
                . 'runtime with [db.sqlite] active; use '
                . 'Ephpm\\Mysqli\\SqliteDbOps in tests.'
            );
        }
    }

    public function query(string $sql, array $params = []): array
    {
        /** @var list<array<string, int|float|string|null>> */
        return \ephpm_db_query($sql, $params);
    }

    public function execute(string $sql, array $params = []): array
    {
        /** @var array{affected_rows: int, last_insert_id: int} */
        return \ephpm_db_execute($sql, $params);
    }

    public function run(string $sql, array $params = []): array
    {
        if (\function_exists('ephpm_db_run')) {
            /** @var array{has_rowset: bool, rows: list<array<string, int|float|string|null>>, columns: list<array{name: string, type: ?string}>, affected_rows: int, last_insert_id: int} */
            return \ephpm_db_run($sql, $params);
        }

        // Floor-preserving fallback for an ePHPm predating ephpm_db_run:
        // route by first keyword and synthesize the unified shape. Column
        // names for a zero-row result come from ephpm_db_columns() when the
        // build has it, else from the first row (the pre-#262 best effort).
        if (SqlHelper::producesRowset($sql)) {
            $rows = $this->query($sql, $params);
            $columns = \function_exists('ephpm_db_columns')
                ? \ephpm_db_columns()
                : self::columnsFromRows($rows);

            return [
                'has_rowset' => true,
                'rows' => $rows,
                'columns' => $columns,
                'affected_rows' => 0,
                'last_insert_id' => 0,
            ];
        }

        $ok = $this->execute($sql, $params);

        return [
            'has_rowset' => false,
            'rows' => [],
            'columns' => [],
            'affected_rows' => (int) ($ok['affected_rows'] ?? 0),
            'last_insert_id' => (int) ($ok['last_insert_id'] ?? 0),
        ];
    }

    public function inTransaction(): ?bool
    {
        return \function_exists('ephpm_db_in_transaction')
            ? \ephpm_db_in_transaction()
            : null;
    }

    /**
     * @param list<array<string, int|float|string|null>> $rows
     *
     * @return list<array{name: string, type: ?string}>
     */
    private static function columnsFromRows(array $rows): array
    {
        if (!isset($rows[0])) {
            return [];
        }

        $columns = [];
        foreach (\array_keys($rows[0]) as $name) {
            $columns[] = ['name' => (string) $name, 'type' => null];
        }

        return $columns;
    }
}
