<?php

declare(strict_types=1);

namespace Ephpm\Mysqli;

/**
 * Stand-in for `mysqli_stmt`, produced by {@see Connection::prepare()}.
 *
 * The bridge has no server-side prepare, so the SQL is only scanned for
 * `?` placeholders here — syntax errors surface at {@see execute()}.
 * Rowsets are always fully buffered on execute, which is why
 * `store_result()` is a no-op and `num_rows` works without one.
 *
 * @property-read int $affected_rows
 * @property-read int $insert_id
 * @property-read int $num_rows
 * @property-read int $param_count
 * @property-read int $field_count
 * @property-read int $errno
 * @property-read string $error
 * @property-read list<array{errno: int, sqlstate: string, error: string}> $error_list
 * @property-read string $sqlstate
 */
class Statement
{
    private readonly int $paramCount;

    private string $types = '';

    /** @var array<int, mixed> references bound via bind_param() */
    private array $boundParams = [];

    /** @var array<int, mixed> references bound via bind_result() */
    private array $boundResults = [];

    private bool $hasBoundResults = false;

    /** @var list<array<string, int|float|string|null>>|null rowset from the last execute, null if none */
    private ?array $rows = null;

    /** @var list<string> column names of the last rowset, in select order (issue #262) */
    private array $columnNames = [];

    private bool $resultConsumed = false;

    private int $cursor = 0;

    private int $affectedRows = 0;

    private int $insertId = 0;

    private int $errno = 0;

    private string $error = '';

    private string $sqlstate = '00000';

    /** @var list<array{errno: int, sqlstate: string, error: string}> */
    private array $errorList = [];

    private bool $closed = false;

    public function __construct(private readonly Connection $conn, private readonly string $sql)
    {
        $this->paramCount = SqlHelper::countPlaceholders($sql);
    }

    // ── Binding ────────────────────────────────────────────────────────

    /**
     * Bind variables by reference. `$types` is the usual i/d/s/b string;
     * values are read (and coerced per their type character) at
     * execute() time, so rebinding is not needed between executions.
     */
    public function bind_param(string $types, mixed &...$vars): bool
    {
        $this->checkOpen();
        if (\strlen($types) !== \count($vars)) {
            throw new \ArgumentCountError(
                'The number of elements in the type definition string must match '
                . 'the number of bind variables'
            );
        }
        if (\count($vars) !== $this->paramCount) {
            throw new \ArgumentCountError(
                'The number of variables must match the number of parameters in the prepared statement'
            );
        }
        if (\preg_match('/^[idsb]*$/', $types) !== 1) {
            throw new \ValueError(
                'bind_param(): Argument #1 ($types) must only contain the "b", "d", "i", "s" type specifiers'
            );
        }
        $this->types = $types;
        $this->boundParams = [];
        foreach ($vars as $i => &$var) {
            $this->boundParams[$i] = &$var;
        }

        return true;
    }

    /**
     * Bind output variables by reference for {@see fetch()}. Values are
     * assigned in column order.
     */
    public function bind_result(mixed &...$vars): bool
    {
        $this->checkOpen();
        $this->boundResults = [];
        foreach ($vars as $i => &$var) {
            $this->boundResults[$i] = &$var;
        }
        $this->hasBoundResults = true;

        return true;
    }

    // ── Execution ──────────────────────────────────────────────────────

    /**
     * Execute with the variables bound via {@see bind_param()}, or with
     * `$params` (PHP 8.1+ style) — in which case, matching the real
     * mysqli, non-null values are sent as strings.
     */
    public function execute(?array $params = null): bool
    {
        $this->checkOpen();
        $this->conn->checkOpen();
        $this->resetErrorState();
        $this->conn->resetErrorState();
        $this->rows = null;
        $this->columnNames = [];
        $this->resultConsumed = false;
        $this->cursor = 0;

        if ($params !== null) {
            if (\count($params) !== $this->paramCount) {
                throw new \ArgumentCountError(
                    'The number of variables must match the number of parameters in the prepared statement'
                );
            }
            $bound = \array_map(
                static fn ($v) => $v === null ? null : (string) $v,
                \array_values($params)
            );
        } else {
            if ($this->paramCount > 0 && \count($this->boundParams) !== $this->paramCount) {
                throw new \ArgumentCountError(
                    'The number of variables must match the number of parameters in the prepared statement'
                );
            }
            $bound = [];
            foreach ($this->boundParams as $i => $var) {
                $bound[] = self::coerce($this->types[$i], $var);
            }
        }

        try {
            // Unified entry point: has_rowset is read from the executed
            // statement, so no first-keyword classification (issue #263), and
            // the column names survive a zero-row result (issue #262).
            $result = $this->conn->runBridge($this->sql, $bound);
            if ($result['has_rowset']) {
                $this->rows = $result['rows'];
                $this->columnNames = \array_column($result['columns'], 'name');
                // Buffered rowset: affected_rows mirrors num_rows, like
                // mysqlnd after store_result. insert_id resets to 0.
                $this->affectedRows = \count($this->rows);
                $this->insertId = 0;
            } else {
                $this->affectedRows = $result['affected_rows'];
                $this->insertId = $result['last_insert_id'];
            }
        } catch (SqlException $e) {
            $this->recordError($e);

            return $this->conn->handleError($e);
        }

        $this->conn->recordStatementResult($this->affectedRows, $this->insertId, $this->fieldCountValue());

        return true;
    }

    /**
     * The buffered rowset as a {@see Result}, or false when the last
     * execute produced no rowset (or the rowset was already consumed by
     * a previous get_result() call).
     */
    public function get_result(): Result|false
    {
        $this->checkOpen();
        if ($this->rows === null || $this->resultConsumed) {
            return false;
        }
        $this->resultConsumed = true;

        return new Result($this->rows, $this->columnNames);
    }

    /**
     * Fetch the next row into the variables bound via
     * {@see bind_result()}. Returns true on success, null when there are
     * no more rows (or no rowset).
     */
    public function fetch(): ?bool
    {
        $this->checkOpen();
        if ($this->rows === null || !isset($this->rows[$this->cursor])) {
            return null;
        }
        if (!$this->hasBoundResults) {
            return null;
        }
        $values = \array_values($this->rows[$this->cursor++]);
        foreach ($this->boundResults as $i => &$var) {
            $var = $values[$i] ?? null;
        }
        unset($var);

        return true;
    }

    /** Rowsets are always buffered; this is a no-op returning true. */
    public function store_result(): bool
    {
        $this->checkOpen();

        return true;
    }

    public function free_result(): void
    {
        $this->rows = null;
        $this->resultConsumed = false;
        $this->cursor = 0;
    }

    public function close(): bool
    {
        $this->closed = true;
        $this->rows = null;

        return true;
    }

    // ── Not implemented ────────────────────────────────────────────────

    public function reset(): bool
    {
        throw NotImplementedException::for('mysqli_stmt::reset()');
    }

    public function send_long_data(int $param_index, string $data): bool
    {
        throw NotImplementedException::for(
            'mysqli_stmt::send_long_data()',
            'bind the full blob value with bind_param()'
        );
    }

    public function result_metadata(): Result|false
    {
        throw NotImplementedException::for(
            'mysqli_stmt::result_metadata()',
            'use get_result() and its fetch_fields()'
        );
    }

    public function attr_set(int $attribute, int $value): bool
    {
        throw NotImplementedException::for('mysqli_stmt::attr_set()');
    }

    public function attr_get(int $attribute): int
    {
        throw NotImplementedException::for('mysqli_stmt::attr_get()');
    }

    // ── Magic properties ───────────────────────────────────────────────

    public function __get(string $name): mixed
    {
        return match ($name) {
            'affected_rows' => $this->affectedRows,
            'insert_id' => $this->insertId,
            'num_rows' => $this->rows === null ? 0 : \count($this->rows),
            'param_count' => $this->paramCount,
            'field_count' => $this->fieldCountValue(),
            'errno' => $this->errno,
            'error' => $this->error,
            'error_list' => $this->errorList,
            'sqlstate' => $this->sqlstate,
            default => \trigger_error(
                \sprintf('Undefined property: %s::$%s', static::class, $name),
                \E_USER_WARNING
            ) ? null : null,
        };
    }

    public function __isset(string $name): bool
    {
        return \in_array($name, [
            'affected_rows', 'insert_id', 'num_rows', 'param_count',
            'field_count', 'errno', 'error', 'error_list', 'sqlstate',
        ], true);
    }

    // ── Internals ──────────────────────────────────────────────────────

    private function fieldCountValue(): int
    {
        // Column count comes from the executed statement's metadata, so a
        // zero-row rowset still reports its field count (issue #262).
        if ($this->rows === null) {
            return 0;
        }

        return \count($this->columnNames);
    }

    private function resetErrorState(): void
    {
        $this->errno = 0;
        $this->error = '';
        $this->sqlstate = '00000';
        $this->errorList = [];
    }

    private function recordError(SqlException $e): void
    {
        $this->errno = (int) $e->getCode();
        $this->error = $e->getMessage();
        $this->sqlstate = $e->getSqlState();
        $this->errorList = [[
            'errno' => $this->errno,
            'sqlstate' => $this->sqlstate,
            'error' => $this->error,
        ]];
    }

    private static function coerce(string $type, mixed $value): int|float|string|null
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'i' => (int) $value,
            'd' => (float) $value,
            default => (string) $value, // 's' and 'b'
        };
    }

    private function checkOpen(): void
    {
        if ($this->closed) {
            throw new \Error('mysqli_stmt object is already closed');
        }
    }
}
