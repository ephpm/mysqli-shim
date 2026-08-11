<?php

declare(strict_types=1);

namespace Ephpm\Mysqli;

/**
 * Stand-in for the `mysqli` class, backed by ePHPm's in-process DB bridge
 * (`ephpm_db_query()` / `ephpm_db_execute()`) instead of a MySQL socket.
 *
 * There is no connection: host/user/password/port/socket arguments are
 * accepted and ignored, `connect()`/`real_connect()`/`ping()` always
 * succeed, and every statement runs on the per-thread litewire session
 * inside the ePHPm process.
 *
 * `src/compat/mysqli.php` aliases this class to the global `mysqli` name
 * when (and only when) ext-mysqli is absent. The class is deliberately
 * not final, mirroring the real `mysqli` (which application code
 * sometimes extends).
 *
 * Magic readable properties (mirroring the real class):
 *
 * @property-read int $errno
 * @property-read string $error
 * @property-read list<array{errno: int, sqlstate: string, error: string}> $error_list
 * @property-read string $sqlstate
 * @property-read int $insert_id
 * @property-read int $affected_rows
 * @property-read int $field_count
 * @property-read string $server_info
 * @property-read int $server_version
 * @property-read string $client_info
 * @property-read int $client_version
 * @property-read string $host_info
 * @property-read int $protocol_version
 * @property-read int $thread_id
 * @property-read int $warning_count
 * @property-read int $connect_errno
 * @property-read ?string $connect_error
 */
class Connection
{
    /**
     * Server version string, mirroring what litewire's MySQL wire
     * frontend advertises in its handshake (`litewire-mysql`
     * `Handler::server_version()`): the same backend answers
     * `SELECT VERSION()` with `8.0.0-litewire` (translate-layer
     * constant), so don't be surprised by the mismatch — real litewire
     * over a socket shows the same pair.
     */
    public const SERVER_INFO = '8.0.36-litewire';

    /** Numeric form of {@see SERVER_INFO} (major*10000 + minor*100 + patch). */
    public const SERVER_VERSION = 80036;

    private static ?\Closure $defaultOpsFactory = null;

    private static int $nextThreadId = 1;

    private DbOpsInterface $ops;

    private bool $closed = false;

    private int $errno = 0;

    private string $error = '';

    private string $sqlstate = '00000';

    /** @var list<array{errno: int, sqlstate: string, error: string}> */
    private array $errorList = [];

    private int $insertId = 0;

    private int $affectedRows = 0;

    private int $fieldCount = 0;

    private bool $autocommit = true;

    private bool $inTransaction = false;

    private int $threadId;

    /** @var list<array<string, int|float|string|null>>|null rows staged by real_query() */
    private ?array $pendingRows = null;

    /**
     * All six mysqli connection arguments are accepted and ignored —
     * there is nothing to connect to. The extra `$ops` argument (not
     * present on the real class) injects a backend for tests.
     */
    public function __construct(
        ?string $hostname = null,
        ?string $username = null,
        ?string $password = null,
        ?string $database = null,
        ?int $port = null,
        ?string $socket = null,
        ?DbOpsInterface $ops = null,
    ) {
        $this->ops = $ops
            ?? (self::$defaultOpsFactory !== null ? (self::$defaultOpsFactory)() : new SapiDbOps());
        $this->threadId = self::$nextThreadId++;
    }

    /**
     * Process-wide default backend factory used when a Connection is
     * constructed without an explicit `$ops` (e.g. through the global
     * `mysqli`/`mysqli_connect()` compat surface). Embedders and tests
     * use this to swap {@see SapiDbOps} out; `null` restores the default.
     *
     * @param null|callable(): DbOpsInterface $factory
     */
    public static function setDefaultOpsFactory(?callable $factory): void
    {
        self::$defaultOpsFactory = $factory === null ? null : \Closure::fromCallable($factory);
    }

    // ── Connection lifecycle (all no-ops) ──────────────────────────────

    /** Accepted and ignored; always succeeds. */
    public function connect(
        ?string $hostname = null,
        ?string $username = null,
        ?string $password = null,
        ?string $database = null,
        ?int $port = null,
        ?string $socket = null,
    ): bool {
        $this->checkOpen();

        return true;
    }

    /** Accepted and ignored ($flags too); always succeeds. */
    public function real_connect(
        ?string $hostname = null,
        ?string $username = null,
        ?string $password = null,
        ?string $database = null,
        ?int $port = null,
        ?string $socket = null,
        int $flags = 0,
    ): bool {
        $this->checkOpen();

        return true;
    }

    public function close(): bool
    {
        $this->closed = true;

        return true;
    }

    /** There is no connection to lose; always true. */
    public function ping(): bool
    {
        $this->checkOpen();

        return true;
    }

    /**
     * No-op returning true. The bridge session has no notion of a
     * current database — [db.sqlite] is a single database.
     */
    public function select_db(string $database): bool
    {
        $this->checkOpen();

        return true;
    }

    /** No-op returning true; the bridge always speaks UTF-8. */
    public function set_charset(string $charset): bool
    {
        $this->checkOpen();

        return true;
    }

    public function character_set_name(): string
    {
        return 'utf8mb4';
    }

    /** @return object charset info, shaped like the real get_charset() */
    public function get_charset(): object
    {
        return (object) [
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_general_ci',
            'dir' => '',
            'min_length' => 1,
            'max_length' => 4,
            'number' => 45,
            'state' => 1,
            'comment' => '',
        ];
    }

    /** Accepted and ignored; always true. */
    public function options(int $option, $value): bool
    {
        return true;
    }

    /** Alias of {@see options()}. */
    public function set_opt(int $option, $value): bool
    {
        return true;
    }

    /** Accepted and ignored (nothing to secure in-process); always true. */
    public function ssl_set(
        ?string $key,
        ?string $certificate,
        ?string $ca_certificate,
        ?string $ca_path,
        ?string $cipher_algos,
    ): bool {
        return true;
    }

    public function get_server_info(): string
    {
        return self::SERVER_INFO;
    }

    public function get_client_info(): string
    {
        return 'ephpm-mysqli-shim';
    }

    // ── Escaping ───────────────────────────────────────────────────────

    /**
     * mysql_real_escape_string() semantics: backslash-escape NUL, \n,
     * \r, backslash, single quote, double quote, and Ctrl-Z.
     */
    public function real_escape_string(string $string): string
    {
        return \strtr($string, [
            "\0" => '\0',
            "\n" => '\n',
            "\r" => '\r',
            '\\' => '\\\\',
            "'" => "\\'",
            '"' => '\"',
            "\x1a" => '\Z',
        ]);
    }

    /** Alias of {@see real_escape_string()}. */
    public function escape_string(string $string): string
    {
        return $this->real_escape_string($string);
    }

    // ── Queries ────────────────────────────────────────────────────────

    /**
     * Run a statement. Returns a {@see Result} for rowset-producing
     * statements (even zero-row ones), true for everything else, false
     * on error when the report mode doesn't throw.
     *
     * `$result_mode` is accepted and ignored: all results are buffered
     * (MYSQLI_USE_RESULT behaves like MYSQLI_STORE_RESULT, MYSQLI_ASYNC
     * is not supported and also ignored).
     */
    public function query(string $query, int $result_mode = 0): Result|bool
    {
        $this->checkOpen();
        $this->resetErrorState();

        try {
            if (SqlHelper::producesRowset($query)) {
                $rows = $this->runQuery($query, []);
                $this->affectedRows = \count($rows);
                $this->insertId = 0;
                $this->fieldCount = $rows === [] ? 0 : \count($rows[0]);

                return new Result($rows);
            }

            $ok = $this->runExec($query, []);
            $this->affectedRows = $ok['affected_rows'];
            $this->insertId = $ok['last_insert_id'];
            $this->fieldCount = 0;

            return true;
        } catch (SqlException $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Like {@see query()} but stages a rowset for a later
     * {@see store_result()} / {@see use_result()} call.
     */
    public function real_query(string $query): bool
    {
        $result = $this->query($query);
        if ($result === false) {
            return false;
        }
        $this->pendingRows = $result instanceof Result ? $result->allRows() : null;

        return true;
    }

    /**
     * Result staged by {@see real_query()}, or false if the last
     * statement produced no rowset. `$mode` accepted and ignored.
     */
    public function store_result(int $mode = 0): Result|false
    {
        $this->checkOpen();
        if ($this->pendingRows === null) {
            return false;
        }
        $rows = $this->pendingRows;
        $this->pendingRows = null;

        return new Result($rows);
    }

    /** Identical to {@see store_result()} — everything is buffered. */
    public function use_result(): Result|false
    {
        return $this->store_result();
    }

    /** Always false: multi_query() is not implemented. */
    public function more_results(): bool
    {
        return false;
    }

    /** Always false: multi_query() is not implemented. */
    public function next_result(): bool
    {
        return false;
    }

    /**
     * Prepare a statement. Note: unlike the real mysqli, the SQL is NOT
     * validated here (the bridge has no separate prepare step) — syntax
     * errors surface at execute() time.
     */
    public function prepare(string $query): Statement|false
    {
        $this->checkOpen();
        $this->resetErrorState();

        return new Statement($this, $query);
    }

    // ── Transactions ───────────────────────────────────────────────────

    /**
     * Emulates MySQL autocommit: turning it off makes the shim open a
     * transaction (lazy BEGIN) before the next statement and after each
     * COMMIT/ROLLBACK; turning it on commits any open transaction.
     * The BEGIN/COMMIT statements flow through the bridge as plain SQL.
     */
    public function autocommit(bool $enable): bool
    {
        $this->checkOpen();
        if ($enable && !$this->autocommit && $this->inTransaction) {
            if ($this->commit() === false) {
                return false;
            }
        }
        $this->autocommit = $enable;

        return true;
    }

    /** `$flags` and `$name` are accepted and ignored; runs `BEGIN`. */
    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        $this->checkOpen();
        $this->resetErrorState();

        try {
            $this->runExec('BEGIN', []);

            return true;
        } catch (SqlException $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Runs `COMMIT` if the shim believes a transaction is open (tracked
     * from BEGIN/COMMIT/ROLLBACK statements it has seen); otherwise a
     * no-op returning true, like real MySQL. `$flags`/`$name` ignored.
     */
    public function commit(int $flags = 0, ?string $name = null): bool
    {
        return $this->endTransaction('COMMIT');
    }

    /** Counterpart of {@see commit()}; runs `ROLLBACK`. */
    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        return $this->endTransaction('ROLLBACK');
    }

    /**
     * Passes `SAVEPOINT `name`` through the bridge as SQL. Whether
     * savepoints work depends on the backend's SQL support — the shim
     * adds nothing on top.
     */
    public function savepoint(string $name): bool
    {
        return $this->runControl('SAVEPOINT ' . self::quoteIdentifier($name));
    }

    /** Passes `RELEASE SAVEPOINT` through as SQL; see {@see savepoint()}. */
    public function release_savepoint(string $name): bool
    {
        return $this->runControl('RELEASE SAVEPOINT ' . self::quoteIdentifier($name));
    }

    // ── Not implemented ────────────────────────────────────────────────

    public function multi_query(string $query): bool
    {
        throw NotImplementedException::for(
            'mysqli::multi_query()',
            'issue the statements one at a time through query()'
        );
    }

    public function change_user(string $username, string $password, ?string $database): bool
    {
        throw NotImplementedException::for('mysqli::change_user()');
    }

    public function kill(int $process_id): bool
    {
        throw NotImplementedException::for('mysqli::kill()');
    }

    public function refresh(int $flags): bool
    {
        throw NotImplementedException::for('mysqli::refresh()');
    }

    public function stat(): string|false
    {
        throw NotImplementedException::for('mysqli::stat()');
    }

    public function dump_debug_info(): bool
    {
        throw NotImplementedException::for('mysqli::dump_debug_info()');
    }

    public function debug(string $options): bool
    {
        throw NotImplementedException::for('mysqli::debug()');
    }

    public function stmt_init(): Statement|false
    {
        throw NotImplementedException::for('mysqli::stmt_init()', 'use prepare()');
    }

    public function get_warnings(): false
    {
        return false; // warning_count is always 0
    }

    // ── Magic properties ───────────────────────────────────────────────

    public function __get(string $name): mixed
    {
        return match ($name) {
            'errno' => $this->errno,
            'error' => $this->error,
            'error_list' => $this->errorList,
            'sqlstate' => $this->sqlstate,
            'insert_id' => $this->insertId,
            'affected_rows' => $this->affectedRows,
            'field_count' => $this->fieldCount,
            'server_info' => self::SERVER_INFO,
            'server_version' => self::SERVER_VERSION,
            'client_info' => $this->get_client_info(),
            'client_version' => 0,
            'host_info' => 'ePHPm in-process DB bridge',
            'protocol_version' => 10,
            'thread_id' => $this->threadId,
            'warning_count' => 0,
            'connect_errno' => 0,
            'connect_error' => null,
            default => \trigger_error(
                \sprintf('Undefined property: %s::$%s', static::class, $name),
                \E_USER_WARNING
            ) ? null : null,
        };
    }

    public function __isset(string $name): bool
    {
        return \in_array($name, [
            'errno', 'error', 'error_list', 'sqlstate', 'insert_id',
            'affected_rows', 'field_count', 'server_info', 'server_version',
            'client_info', 'client_version', 'host_info', 'protocol_version',
            'thread_id', 'warning_count', 'connect_errno', 'connect_error',
        ], true);
    }

    // ── Internals shared with Statement ────────────────────────────────

    /**
     * Run a rowset-producing statement through the bridge.
     *
     * @internal used by {@see Statement}
     *
     * @param list<null|bool|int|float|string> $params
     *
     * @return list<array<string, int|float|string|null>>
     *
     * @throws SqlException
     */
    public function runQuery(string $sql, array $params): array
    {
        $this->beforeStatement($sql);

        try {
            $rows = $this->ops->query($sql, $params);
        } catch (\Exception $e) {
            throw $this->recordError($e);
        }
        $this->trackTransaction($sql);

        return $rows;
    }

    /**
     * Run a non-rowset statement through the bridge.
     *
     * @internal used by {@see Statement}
     *
     * @param list<null|bool|int|float|string> $params
     *
     * @return array{affected_rows: int, last_insert_id: int}
     *
     * @throws SqlException
     */
    public function runExec(string $sql, array $params): array
    {
        $this->beforeStatement($sql);

        try {
            $ok = $this->ops->execute($sql, $params);
        } catch (\Exception $e) {
            throw $this->recordError($e);
        }
        $this->trackTransaction($sql);

        return $ok;
    }

    /**
     * Apply the current report mode to an error: throw under STRICT,
     * warn and return false under ERROR alone, silently return false
     * otherwise. Error state has already been recorded on the object.
     *
     * @internal used by {@see Statement}
     */
    public function handleError(SqlException $e): bool
    {
        $mode = Report::get();
        if (($mode & Report::ERROR) !== 0) {
            if (($mode & Report::STRICT) !== 0) {
                throw $e;
            }
            \trigger_error(
                \sprintf('mysqli error (%s/%d): %s', $this->sqlstate, $this->errno, $e->getMessage()),
                \E_USER_WARNING
            );
        }

        return false;
    }

    /**
     * Record OK metadata from a Statement execution so the connection's
     * insert_id/affected_rows mirror the last statement, like real mysqli.
     *
     * @internal used by {@see Statement}
     */
    public function recordStatementResult(int $affectedRows, int $insertId, int $fieldCount): void
    {
        $this->affectedRows = $affectedRows;
        $this->insertId = $insertId;
        $this->fieldCount = $fieldCount;
    }

    /** @internal used by {@see Statement} */
    public function resetErrorState(): void
    {
        $this->errno = 0;
        $this->error = '';
        $this->sqlstate = '00000';
        $this->errorList = [];
    }

    /** @internal used by {@see Statement} */
    public function checkOpen(): void
    {
        if ($this->closed) {
            throw new \Error('mysqli object is already closed');
        }
    }

    // ── Private helpers ────────────────────────────────────────────────

    /**
     * With autocommit off, lazily open a transaction before a data
     * statement (mirrors MySQL's implicit transaction start).
     */
    private function beforeStatement(string $sql): void
    {
        if ($this->autocommit || $this->inTransaction) {
            return;
        }
        $kw = SqlHelper::firstKeyword($sql);
        if (SqlHelper::txnEffect($sql) !== 0 || $kw === 'SET' || $kw === 'SAVEPOINT' || $kw === 'RELEASE') {
            return;
        }

        try {
            $this->ops->execute('BEGIN', []);
        } catch (\Exception $e) {
            throw $this->recordError($e);
        }
        $this->inTransaction = true;
    }

    private function trackTransaction(string $sql): void
    {
        match (SqlHelper::txnEffect($sql)) {
            1 => $this->inTransaction = true,
            -1 => $this->inTransaction = false,
            default => null,
        };
    }

    /**
     * Parse the bridge's error shape (`SQLSTATE[xxxxx]: message`, code =
     * MySQL errno), record it on the connection, and return the
     * corresponding SqlException (message = bare backend message, like
     * the real mysqli's $error).
     */
    private function recordError(\Exception $e): SqlException
    {
        if ($e instanceof SqlException) {
            return $e; // already parsed and recorded
        }
        $errno = (int) $e->getCode();
        $state = 'HY000';
        $message = $e->getMessage();
        if (\preg_match('/^SQLSTATE\[([0-9A-Za-z]{5})\]:\s*(.*)$/s', $message, $m) === 1) {
            $state = $m[1];
            $message = $m[2];
        }
        $this->errno = $errno;
        $this->error = $message;
        $this->sqlstate = $state;
        $this->errorList = [['errno' => $errno, 'sqlstate' => $state, 'error' => $message]];

        return new SqlException($message, $errno, $state);
    }

    private function endTransaction(string $sql): bool
    {
        $this->checkOpen();
        $this->resetErrorState();
        if (!$this->inTransaction) {
            return true; // nothing open — no-op, like real MySQL
        }

        try {
            $this->runExec($sql, []);

            return true;
        } catch (SqlException $e) {
            return $this->handleError($e);
        }
    }

    private function runControl(string $sql): bool
    {
        $this->checkOpen();
        $this->resetErrorState();

        try {
            $this->runExec($sql, []);

            return true;
        } catch (SqlException $e) {
            return $this->handleError($e);
        }
    }

    private static function quoteIdentifier(string $name): string
    {
        return '`' . \str_replace('`', '``', $name) . '`';
    }
}
