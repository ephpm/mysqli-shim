<?php

declare(strict_types=1);

namespace Ephpm\Mysqli;

/**
 * The global `MYSQLI_*` constants defined by `src/compat/mysqli.php`
 * when ext-mysqli is absent, with the same values as the real
 * extension's constants.
 *
 * Kept as a data map (rather than inline `define()`s) so the test suite
 * can assert every value against the real extension when it is loaded.
 */
final class Compat
{
    /** @var array<string, int> */
    public const CONSTANTS = [
        // Fetch modes
        'MYSQLI_ASSOC' => 1,
        'MYSQLI_NUM' => 2,
        'MYSQLI_BOTH' => 3,

        // Result modes
        'MYSQLI_STORE_RESULT' => 0,
        'MYSQLI_USE_RESULT' => 1,

        // mysqli_report() flags
        'MYSQLI_REPORT_OFF' => 0,
        'MYSQLI_REPORT_ERROR' => 1,
        'MYSQLI_REPORT_STRICT' => 2,
        'MYSQLI_REPORT_INDEX' => 4,
        'MYSQLI_REPORT_ALL' => 255,

        // Field types
        'MYSQLI_TYPE_DECIMAL' => 0,
        'MYSQLI_TYPE_TINY' => 1,
        'MYSQLI_TYPE_SHORT' => 2,
        'MYSQLI_TYPE_LONG' => 3,
        'MYSQLI_TYPE_FLOAT' => 4,
        'MYSQLI_TYPE_DOUBLE' => 5,
        'MYSQLI_TYPE_NULL' => 6,
        'MYSQLI_TYPE_TIMESTAMP' => 7,
        'MYSQLI_TYPE_LONGLONG' => 8,
        'MYSQLI_TYPE_INT24' => 9,
        'MYSQLI_TYPE_DATE' => 10,
        'MYSQLI_TYPE_TIME' => 11,
        'MYSQLI_TYPE_DATETIME' => 12,
        'MYSQLI_TYPE_YEAR' => 13,
        'MYSQLI_TYPE_NEWDATE' => 14,
        // NOTE: there deliberately is no MYSQLI_TYPE_VARCHAR — the real
        // extension does not define one (protocol type 15 is never sent).
        'MYSQLI_TYPE_BIT' => 16,
        'MYSQLI_TYPE_JSON' => 245,
        'MYSQLI_TYPE_NEWDECIMAL' => 246,
        'MYSQLI_TYPE_ENUM' => 247,
        'MYSQLI_TYPE_SET' => 248,
        'MYSQLI_TYPE_TINY_BLOB' => 249,
        'MYSQLI_TYPE_MEDIUM_BLOB' => 250,
        'MYSQLI_TYPE_LONG_BLOB' => 251,
        'MYSQLI_TYPE_BLOB' => 252,
        'MYSQLI_TYPE_VAR_STRING' => 253,
        'MYSQLI_TYPE_STRING' => 254,
        'MYSQLI_TYPE_GEOMETRY' => 255,
        'MYSQLI_TYPE_CHAR' => 1,

        // Field flags
        'MYSQLI_NOT_NULL_FLAG' => 1,
        'MYSQLI_PRI_KEY_FLAG' => 2,
        'MYSQLI_UNIQUE_KEY_FLAG' => 4,
        'MYSQLI_MULTIPLE_KEY_FLAG' => 8,
        'MYSQLI_BLOB_FLAG' => 16,
        'MYSQLI_UNSIGNED_FLAG' => 32,
        'MYSQLI_ZEROFILL_FLAG' => 64,
        'MYSQLI_BINARY_FLAG' => 128,
        'MYSQLI_ENUM_FLAG' => 256,
        'MYSQLI_AUTO_INCREMENT_FLAG' => 512,
        'MYSQLI_TIMESTAMP_FLAG' => 1024,
        'MYSQLI_SET_FLAG' => 2048,
        'MYSQLI_PART_KEY_FLAG' => 16384,
        'MYSQLI_NUM_FLAG' => 32768,

        // Connect flags (accepted and ignored by the shim)
        'MYSQLI_CLIENT_FOUND_ROWS' => 2,
        'MYSQLI_CLIENT_NO_SCHEMA' => 16,
        'MYSQLI_CLIENT_COMPRESS' => 32,
        'MYSQLI_CLIENT_IGNORE_SPACE' => 256,
        'MYSQLI_CLIENT_INTERACTIVE' => 1024,
        'MYSQLI_CLIENT_SSL' => 2048,

        // Options (accepted and ignored by the shim)
        'MYSQLI_OPT_CONNECT_TIMEOUT' => 0,
        'MYSQLI_INIT_COMMAND' => 3,
        'MYSQLI_READ_DEFAULT_FILE' => 4,
        'MYSQLI_READ_DEFAULT_GROUP' => 5,
        'MYSQLI_OPT_LOCAL_INFILE' => 8,
        'MYSQLI_OPT_READ_TIMEOUT' => 11,
        'MYSQLI_OPT_INT_AND_FLOAT_NATIVE' => 201,
        'MYSQLI_OPT_NET_CMD_BUFFER_SIZE' => 202,
        'MYSQLI_OPT_NET_READ_BUFFER_SIZE' => 203,

        // Transaction start flags (accepted and ignored by the shim)
        'MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT' => 1,
        'MYSQLI_TRANS_START_READ_WRITE' => 2,
        'MYSQLI_TRANS_START_READ_ONLY' => 4,

        // MYSQLI_NO_DATA / MYSQLI_DATA_TRUNCATED are deliberately absent:
        // deprecated in PHP 8.4, and the shim never produces them.
    ];

    private function __construct()
    {
    }
}
