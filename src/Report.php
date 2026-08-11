<?php

declare(strict_types=1);

namespace Ephpm\Mysqli;

/**
 * Error-report mode for the shim, mirroring `mysqli_report()`.
 *
 * The constants carry the same values as the global `MYSQLI_REPORT_*`
 * constants. The default matches PHP 8.1+'s mysqli default:
 * `ERROR | STRICT` (errors throw {@see SqlException}).
 *
 * Note: when the real ext-mysqli is loaded, the global `mysqli_report()`
 * function controls the real driver, not this shim — namespaced users
 * call {@see Report::set()} directly. The guarded global `mysqli_report()`
 * defined by `src/compat/mysqli.php` (ext absent) forwards here.
 */
final class Report
{
    public const OFF = 0;
    public const ERROR = 1;
    public const STRICT = 2;
    public const INDEX = 4;
    public const ALL = 255;

    private static int $mode = self::ERROR | self::STRICT;

    public static function set(int $flags): void
    {
        self::$mode = $flags;
    }

    public static function get(): int
    {
        return self::$mode;
    }
}
