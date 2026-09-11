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

    /**
     * Execute SQL once and report what it actually did — the unified entry
     * point mirroring the native `ephpm_db_run()` (ePHPm issue #263).
     *
     * `has_rowset` is read from the executed statement, so routing between
     * a rowset and OK metadata is never guessed from the first keyword.
     * `columns` carries the column metadata (name + declared type) even for
     * a zero-row result set (ePHPm issue #262), so `mysqli_result` field
     * metadata works with no rows. `rows` is empty for an OK outcome;
     * `affected_rows`/`last_insert_id` are zero for a result set.
     *
     * @param list<null|bool|int|float|string> $params
     *
     * @return array{has_rowset: bool, rows: list<array<string, int|float|string|null>>, columns: list<array{name: string, type: ?string}>, affected_rows: int, last_insert_id: int}
     *
     * @throws \Exception code = MySQL errno, message `SQLSTATE[xxxxx]: …`
     */
    public function run(string $sql, array $params = []): array;

    /**
     * Whether this thread's bridge session is inside an explicit
     * transaction, from the authoritative native `ephpm_db_in_transaction()`
     * (ePHPm issue #260), or `null` when the backend cannot answer
     * (an older ePHPm, or a test backend that does not emulate it) — in
     * which case the caller keeps its own keyword-based tracking.
     */
    public function inTransaction(): ?bool;
}
