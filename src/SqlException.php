<?php

declare(strict_types=1);

namespace Ephpm\Mysqli;

/**
 * SQL error thrown by the shim under `MYSQLI_REPORT_STRICT`.
 *
 * Extends \RuntimeException — the same parent as the real
 * `mysqli_sql_exception`. When ext-mysqli is absent,
 * `src/compat/mysqli.php` aliases this class to the global
 * `mysqli_sql_exception` name, so `catch (mysqli_sql_exception $e)`
 * in existing code works.
 *
 * When the real ext-mysqli IS loaded, that alias is not installed and
 * this class CANNOT extend the real `mysqli_sql_exception` either (the
 * real class is final as of PHP 8.4) — so code using the namespaced API
 * alongside the real extension must catch `Ephpm\Mysqli\SqlException`
 * (or `\RuntimeException`), not `\mysqli_sql_exception`.
 */
class SqlException extends \RuntimeException
{
    protected string $sqlstate;

    public function __construct(string $message, int $code = 0, string $sqlstate = 'HY000')
    {
        parent::__construct($message, $code);
        $this->sqlstate = $sqlstate;
    }

    /**
     * The SQLSTATE associated with the error, e.g. `23000`. Same method
     * name/shape as the real `mysqli_sql_exception::getSqlState()`.
     */
    public function getSqlState(): string
    {
        return $this->sqlstate;
    }
}
