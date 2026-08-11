<?php

declare(strict_types=1);

namespace Ephpm\Mysqli;

/**
 * Thrown by mysqli surface the shim deliberately does not implement
 * (multi_query, bind-by-reference metadata, async, …). Deliberately NOT
 * a {@see SqlException} so `catch (mysqli_sql_exception)` blocks in
 * application code don't swallow "you hit an unimplemented API" bugs.
 *
 * See the coverage matrix in the README for the full list.
 */
final class NotImplementedException extends \BadMethodCallException
{
    public static function for(string $api, string $hint = ''): self
    {
        $msg = \sprintf('ephpm/mysqli-shim does not implement %s', $api);
        if ($hint !== '') {
            $msg .= ' — ' . $hint;
        }

        return new self($msg . '. See the coverage matrix in the README.');
    }
}
