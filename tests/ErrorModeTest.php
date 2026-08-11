<?php

declare(strict_types=1);

namespace Ephpm\Mysqli\Tests;

use Ephpm\Mysqli\Report;
use Ephpm\Mysqli\SqlException;
use Ephpm\Mysqli\Statement;

final class ErrorModeTest extends ShimTestCase
{
    public function testDefaultModeThrowsSqlException(): void
    {
        $this->seedPeople($this->db);

        try {
            $this->db->query("INSERT INTO people (name, email) VALUES ('Dup', 'alice@example.com')");
            self::fail('expected SqlException');
        } catch (SqlException $e) {
            self::assertSame(1062, $e->getCode(), 'MySQL errno for duplicate key');
            self::assertSame('23000', $e->getSqlState());
            self::assertStringContainsString('UNIQUE', $e->getMessage());
            self::assertStringNotContainsString('SQLSTATE', $e->getMessage(), 'message is the bare backend message');
        }

        // Error state recorded on the connection too.
        self::assertSame(1062, $this->db->errno);
        self::assertSame('23000', $this->db->sqlstate);
        self::assertStringContainsString('UNIQUE', $this->db->error);
        self::assertSame(1062, $this->db->error_list[0]['errno']);
    }

    public function testShimExceptionIsARuntimeExceptionLikeTheRealOne(): void
    {
        // The real mysqli_sql_exception is final (PHP 8.4), so the shim
        // cannot extend it when ext-mysqli is loaded. Both extend
        // RuntimeException, which is the widest catch that works in
        // every environment.
        try {
            $this->db->query('SELECT * FROM nonexistent_table');
            self::fail('expected exception');
        } catch (\RuntimeException $e) {
            self::assertInstanceOf(SqlException::class, $e);
            self::assertSame(1146, $e->getCode());
            self::assertSame('42S02', $e->getSqlState());
        }
    }

    public function testReportOffReturnsFalseAndSetsErrorState(): void
    {
        Report::set(Report::OFF);
        $result = $this->db->query('SELECT * FROM nonexistent_table');
        self::assertFalse($result);
        self::assertSame(1146, $this->db->errno);
        self::assertSame('42S02', $this->db->sqlstate);
        self::assertStringContainsString('no such table', $this->db->error);

        // A successful statement clears the error state.
        self::assertTrue($this->db->query('CREATE TABLE t (a INTEGER)'));
        self::assertSame(0, $this->db->errno);
        self::assertSame('', $this->db->error);
        self::assertSame('00000', $this->db->sqlstate);
        self::assertSame([], $this->db->error_list);
    }

    public function testReportErrorWithoutStrictWarnsAndReturnsFalse(): void
    {
        Report::set(Report::ERROR);
        $warnings = [];
        \set_error_handler(static function (int $errno, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;

            return true;
        }, \E_USER_WARNING);

        try {
            $result = $this->db->query('SELECT * FROM nonexistent_table');
        } finally {
            \restore_error_handler();
        }

        self::assertFalse($result);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('42S02', $warnings[0]);
        self::assertStringContainsString('1146', $warnings[0]);
    }

    public function testStatementErrorsFollowReportMode(): void
    {
        Report::set(Report::OFF);
        $stmt = $this->db->prepare('SELECT * FROM nonexistent_table WHERE a = ?');
        \assert($stmt instanceof Statement);
        $v = 1;
        $stmt->bind_param('i', $v);
        self::assertFalse($stmt->execute());
        self::assertSame(1146, $stmt->errno);
        self::assertSame('42S02', $stmt->sqlstate);
        self::assertStringContainsString('no such table', $stmt->error);
        self::assertSame(1146, $stmt->error_list[0]['errno']);
        self::assertSame(1146, $this->db->errno, 'connection error state mirrors the statement');

        Report::set(Report::ERROR | Report::STRICT);
        $this->expectException(SqlException::class);
        $stmt->execute();
    }

    public function testSyntaxErrorMapsTo1064(): void
    {
        try {
            $this->db->query('SELEKT wat');
            self::fail('expected SqlException');
        } catch (SqlException $e) {
            self::assertSame(1064, $e->getCode());
            self::assertSame('42000', $e->getSqlState());
        }
    }

    public function testPrepareDoesNotValidateSql(): void
    {
        // Fidelity limit, documented in the README: the bridge has no
        // server-side prepare, so errors surface at execute() time.
        $stmt = $this->db->prepare('SELEKT definitely not sql');
        self::assertInstanceOf(Statement::class, $stmt);
        $this->expectException(SqlException::class);
        $stmt->execute();
    }
}
