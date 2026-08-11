<?php

declare(strict_types=1);

namespace Ephpm\Mysqli\Tests;

use Ephpm\Mysqli\SqlHelper;
use PHPUnit\Framework\TestCase;

final class SqlHelperTest extends TestCase
{
    public function testCountPlaceholders(): void
    {
        self::assertSame(0, SqlHelper::countPlaceholders('SELECT 1'));
        self::assertSame(2, SqlHelper::countPlaceholders('SELECT ? , ?'));
        self::assertSame(1, SqlHelper::countPlaceholders("SELECT '?', \"?\", `?col`, ? FROM t"));
        self::assertSame(1, SqlHelper::countPlaceholders("SELECT ? -- and ? in comment"));
        self::assertSame(1, SqlHelper::countPlaceholders("SELECT ? /* block ? */ # line ?"));
        self::assertSame(1, SqlHelper::countPlaceholders("SELECT 'it''s ?', ?"));
        self::assertSame(1, SqlHelper::countPlaceholders("SELECT 'esc\\'aped ?', ?"));
    }

    public function testProducesRowset(): void
    {
        self::assertTrue(SqlHelper::producesRowset('SELECT 1'));
        self::assertTrue(SqlHelper::producesRowset('  select 1'));
        self::assertTrue(SqlHelper::producesRowset('(SELECT 1) UNION (SELECT 2)'));
        self::assertTrue(SqlHelper::producesRowset('/* hint */ SELECT 1'));
        self::assertTrue(SqlHelper::producesRowset('SHOW TABLES'));
        self::assertTrue(SqlHelper::producesRowset('DESCRIBE t'));
        self::assertTrue(SqlHelper::producesRowset('EXPLAIN SELECT 1'));
        self::assertTrue(SqlHelper::producesRowset('WITH x AS (SELECT 1) SELECT * FROM x'));
        self::assertTrue(SqlHelper::producesRowset('INSERT INTO t (a) VALUES (1) RETURNING id'));

        self::assertFalse(SqlHelper::producesRowset('INSERT INTO t (a) VALUES (1)'));
        self::assertFalse(SqlHelper::producesRowset("INSERT INTO t (a) VALUES ('RETURNING')"));
        self::assertFalse(SqlHelper::producesRowset('UPDATE t SET a = 1'));
        self::assertFalse(SqlHelper::producesRowset('DELETE FROM t'));
        self::assertFalse(SqlHelper::producesRowset('CREATE TABLE t (a INT)'));
        self::assertFalse(SqlHelper::producesRowset("SET NAMES 'utf8mb4'"));
        self::assertFalse(SqlHelper::producesRowset('BEGIN'));
    }

    public function testTxnEffect(): void
    {
        self::assertSame(1, SqlHelper::txnEffect('BEGIN'));
        self::assertSame(1, SqlHelper::txnEffect('start transaction'));
        self::assertSame(-1, SqlHelper::txnEffect('COMMIT'));
        self::assertSame(-1, SqlHelper::txnEffect('ROLLBACK'));
        self::assertSame(0, SqlHelper::txnEffect('ROLLBACK TO SAVEPOINT sp1'));
        self::assertSame(0, SqlHelper::txnEffect('rollback to sp1'));
        self::assertSame(0, SqlHelper::txnEffect('SELECT 1'));
    }
}
