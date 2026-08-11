<?php

/**
 * Guarded global mysqli surface.
 *
 * ACTIVATION RULE: this file defines the global mysqli classes,
 * functions, and constants ONLY when the real ext-mysqli is NOT loaded.
 * A userland shim cannot override a loaded extension, so under any PHP
 * build that compiles mysqli in (including ePHPm's own SDK builds,
 * currently) this file is a no-op and the real extension wins. The
 * namespaced API (Ephpm\Mysqli\Connection etc.) works either way.
 *
 * Loaded automatically via composer's `autoload.files`. Every definition
 * is additionally guarded individually so coexistence with another
 * polyfill can't fatal.
 */

declare(strict_types=1);

use Ephpm\Mysqli\Compat;
use Ephpm\Mysqli\Connection;
use Ephpm\Mysqli\Report;
use Ephpm\Mysqli\Result;
use Ephpm\Mysqli\SqlException;
use Ephpm\Mysqli\Statement;

if (\extension_loaded('mysqli')) {
    return;
}

// ── Constants ──────────────────────────────────────────────────────────

foreach (Compat::CONSTANTS as $ephpmMysqliConstName => $ephpmMysqliConstValue) {
    if (!\defined($ephpmMysqliConstName)) {
        \define($ephpmMysqliConstName, $ephpmMysqliConstValue);
    }
}
unset($ephpmMysqliConstName, $ephpmMysqliConstValue);

// ── Classes (thin aliases onto the namespaced implementation) ─────────

if (!\class_exists('mysqli_sql_exception', false)) {
    \class_alias(SqlException::class, 'mysqli_sql_exception');
}
if (!\class_exists('mysqli', false)) {
    \class_alias(Connection::class, 'mysqli');
}
if (!\class_exists('mysqli_result', false)) {
    \class_alias(Result::class, 'mysqli_result');
}
if (!\class_exists('mysqli_stmt', false)) {
    \class_alias(Statement::class, 'mysqli_stmt');
}

// ── Procedural wrappers ───────────────────────────────────────────────

if (!\function_exists('mysqli_connect')) {
    function mysqli_connect(
        ?string $hostname = null,
        ?string $username = null,
        ?string $password = null,
        ?string $database = null,
        ?int $port = null,
        ?string $socket = null,
    ): Connection|false {
        return new Connection($hostname, $username, $password, $database, $port, $socket);
    }
}

if (!\function_exists('mysqli_init')) {
    function mysqli_init(): Connection|false
    {
        return new Connection();
    }
}

if (!\function_exists('mysqli_real_connect')) {
    function mysqli_real_connect(
        Connection $mysql,
        ?string $hostname = null,
        ?string $username = null,
        ?string $password = null,
        ?string $database = null,
        ?int $port = null,
        ?string $socket = null,
        int $flags = 0,
    ): bool {
        return $mysql->real_connect($hostname, $username, $password, $database, $port, $socket, $flags);
    }
}

if (!\function_exists('mysqli_connect_errno')) {
    function mysqli_connect_errno(): int
    {
        return 0;
    }
}

if (!\function_exists('mysqli_connect_error')) {
    function mysqli_connect_error(): ?string
    {
        return null;
    }
}

if (!\function_exists('mysqli_close')) {
    function mysqli_close(Connection $mysql): bool
    {
        return $mysql->close();
    }
}

if (!\function_exists('mysqli_ping')) {
    function mysqli_ping(Connection $mysql): bool
    {
        return $mysql->ping();
    }
}

if (!\function_exists('mysqli_select_db')) {
    function mysqli_select_db(Connection $mysql, string $database): bool
    {
        return $mysql->select_db($database);
    }
}

if (!\function_exists('mysqli_set_charset')) {
    function mysqli_set_charset(Connection $mysql, string $charset): bool
    {
        return $mysql->set_charset($charset);
    }
}

if (!\function_exists('mysqli_character_set_name')) {
    function mysqli_character_set_name(Connection $mysql): string
    {
        return $mysql->character_set_name();
    }
}

if (!\function_exists('mysqli_get_charset')) {
    function mysqli_get_charset(Connection $mysql): ?object
    {
        return $mysql->get_charset();
    }
}

if (!\function_exists('mysqli_real_escape_string')) {
    function mysqli_real_escape_string(Connection $mysql, string $string): string
    {
        return $mysql->real_escape_string($string);
    }
}

if (!\function_exists('mysqli_escape_string')) {
    function mysqli_escape_string(Connection $mysql, string $string): string
    {
        return $mysql->real_escape_string($string);
    }
}

if (!\function_exists('mysqli_query')) {
    function mysqli_query(Connection $mysql, string $query, int $result_mode = 0): Result|bool
    {
        return $mysql->query($query, $result_mode);
    }
}

if (!\function_exists('mysqli_real_query')) {
    function mysqli_real_query(Connection $mysql, string $query): bool
    {
        return $mysql->real_query($query);
    }
}

if (!\function_exists('mysqli_store_result')) {
    function mysqli_store_result(Connection $mysql, int $mode = 0): Result|false
    {
        return $mysql->store_result($mode);
    }
}

if (!\function_exists('mysqli_use_result')) {
    function mysqli_use_result(Connection $mysql): Result|false
    {
        return $mysql->use_result();
    }
}

if (!\function_exists('mysqli_more_results')) {
    function mysqli_more_results(Connection $mysql): bool
    {
        return $mysql->more_results();
    }
}

if (!\function_exists('mysqli_next_result')) {
    function mysqli_next_result(Connection $mysql): bool
    {
        return $mysql->next_result();
    }
}

if (!\function_exists('mysqli_multi_query')) {
    function mysqli_multi_query(Connection $mysql, string $query): bool
    {
        return $mysql->multi_query($query); // throws NotImplementedException
    }
}

if (!\function_exists('mysqli_prepare')) {
    function mysqli_prepare(Connection $mysql, string $query): Statement|false
    {
        return $mysql->prepare($query);
    }
}

if (!\function_exists('mysqli_autocommit')) {
    function mysqli_autocommit(Connection $mysql, bool $enable): bool
    {
        return $mysql->autocommit($enable);
    }
}

if (!\function_exists('mysqli_begin_transaction')) {
    function mysqli_begin_transaction(Connection $mysql, int $flags = 0, ?string $name = null): bool
    {
        return $mysql->begin_transaction($flags, $name);
    }
}

if (!\function_exists('mysqli_commit')) {
    function mysqli_commit(Connection $mysql, int $flags = 0, ?string $name = null): bool
    {
        return $mysql->commit($flags, $name);
    }
}

if (!\function_exists('mysqli_rollback')) {
    function mysqli_rollback(Connection $mysql, int $flags = 0, ?string $name = null): bool
    {
        return $mysql->rollback($flags, $name);
    }
}

if (!\function_exists('mysqli_savepoint')) {
    function mysqli_savepoint(Connection $mysql, string $name): bool
    {
        return $mysql->savepoint($name);
    }
}

if (!\function_exists('mysqli_release_savepoint')) {
    function mysqli_release_savepoint(Connection $mysql, string $name): bool
    {
        return $mysql->release_savepoint($name);
    }
}

if (!\function_exists('mysqli_errno')) {
    function mysqli_errno(Connection $mysql): int
    {
        return $mysql->errno;
    }
}

if (!\function_exists('mysqli_error')) {
    function mysqli_error(Connection $mysql): string
    {
        return $mysql->error;
    }
}

if (!\function_exists('mysqli_error_list')) {
    function mysqli_error_list(Connection $mysql): array
    {
        return $mysql->error_list;
    }
}

if (!\function_exists('mysqli_sqlstate')) {
    function mysqli_sqlstate(Connection $mysql): string
    {
        return $mysql->sqlstate;
    }
}

if (!\function_exists('mysqli_insert_id')) {
    function mysqli_insert_id(Connection $mysql): int|string
    {
        return $mysql->insert_id;
    }
}

if (!\function_exists('mysqli_affected_rows')) {
    function mysqli_affected_rows(Connection $mysql): int|string
    {
        return $mysql->affected_rows;
    }
}

if (!\function_exists('mysqli_field_count')) {
    function mysqli_field_count(Connection $mysql): int
    {
        return $mysql->field_count;
    }
}

if (!\function_exists('mysqli_thread_id')) {
    function mysqli_thread_id(Connection $mysql): int
    {
        return $mysql->thread_id;
    }
}

if (!\function_exists('mysqli_warning_count')) {
    function mysqli_warning_count(Connection $mysql): int
    {
        return 0;
    }
}

if (!\function_exists('mysqli_get_server_info')) {
    function mysqli_get_server_info(Connection $mysql): string
    {
        return $mysql->get_server_info();
    }
}

if (!\function_exists('mysqli_get_server_version')) {
    function mysqli_get_server_version(Connection $mysql): int
    {
        return Connection::SERVER_VERSION;
    }
}

if (!\function_exists('mysqli_get_client_info')) {
    function mysqli_get_client_info(?Connection $mysql = null): string
    {
        return $mysql !== null ? $mysql->get_client_info() : 'ephpm-mysqli-shim';
    }
}

if (!\function_exists('mysqli_get_host_info')) {
    function mysqli_get_host_info(Connection $mysql): string
    {
        return $mysql->host_info;
    }
}

if (!\function_exists('mysqli_get_proto_info')) {
    function mysqli_get_proto_info(Connection $mysql): int
    {
        return $mysql->protocol_version;
    }
}

if (!\function_exists('mysqli_options')) {
    function mysqli_options(Connection $mysql, int $option, $value): bool
    {
        return $mysql->options($option, $value);
    }
}

if (!\function_exists('mysqli_ssl_set')) {
    function mysqli_ssl_set(
        Connection $mysql,
        ?string $key,
        ?string $certificate,
        ?string $ca_certificate,
        ?string $ca_path,
        ?string $cipher_algos,
    ): bool {
        return $mysql->ssl_set($key, $certificate, $ca_certificate, $ca_path, $cipher_algos);
    }
}

if (!\function_exists('mysqli_report')) {
    function mysqli_report(int $flags): bool
    {
        Report::set($flags);

        return true;
    }
}

// ── Result functions ──────────────────────────────────────────────────

if (!\function_exists('mysqli_fetch_assoc')) {
    function mysqli_fetch_assoc(Result $result): ?array
    {
        return $result->fetch_assoc();
    }
}

if (!\function_exists('mysqli_fetch_array')) {
    function mysqli_fetch_array(Result $result, int $mode = MYSQLI_BOTH): ?array
    {
        return $result->fetch_array($mode);
    }
}

if (!\function_exists('mysqli_fetch_row')) {
    function mysqli_fetch_row(Result $result): ?array
    {
        return $result->fetch_row();
    }
}

if (!\function_exists('mysqli_fetch_object')) {
    function mysqli_fetch_object(
        Result $result,
        string $class = 'stdClass',
        array $constructor_args = [],
    ): object|false|null {
        return $result->fetch_object($class, $constructor_args);
    }
}

if (!\function_exists('mysqli_fetch_all')) {
    function mysqli_fetch_all(Result $result, int $mode = MYSQLI_NUM): array
    {
        return $result->fetch_all($mode);
    }
}

if (!\function_exists('mysqli_fetch_column')) {
    function mysqli_fetch_column(Result $result, int $column = 0): int|float|string|null|false
    {
        return $result->fetch_column($column);
    }
}

if (!\function_exists('mysqli_num_rows')) {
    function mysqli_num_rows(Result $result): int|string
    {
        return $result->num_rows;
    }
}

if (!\function_exists('mysqli_num_fields')) {
    function mysqli_num_fields(Result $result): int
    {
        return $result->field_count;
    }
}

if (!\function_exists('mysqli_fetch_field')) {
    function mysqli_fetch_field(Result $result): object|false
    {
        return $result->fetch_field();
    }
}

if (!\function_exists('mysqli_fetch_fields')) {
    function mysqli_fetch_fields(Result $result): array
    {
        return $result->fetch_fields();
    }
}

if (!\function_exists('mysqli_fetch_field_direct')) {
    function mysqli_fetch_field_direct(Result $result, int $index): object|false
    {
        return $result->fetch_field_direct($index);
    }
}

if (!\function_exists('mysqli_field_seek')) {
    function mysqli_field_seek(Result $result, int $index): bool
    {
        return $result->field_seek($index);
    }
}

if (!\function_exists('mysqli_field_tell')) {
    function mysqli_field_tell(Result $result): int
    {
        return $result->current_field;
    }
}

if (!\function_exists('mysqli_data_seek')) {
    function mysqli_data_seek(Result $result, int $offset): bool
    {
        return $result->data_seek($offset);
    }
}

if (!\function_exists('mysqli_free_result')) {
    function mysqli_free_result(Result $result): void
    {
        $result->free();
    }
}

// ── Statement functions ───────────────────────────────────────────────

if (!\function_exists('mysqli_stmt_bind_param')) {
    function mysqli_stmt_bind_param(Statement $statement, string $types, mixed &...$vars): bool
    {
        return $statement->bind_param($types, ...$vars);
    }
}

if (!\function_exists('mysqli_stmt_bind_result')) {
    function mysqli_stmt_bind_result(Statement $statement, mixed &...$vars): bool
    {
        return $statement->bind_result(...$vars);
    }
}

if (!\function_exists('mysqli_stmt_execute')) {
    function mysqli_stmt_execute(Statement $statement, ?array $params = null): bool
    {
        return $statement->execute($params);
    }
}

if (!\function_exists('mysqli_stmt_get_result')) {
    function mysqli_stmt_get_result(Statement $statement): Result|false
    {
        return $statement->get_result();
    }
}

if (!\function_exists('mysqli_stmt_fetch')) {
    function mysqli_stmt_fetch(Statement $statement): ?bool
    {
        return $statement->fetch();
    }
}

if (!\function_exists('mysqli_stmt_store_result')) {
    function mysqli_stmt_store_result(Statement $statement): bool
    {
        return $statement->store_result();
    }
}

if (!\function_exists('mysqli_stmt_free_result')) {
    function mysqli_stmt_free_result(Statement $statement): void
    {
        $statement->free_result();
    }
}

if (!\function_exists('mysqli_stmt_affected_rows')) {
    function mysqli_stmt_affected_rows(Statement $statement): int|string
    {
        return $statement->affected_rows;
    }
}

if (!\function_exists('mysqli_stmt_insert_id')) {
    function mysqli_stmt_insert_id(Statement $statement): int|string
    {
        return $statement->insert_id;
    }
}

if (!\function_exists('mysqli_stmt_num_rows')) {
    function mysqli_stmt_num_rows(Statement $statement): int|string
    {
        return $statement->num_rows;
    }
}

if (!\function_exists('mysqli_stmt_param_count')) {
    function mysqli_stmt_param_count(Statement $statement): int
    {
        return $statement->param_count;
    }
}

if (!\function_exists('mysqli_stmt_field_count')) {
    function mysqli_stmt_field_count(Statement $statement): int
    {
        return $statement->field_count;
    }
}

if (!\function_exists('mysqli_stmt_errno')) {
    function mysqli_stmt_errno(Statement $statement): int
    {
        return $statement->errno;
    }
}

if (!\function_exists('mysqli_stmt_error')) {
    function mysqli_stmt_error(Statement $statement): string
    {
        return $statement->error;
    }
}

if (!\function_exists('mysqli_stmt_error_list')) {
    function mysqli_stmt_error_list(Statement $statement): array
    {
        return $statement->error_list;
    }
}

if (!\function_exists('mysqli_stmt_sqlstate')) {
    function mysqli_stmt_sqlstate(Statement $statement): string
    {
        return $statement->sqlstate;
    }
}

if (!\function_exists('mysqli_stmt_close')) {
    function mysqli_stmt_close(Statement $statement): bool
    {
        return $statement->close();
    }
}
