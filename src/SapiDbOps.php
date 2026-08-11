<?php

declare(strict_types=1);

namespace Ephpm\Mysqli;

/**
 * Backend that calls the global `ephpm_db_query()` / `ephpm_db_execute()`
 * functions registered by the ePHPm SAPI. Refuses to construct if those
 * functions aren't present so we fail fast outside the runtime instead of
 * producing "Call to undefined function" errors at request time.
 *
 * Requires an ePHPm build from current `main` (the bridge merged in
 * ephpm#257 and is not in any tagged release yet) with `[db.sqlite]`
 * active — without an embedded database the natives exist but throw
 * "no embedded database is active".
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
}
