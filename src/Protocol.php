<?php

declare(strict_types=1);

namespace Ephpm\Mysqli;

/**
 * MySQL protocol constants used by the shim internally, with the same
 * values as the corresponding global `MYSQLI_*` constants (which only
 * exist when ext-mysqli is loaded, or via `src/compat/mysqli.php` when
 * it isn't — internal code must not depend on the globals).
 *
 * Value fidelity is asserted against the real extension in the test
 * suite when ext-mysqli is available.
 */
final class Protocol
{
    // Fetch modes (MYSQLI_ASSOC / MYSQLI_NUM / MYSQLI_BOTH)
    public const ASSOC = 1;
    public const NUM = 2;
    public const BOTH = 3;

    // Field types (MYSQLI_TYPE_*)
    public const TYPE_DECIMAL = 0;
    public const TYPE_TINY = 1;
    public const TYPE_SHORT = 2;
    public const TYPE_LONG = 3;
    public const TYPE_FLOAT = 4;
    public const TYPE_DOUBLE = 5;
    public const TYPE_NULL = 6;
    public const TYPE_TIMESTAMP = 7;
    public const TYPE_LONGLONG = 8;
    public const TYPE_INT24 = 9;
    public const TYPE_DATE = 10;
    public const TYPE_TIME = 11;
    public const TYPE_DATETIME = 12;
    public const TYPE_YEAR = 13;
    public const TYPE_NEWDATE = 14;
    public const TYPE_VARCHAR = 15;
    public const TYPE_BIT = 16;
    public const TYPE_JSON = 245;
    public const TYPE_NEWDECIMAL = 246;
    public const TYPE_ENUM = 247;
    public const TYPE_SET = 248;
    public const TYPE_TINY_BLOB = 249;
    public const TYPE_MEDIUM_BLOB = 250;
    public const TYPE_LONG_BLOB = 251;
    public const TYPE_BLOB = 252;
    public const TYPE_VAR_STRING = 253;
    public const TYPE_STRING = 254;
    public const TYPE_GEOMETRY = 255;

    // Field flags (MYSQLI_*_FLAG) — only NUM_FLAG is ever emitted by the
    // shim's best-effort field metadata; the rest are defined for user
    // code that masks against them.
    public const NOT_NULL_FLAG = 1;
    public const PRI_KEY_FLAG = 2;
    public const UNIQUE_KEY_FLAG = 4;
    public const MULTIPLE_KEY_FLAG = 8;
    public const BLOB_FLAG = 16;
    public const UNSIGNED_FLAG = 32;
    public const ZEROFILL_FLAG = 64;
    public const BINARY_FLAG = 128;
    public const ENUM_FLAG = 256;
    public const AUTO_INCREMENT_FLAG = 512;
    public const TIMESTAMP_FLAG = 1024;
    public const SET_FLAG = 2048;
    public const PART_KEY_FLAG = 16384;
    public const NUM_FLAG = 32768;

    // Charset numbers used in field metadata
    public const CHARSETNR_BINARY = 63;
    public const CHARSETNR_UTF8MB4 = 255;

    private function __construct()
    {
    }
}
