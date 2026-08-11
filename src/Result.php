<?php

declare(strict_types=1);

namespace Ephpm\Mysqli;

/**
 * Stand-in for `mysqli_result`, wrapping a fully buffered rowset from the
 * bridge (a list of associative rows with native int/float/string/null
 * values).
 *
 * Field metadata fidelity limit: the bridge returns rows only, so
 * `fetch_field()`/`fetch_fields()` are best-effort — `name`/`orgname` are
 * always correct; `type`, `flags`, `charsetnr`, and `decimals` are
 * inferred from the PHP value types in the rowset (int → LONGLONG,
 * float → DOUBLE, string → VAR_STRING, all-null → NULL); `table`,
 * `orgtable`, `db`, and `length` are unknown and reported empty/zero.
 * A zero-row rowset has no column names at all (`field_count` is 0) —
 * see the README.
 *
 * @property-read int $num_rows
 * @property-read int $field_count
 * @property-read int $current_field
 * @property-read list<int>|null $lengths
 *
 * @implements \IteratorAggregate<int, array<string, int|float|string|null>>
 */
class Result implements \IteratorAggregate
{
    private int $cursor = 0;

    private int $currentField = 0;

    private bool $freed = false;

    /** @var array<string, int|float|string|null>|null */
    private ?array $lastRow = null;

    /**
     * @param list<array<string, int|float|string|null>> $rows
     */
    public function __construct(private array $rows)
    {
    }

    // ── Row fetching ───────────────────────────────────────────────────

    /** @return array<string, int|float|string|null>|null */
    public function fetch_assoc(): ?array
    {
        $this->checkOpen();
        if (!isset($this->rows[$this->cursor])) {
            return null;
        }

        return $this->lastRow = $this->rows[$this->cursor++];
    }

    /** @return list<int|float|string|null>|null */
    public function fetch_row(): ?array
    {
        $row = $this->fetch_assoc();

        return $row === null ? null : \array_values($row);
    }

    /**
     * @return array<int|string, int|float|string|null>|null
     */
    public function fetch_array(int $mode = Protocol::BOTH): ?array
    {
        $row = $this->fetch_assoc();
        if ($row === null) {
            return null;
        }

        return self::shapeRow($row, $mode);
    }

    /**
     * Fetch the next row as an object. For classes other than stdClass
     * the shim instantiates the class (with `$constructor_args`) and
     * then assigns each column as a property — a simplification of the
     * real mysqli's populate-before-constructor behavior.
     */
    public function fetch_object(string $class = 'stdClass', array $constructor_args = []): object|false|null
    {
        $row = $this->fetch_assoc();
        if ($row === null) {
            return null;
        }
        if ($class === 'stdClass' || $class === \stdClass::class) {
            return (object) $row;
        }
        $ref = new \ReflectionClass($class);
        $obj = $constructor_args === [] ? $ref->newInstance() : $ref->newInstanceArgs($constructor_args);
        foreach ($row as $k => $v) {
            $obj->{$k} = $v;
        }

        return $obj;
    }

    /**
     * Fetch all remaining rows. NOTE: the real mysqli default is
     * MYSQLI_NUM, matched here.
     *
     * @return list<array<int|string, int|float|string|null>>
     */
    public function fetch_all(int $mode = Protocol::NUM): array
    {
        $this->checkOpen();
        $out = [];
        while (isset($this->rows[$this->cursor])) {
            $row = $this->rows[$this->cursor++];
            $this->lastRow = $row;
            $out[] = self::shapeRow($row, $mode);
        }

        return $out;
    }

    /**
     * Fetch a single column from the next row, or false when there are
     * no more rows.
     */
    public function fetch_column(int $column = 0): int|float|string|null|false
    {
        $row = $this->fetch_row();
        if ($row === null) {
            return false;
        }

        return $row[$column] ?? false;
    }

    public function data_seek(int $offset): bool
    {
        $this->checkOpen();
        if ($offset < 0 || $offset >= \count($this->rows)) {
            return false;
        }
        $this->cursor = $offset;

        return true;
    }

    /** @return \Generator<int, array<string, int|float|string|null>> */
    public function getIterator(): \Generator
    {
        $this->checkOpen();
        yield from $this->rows;
    }

    // ── Field metadata (best effort — see class docblock) ─────────────

    /** @return object|false the next field's metadata, false when exhausted */
    public function fetch_field(): object|false
    {
        $this->checkOpen();
        $info = $this->fieldInfo($this->currentField);
        if ($info === false) {
            return false;
        }
        $this->currentField++;

        return $info;
    }

    /** @return list<object> */
    public function fetch_fields(): array
    {
        $this->checkOpen();
        $out = [];
        for ($i = 0; ($info = $this->fieldInfo($i)) !== false; $i++) {
            $out[] = $info;
        }

        return $out;
    }

    public function fetch_field_direct(int $index): object|false
    {
        $this->checkOpen();

        return $this->fieldInfo($index);
    }

    public function field_seek(int $index): bool
    {
        $this->checkOpen();
        if ($index < 0 || $index >= $this->fieldCount()) {
            return false;
        }
        $this->currentField = $index;

        return true;
    }

    // ── Lifecycle ──────────────────────────────────────────────────────

    public function free(): void
    {
        $this->freed = true;
        $this->rows = [];
    }

    public function close(): void
    {
        $this->free();
    }

    public function free_result(): void
    {
        $this->free();
    }

    // ── Magic properties ───────────────────────────────────────────────

    public function __get(string $name): mixed
    {
        $this->checkOpen();

        return match ($name) {
            'num_rows' => \count($this->rows),
            'field_count' => $this->fieldCount(),
            'current_field' => $this->currentField,
            'lengths' => $this->lastRow === null
                ? null
                : \array_map(
                    static fn ($v): int => $v === null ? 0 : \strlen((string) $v),
                    \array_values($this->lastRow)
                ),
            default => \trigger_error(
                \sprintf('Undefined property: %s::$%s', static::class, $name),
                \E_USER_WARNING
            ) ? null : null,
        };
    }

    public function __isset(string $name): bool
    {
        return \in_array($name, ['num_rows', 'field_count', 'current_field', 'lengths'], true);
    }

    // ── Internals ──────────────────────────────────────────────────────

    /**
     * All buffered rows, independent of the cursor.
     *
     * @internal used by {@see Connection::real_query()}
     *
     * @return list<array<string, int|float|string|null>>
     */
    public function allRows(): array
    {
        return $this->rows;
    }

    private function fieldCount(): int
    {
        return $this->rows === [] ? 0 : \count($this->rows[0]);
    }

    /** @return list<string> */
    private function columnNames(): array
    {
        return $this->rows === [] ? [] : \array_map(\strval(...), \array_keys($this->rows[0]));
    }

    private function fieldInfo(int $index): object|false
    {
        $names = $this->columnNames();
        if (!isset($names[$index])) {
            return false;
        }
        $name = $names[$index];

        $sawInt = false;
        $sawFloat = false;
        $sawString = false;
        $maxLength = 0;
        foreach ($this->rows as $row) {
            $v = $row[$name] ?? null;
            if ($v === null) {
                continue;
            }
            $sawInt = $sawInt || \is_int($v);
            $sawFloat = $sawFloat || \is_float($v);
            $sawString = $sawString || \is_string($v);
            $maxLength = \max($maxLength, \strlen((string) $v));
        }

        [$type, $charsetnr, $flags, $decimals] = match (true) {
            $sawString => [Protocol::TYPE_VAR_STRING, Protocol::CHARSETNR_UTF8MB4, 0, 0],
            $sawFloat => [Protocol::TYPE_DOUBLE, Protocol::CHARSETNR_BINARY, Protocol::NUM_FLAG, 31],
            $sawInt => [Protocol::TYPE_LONGLONG, Protocol::CHARSETNR_BINARY, Protocol::NUM_FLAG, 0],
            default => [Protocol::TYPE_NULL, Protocol::CHARSETNR_BINARY, 0, 0],
        };

        return (object) [
            'name' => $name,
            'orgname' => $name,
            'table' => '',
            'orgtable' => '',
            'def' => '',
            'db' => '',
            'catalog' => 'def',
            'max_length' => $maxLength,
            'length' => 0,
            'charsetnr' => $charsetnr,
            'flags' => $flags,
            'type' => $type,
            'decimals' => $decimals,
        ];
    }

    /**
     * @param array<string, int|float|string|null> $row
     *
     * @return array<int|string, int|float|string|null>
     */
    private static function shapeRow(array $row, int $mode): array
    {
        if ($mode === Protocol::ASSOC) {
            return $row;
        }
        if ($mode === Protocol::NUM) {
            return \array_values($row);
        }
        $out = [];
        $i = 0;
        foreach ($row as $k => $v) {
            $out[$i] = $v;
            $out[$k] = $v;
            $i++;
        }

        return $out;
    }

    private function checkOpen(): void
    {
        if ($this->freed) {
            throw new \Error('mysqli_result object is already closed');
        }
    }
}
