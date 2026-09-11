<?php

declare(strict_types=1);

namespace Ephpm\Mysqli;

/**
 * Test/development backend that emulates the `ephpm_db_*` bridge with
 * the `sqlite3` extension, so the shim (and code written against it)
 * can run under a stock PHP CLI without the ePHPm runtime.
 *
 * SQLite3 is used (rather than pdo_sqlite) because it can bind native
 * REAL values — `SELECT ?` with a float parameter comes back as a PHP
 * float, matching the real bridge's `param_float`. String parameters
 * mirror the bridge's rule: valid UTF-8 binds as TEXT, anything else as
 * BLOB.
 *
 * This is a best-effort approximation, for tests only:
 *
 * - SQL goes to SQLite directly — no MySQL-dialect translation, no
 *   SHOW/DESCRIBE emulation (litewire provides those in the real bridge).
 * - `SET …` statements are treated as no-ops (the real bridge routes
 *   dialect no-ops like `SET NAMES` to an OK result).
 * - MySQL error codes are sniffed from SQLite messages for the common
 *   cases (1062 duplicate key, 1064 syntax, 1146 no such table, …);
 *   everything else maps to 1105 ER_UNKNOWN_ERROR.
 */
final class SqliteDbOps implements DbOpsInterface
{
    private \SQLite3 $db;

    /**
     * @param \SQLite3|string|null $db an existing SQLite3 handle, a
     *                                 filename, or null for `:memory:`
     */
    public function __construct(\SQLite3|string|null $db = null)
    {
        $this->db = $db instanceof \SQLite3 ? $db : new \SQLite3($db ?? ':memory:');
        $this->db->enableExceptions(true);
    }

    public function query(string $sql, array $params = []): array
    {
        $result = $this->exec($sql, $params);
        if ($result === null || $result->numColumns() === 0) {
            return [];
        }
        $rows = [];
        while (($row = $result->fetchArray(\SQLITE3_ASSOC)) !== false) {
            $rows[] = $row;
        }
        $result->finalize();

        return $rows;
    }

    public function execute(string $sql, array $params = []): array
    {
        $result = $this->exec($sql, $params);
        if ($result === null || $result->numColumns() > 0) {
            // Dialect no-op, or a SELECT routed through execute: zeros,
            // matching the real bridge's documented behavior.
            return ['affected_rows' => 0, 'last_insert_id' => 0];
        }
        $result->finalize();

        return [
            'affected_rows' => $this->db->changes(),
            'last_insert_id' => $this->db->lastInsertRowID(),
        ];
    }

    public function run(string $sql, array $params = []): array
    {
        $result = $this->exec($sql, $params);

        // Dialect no-op (SET ...): an OK outcome with no metadata.
        if ($result === null) {
            return [
                'has_rowset' => false,
                'rows' => [],
                'columns' => [],
                'affected_rows' => 0,
                'last_insert_id' => 0,
            ];
        }

        $ncols = $result->numColumns();
        if ($ncols === 0) {
            $result->finalize();

            return [
                'has_rowset' => false,
                'rows' => [],
                'columns' => [],
                'affected_rows' => $this->db->changes(),
                'last_insert_id' => $this->db->lastInsertRowID(),
            ];
        }

        // Column names are read from the statement before fetching, so they
        // are present even when the result set matched zero rows (issue #262).
        $columns = [];
        for ($c = 0; $c < $ncols; $c++) {
            $columns[] = ['name' => $result->columnName($c), 'type' => null];
        }

        $rows = [];
        while (($row = $result->fetchArray(\SQLITE3_ASSOC)) !== false) {
            $rows[] = $row;
        }
        $result->finalize();

        return [
            'has_rowset' => true,
            'rows' => $rows,
            'columns' => $columns,
            'affected_rows' => 0,
            'last_insert_id' => 0,
        ];
    }

    /**
     * Not emulated by this test backend: returning null keeps the shim on
     * its own keyword-based transaction tracking (the real SapiDbOps answers
     * from `ephpm_db_in_transaction()` when the runtime provides it).
     */
    public function inTransaction(): ?bool
    {
        return null;
    }

    /**
     * @param list<null|bool|int|float|string> $params
     */
    private function exec(string $sql, array $params): ?\SQLite3Result
    {
        if (\preg_match('/^\s*SET\s/i', $sql) === 1) {
            return null; // dialect no-op (SET NAMES, SET sql_mode, ...)
        }

        // Validate parameter types before touching SQLite so the
        // "unsupported type" error is never wrapped by mapError().
        $bindings = [];
        foreach ($params as $p) {
            $bindings[] = match (true) {
                $p === null => [null, \SQLITE3_NULL],
                \is_bool($p) => [(int) $p, \SQLITE3_INTEGER],
                \is_int($p) => [$p, \SQLITE3_INTEGER],
                \is_float($p) => [$p, \SQLITE3_FLOAT],
                // Bridge rule: valid UTF-8 binds as TEXT, else as BLOB.
                \is_string($p) => [$p, \preg_match('//u', $p) === 1 ? \SQLITE3_TEXT : \SQLITE3_BLOB],
                default => throw new \Exception(\sprintf(
                    'ephpm_db: unsupported parameter type %s (only null, bool, '
                    . 'int, float, and string parameters bind)',
                    \get_debug_type($p),
                )),
            };
        }

        try {
            $stmt = $this->db->prepare($sql);
            foreach ($bindings as $i => [$value, $type]) {
                $stmt->bindValue($i + 1, $value, $type);
            }
            $result = $stmt->execute();
            if ($result === false) {
                throw new \Exception($this->db->lastErrorMsg());
            }

            return $result;
        } catch (\Exception $e) {
            throw $this->mapError($e);
        }
    }

    /**
     * Convert a SQLite3 error into the bridge's error shape: a plain
     * Exception, code = (best-effort) MySQL errno, message
     * `SQLSTATE[xxxxx]: <message>`.
     */
    private function mapError(\Exception $e): \Exception
    {
        $last = $this->db->lastErrorMsg();
        $msg = $last !== '' && $last !== 'not an error' ? $last : $e->getMessage();

        [$errno, $state] = match (true) {
            \str_contains($msg, 'UNIQUE constraint failed') => [1062, '23000'],
            \str_contains($msg, 'NOT NULL constraint failed') => [1048, '23000'],
            \str_contains($msg, 'FOREIGN KEY constraint failed') => [1452, '23000'],
            \str_contains($msg, 'no such table') => [1146, '42S02'],
            \str_contains($msg, 'no such column') => [1054, '42S22'],
            \str_contains($msg, 'syntax error') => [1064, '42000'],
            default => [1105, 'HY000'],
        };

        return new \Exception(\sprintf('SQLSTATE[%s]: %s', $state, $msg), $errno);
    }
}
