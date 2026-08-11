<?php

/**
 * Child-process probe run by CompatSurfaceTest under `php -n` (no
 * ext-mysqli): exercises the guarded global mysqli surface end to end
 * against the pdo_sqlite bridge polyfill and prints a JSON report.
 */

declare(strict_types=1);

error_reporting(E_ALL);

$out = static function (array $data): never {
    echo json_encode($data, JSON_THROW_ON_ERROR);
    exit(0);
};

if (extension_loaded('mysqli')) {
    $out(['skip' => 'ext-mysqli is loaded in the child; cannot test the guarded surface']);
}
if (!extension_loaded('sqlite3')) {
    $out(['skip' => 'sqlite3 unavailable in the child']);
}

require $argv[1]; // vendor/autoload.php — loads src/compat/mysqli.php via autoload.files

use Ephpm\Mysqli\Connection;
use Ephpm\Mysqli\SqliteDbOps;

Connection::setDefaultOpsFactory(static fn (): SqliteDbOps => new SqliteDbOps());

$report = [];

$report['class_mysqli_is_shim'] = class_exists('mysqli') && is_a('mysqli', Connection::class, true);
$report['classes'] = [
    'mysqli_result' => class_exists('mysqli_result'),
    'mysqli_stmt' => class_exists('mysqli_stmt'),
    'mysqli_sql_exception' => class_exists('mysqli_sql_exception'),
];
$report['constants'] = [
    'MYSQLI_ASSOC' => defined('MYSQLI_ASSOC') ? constant('MYSQLI_ASSOC') : null,
    'MYSQLI_NUM' => defined('MYSQLI_NUM') ? constant('MYSQLI_NUM') : null,
    'MYSQLI_BOTH' => defined('MYSQLI_BOTH') ? constant('MYSQLI_BOTH') : null,
    'MYSQLI_REPORT_ERROR' => defined('MYSQLI_REPORT_ERROR') ? constant('MYSQLI_REPORT_ERROR') : null,
    'MYSQLI_TYPE_LONGLONG' => defined('MYSQLI_TYPE_LONGLONG') ? constant('MYSQLI_TYPE_LONGLONG') : null,
];

// Full procedural cycle through the global functions.
$db = mysqli_connect('ignored-host', 'user', 'pass', 'dbname', 3306);
$report['connect_ok'] = $db instanceof mysqli;
$report['server_info'] = mysqli_get_server_info($db);
$report['server_version'] = mysqli_get_server_version($db);

mysqli_query($db, 'CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE)');
mysqli_query($db, "INSERT INTO items (name) VALUES ('one')");
$report['insert_id'] = mysqli_insert_id($db);
$report['affected_rows'] = mysqli_affected_rows($db);

$stmt = mysqli_prepare($db, 'INSERT INTO items (name) VALUES (?)');
$name = 'two';
mysqli_stmt_bind_param($stmt, 's', $name);
mysqli_stmt_execute($stmt);
$report['stmt_insert_id'] = mysqli_stmt_insert_id($stmt);
mysqli_stmt_close($stmt);

$result = mysqli_query($db, 'SELECT id, name FROM items ORDER BY id');
$report['result_is_mysqli_result'] = $result instanceof mysqli_result;
$report['num_rows'] = mysqli_num_rows($result);
$report['rows_assoc'] = [];
while (($row = mysqli_fetch_assoc($result)) !== null) {
    $report['rows_assoc'][] = $row;
}
mysqli_data_seek($result, 0);
$report['row_num'] = mysqli_fetch_array($result, MYSQLI_NUM);
mysqli_free_result($result);

// Error path: default report mode throws the aliased exception class.
try {
    mysqli_query($db, "INSERT INTO items (name) VALUES ('one')");
    $report['dup_error'] = 'no exception';
} catch (mysqli_sql_exception $e) {
    $report['dup_error'] = ['code' => $e->getCode(), 'sqlstate' => $e->getSqlState()];
}

// Report-off path.
mysqli_report(MYSQLI_REPORT_OFF);
$report['off_mode_result'] = mysqli_query($db, 'SELECT * FROM missing_table');
$report['off_mode_errno'] = mysqli_errno($db);
$report['off_mode_sqlstate'] = mysqli_sqlstate($db);

$report['escape'] = mysqli_real_escape_string($db, "O'Brien\n");
$report['close'] = mysqli_close($db);

// Including the compat file a second time must be harmless.
require __DIR__ . '/../../src/compat/mysqli.php';
$report['double_include_ok'] = true;

$out(['report' => $report]);
