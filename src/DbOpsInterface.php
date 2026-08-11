<?php

declare(strict_types=1);

namespace Ephpm\Mysqli;

/**
 * Backend abstraction over ePHPm's in-process DB bridge.
 *
 * The production implementation ({@see SapiDbOps}) calls the global
 * `ephpm_db_query()` / `ephpm_db_execute()` functions registered by the
 * ePHPm SAPI. Tests use {@see SqliteDbOps} so they can run anywhere
 * without the runtime.
 *
 * Method semantics intentionally mirror the `ephpm_db_*` SAPI surface —
 * the shim adapts those to mysqli shapes, not the other way around:
 *
 * - `?` positional placeholders; parameters may be null, bool, int,
 *   float, or string only.
 * - Errors are reported by throwing a plain {@see \Exception} whose
 *   code is the MySQL errno and whose message has the shape
 *   `SQLSTATE[xxxxx]: <backend message>` (the shim parses that shape,
 *   see {@see Connection}).
 */
interface DbOpsInterface
{
    /**
     * Execute SQL and return the rows as a list of associative arrays
     * keyed by column name.
     *
     * Integer/float columns come back as PHP int/float, NULL as null,
     * text/blob as string. A statement with no result set returns `[]`.
     *
     * @param list<null|bool|int|float|string> $params
     *
     * @return list<array<string, int|float|string|null>>
     *
     * @throws \Exception code = MySQL errno, message `SQLSTATE[xxxxx]: …`
     */
    public function query(string $sql, array $params = []): array;

    /**
     * Execute SQL and return the OK metadata.
     *
     * A statement that produces a result set (e.g. a SELECT routed here)
     * returns zeros rather than throwing.
     *
     * @param list<null|bool|int|float|string> $params
     *
     * @return array{affected_rows: int, last_insert_id: int}
     *
     * @throws \Exception code = MySQL errno, message `SQLSTATE[xxxxx]: …`
     */
    public function execute(string $sql, array $params = []): array;
}
