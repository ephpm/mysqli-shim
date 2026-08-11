<?php

declare(strict_types=1);

namespace Ephpm\Mysqli\Tests;

use Ephpm\Mysqli\SqliteDbOps;
use PHPUnit\Framework\TestCase;

/**
 * Conformance of the test polyfill to the documented `ephpm_db_*` bridge
 * shapes (crates/ephpm-php/ephpm_wrapper.c in the ephpm repo).
 */
final class SqliteDbOpsTest extends TestCase
{
    private SqliteDbOps $ops;

    protected function setUp(): void
    {
        if (!\extension_loaded('sqlite3')) {
            self::markTestSkipped('pdo_sqlite is required');
        }
        $this->ops = new SqliteDbOps();
        $this->ops->execute('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT UNIQUE)');
    }

    public function testQueryReturnsListOfAssocRowsWithNativeTypes(): void
    {
        $this->ops->execute("INSERT INTO t (v) VALUES ('x')");
        $rows = $this->ops->query('SELECT id, v, 1.5 AS f, NULL AS n FROM t');
        self::assertSame([['id' => 1, 'v' => 'x', 'f' => 1.5, 'n' => null]], $rows);
    }

    public function testQueryOfNoRowsetStatementReturnsEmptyArray(): void
    {
        self::assertSame([], $this->ops->query("INSERT INTO t (v) VALUES ('y')"));
        self::assertSame([], $this->ops->query("SET NAMES 'utf8mb4'"));
    }

    public function testExecuteReturnsOkShape(): void
    {
        $ok = $this->ops->execute('INSERT INTO t (v) VALUES (?)', ['z']);
        self::assertSame(['affected_rows' => 1, 'last_insert_id' => 1], $ok);
    }

    public function testExecuteOfSelectReturnsZeros(): void
    {
        self::assertSame(
            ['affected_rows' => 0, 'last_insert_id' => 0],
            $this->ops->execute('SELECT 1')
        );
    }

    public function testParamTypesBind(): void
    {
        $rows = $this->ops->query(
            'SELECT ? AS i, ? AS f, ? AS s, ? AS n, ? AS b',
            [7, 2.5, 'str', null, true]
        );
        self::assertSame([['i' => 7, 'f' => 2.5, 's' => 'str', 'n' => null, 'b' => 1]], $rows);
    }

    public function testErrorShapeMatchesBridgeContract(): void
    {
        $this->ops->execute("INSERT INTO t (v) VALUES ('dup')");

        try {
            $this->ops->execute("INSERT INTO t (v) VALUES ('dup')");
            self::fail('expected Exception');
        } catch (\Exception $e) {
            self::assertSame(1062, $e->getCode());
            self::assertMatchesRegularExpression('/^SQLSTATE\[23000\]: /', $e->getMessage());
        }

        try {
            $this->ops->query('SELECT * FROM missing');
            self::fail('expected Exception');
        } catch (\Exception $e) {
            self::assertSame(1146, $e->getCode());
            self::assertMatchesRegularExpression('/^SQLSTATE\[42S02\]: /', $e->getMessage());
        }
    }

    public function testUnsupportedParamTypeThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('unsupported parameter type');
        $this->ops->query('SELECT ?', [[1, 2]]);
    }
}
