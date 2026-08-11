<?php

declare(strict_types=1);

namespace Ephpm\Mysqli;

/**
 * Small SQL scanner used to (a) count `?` placeholders outside string
 * literals/comments, (b) classify a statement as rowset-producing or not,
 * and (c) track BEGIN/COMMIT/ROLLBACK for autocommit emulation.
 *
 * (b) exists because the bridge exposes two entry points with different
 * return shapes — `ephpm_db_query()` (rows, no OK metadata) and
 * `ephpm_db_execute()` (affected_rows/last_insert_id, no rows) — while
 * `mysqli_query()` is a single entry point. The shim routes by the first
 * significant keyword; see the README ("Statement routing") for the
 * fidelity limits of that approach.
 *
 * @internal
 */
final class SqlHelper
{
    /** First keywords of statements routed to `ephpm_db_query()`. */
    private const ROWSET_KEYWORDS = [
        'SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH', 'VALUES', 'TABLE',
    ];

    private function __construct()
    {
    }

    /**
     * Return the SQL with the contents of string literals, quoted
     * identifiers, and comments removed (quotes kept, comments replaced
     * with a space). Handles '', "", ``, backslash escapes, doubled
     * quotes, `--` and `#` line comments, and C-style block comments.
     */
    public static function strip(string $sql): string
    {
        $out = '';
        $len = \strlen($sql);
        $i = 0;
        while ($i < $len) {
            $c = $sql[$i];
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $out .= $quote;
                $i++;
                while ($i < $len) {
                    $ch = $sql[$i];
                    if ($ch === '\\' && $quote !== '`' && $i + 1 < $len) {
                        $i += 2; // backslash escape inside a MySQL string
                        continue;
                    }
                    if ($ch === $quote) {
                        if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                            $i += 2; // doubled quote
                            continue;
                        }
                        break;
                    }
                    $i++;
                }
                if ($i < $len) {
                    $out .= $quote;
                    $i++;
                }
                continue;
            }
            if ($c === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($c === '#') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($c === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
                $i += 2;
                while ($i + 1 < $len && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $i++;
                }
                $i = \min($i + 2, $len);
                $out .= ' ';
                continue;
            }
            $out .= $c;
            $i++;
        }

        return $out;
    }

    /**
     * Number of `?` placeholders outside literals and comments.
     */
    public static function countPlaceholders(string $sql): int
    {
        return \substr_count(self::strip($sql), '?');
    }

    /**
     * Uppercased first significant keyword (leading whitespace, comments,
     * and opening parentheses skipped), or '' for degenerate input.
     */
    public static function firstKeyword(string $sql): string
    {
        $stripped = \ltrim(self::strip($sql));
        while ($stripped !== '' && $stripped[0] === '(') {
            $stripped = \ltrim(\substr($stripped, 1));
        }
        if (\preg_match('/^[A-Za-z_]+/', $stripped, $m) !== 1) {
            return '';
        }

        return \strtoupper($m[0]);
    }

    /**
     * Whether the statement should be routed to `ephpm_db_query()`
     * (produces a rowset) rather than `ephpm_db_execute()`.
     */
    public static function producesRowset(string $sql): bool
    {
        if (\in_array(self::firstKeyword($sql), self::ROWSET_KEYWORDS, true)) {
            return true;
        }

        // INSERT/UPDATE/DELETE ... RETURNING produces rows.
        return \preg_match('/\bRETURNING\b/i', self::strip($sql)) === 1;
    }

    /**
     * Transaction effect of the statement: 1 opens a transaction,
     * -1 closes one, 0 neither. `ROLLBACK TO [SAVEPOINT] …` is 0.
     */
    public static function txnEffect(string $sql): int
    {
        return match (self::firstKeyword($sql)) {
            'BEGIN', 'START' => 1,
            'COMMIT' => -1,
            'ROLLBACK' => \preg_match('/^\s*ROLLBACK\s+TO\b/i', \ltrim(self::strip($sql))) === 1 ? 0 : -1,
            default => 0,
        };
    }
}
