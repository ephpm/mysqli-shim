<?php

declare(strict_types=1);

namespace Ephpm\Mysqli\Tests;

use Ephpm\Mysqli\Result;
use Ephpm\Mysqli\Statement;

final class StatementTest extends ShimTestCase
{
    public function testPlaceholderCountIgnoresLiteralsAndComments(): void
    {
        $stmt = $this->db->prepare(
            "SELECT '?' AS q, `weird?col` FROM t -- trailing ? comment\n"
            . "/* block ? */ WHERE a = ? AND b = ? # another ?"
        );
        \assert($stmt instanceof Statement);
        self::assertSame(2, $stmt->param_count);
    }

    public function testBindEachTypeAndRoundTrip(): void
    {
        $this->createPeopleTable($this->db);
        $stmt = $this->db->prepare('INSERT INTO people (name, age, score, email) VALUES (?, ?, ?, ?)');
        \assert($stmt instanceof Statement);
        self::assertSame(4, $stmt->param_count);

        $name = 'Dana';
        $age = 40;
        $score = 77.5;
        $email = 'dana@example.com';
        self::assertTrue($stmt->bind_param('sids', $name, $age, $score, $email));
        self::assertTrue($stmt->execute());
        self::assertSame(1, $stmt->affected_rows);
        self::assertSame(1, $stmt->insert_id);
        self::assertSame(1, $this->db->insert_id, 'connection mirrors statement OK data');

        // References: change the bound variables and re-execute.
        $name = 'Eve';
        $age = 22;
        $email = 'eve@example.com';
        self::assertTrue($stmt->execute());
        self::assertSame(2, $stmt->insert_id);
        self::assertTrue($stmt->close());

        $check = $this->db->prepare('SELECT name, age, score FROM people WHERE email = ?');
        \assert($check instanceof Statement);
        $check->bind_param('s', $email);
        self::assertTrue($check->execute());
        $result = $check->get_result();
        self::assertInstanceOf(Result::class, $result);
        self::assertSame(['name' => 'Eve', 'age' => 22, 'score' => 77.5], $result->fetch_assoc());
    }

    public function testTypeCoercionFollowsTypeString(): void
    {
        $stmt = $this->db->prepare('SELECT ? AS i, ? AS d, ? AS s');
        \assert($stmt instanceof Statement);
        $i = '42';   // 'i' coerces to int
        $d = '3.5';  // 'd' coerces to float
        $s = 42;     // 's' coerces to string
        $stmt->bind_param('ids', $i, $d, $s);
        self::assertTrue($stmt->execute());
        $row = $stmt->get_result()->fetch_assoc();
        self::assertSame(42, $row['i']);
        self::assertSame(3.5, $row['d']);
        self::assertSame('42', $row['s']);
    }

    public function testNullBindsAsNullRegardlessOfType(): void
    {
        $stmt = $this->db->prepare('SELECT ? AS a');
        \assert($stmt instanceof Statement);
        $v = null;
        $stmt->bind_param('i', $v);
        self::assertTrue($stmt->execute());
        self::assertNull($stmt->get_result()->fetch_assoc()['a']);
    }

    public function testBlobRoundTrip(): void
    {
        $this->db->query('CREATE TABLE blobs (id INTEGER PRIMARY KEY, data BLOB)');
        $stmt = $this->db->prepare('INSERT INTO blobs (data) VALUES (?)');
        \assert($stmt instanceof Statement);
        $blob = "\x00\x01\xFF binary \x1a data";
        $stmt->bind_param('b', $blob);
        self::assertTrue($stmt->execute());

        $out = $this->db->query('SELECT data FROM blobs')->fetch_row()[0];
        self::assertSame($blob, $out);
    }

    public function testExecuteWithParamsArraySendsStrings(): void
    {
        $stmt = $this->db->prepare('SELECT ? AS a, ? AS b');
        \assert($stmt instanceof Statement);
        self::assertTrue($stmt->execute([7, null]));
        $row = $stmt->get_result()->fetch_assoc();
        self::assertSame('7', $row['a'], 'PHP 8.1-style execute() params are sent as strings');
        self::assertNull($row['b']);
    }

    public function testGetResultIsFalseForNonRowsetAndConsumedOnce(): void
    {
        $this->createPeopleTable($this->db);
        $stmt = $this->db->prepare("INSERT INTO people (name) VALUES ('X')");
        \assert($stmt instanceof Statement);
        self::assertTrue($stmt->execute());
        self::assertFalse($stmt->get_result());

        $sel = $this->db->prepare('SELECT name FROM people');
        \assert($sel instanceof Statement);
        self::assertTrue($sel->execute());
        self::assertInstanceOf(Result::class, $sel->get_result());
        self::assertFalse($sel->get_result(), 'rowset already consumed');
        self::assertTrue($sel->execute());
        self::assertInstanceOf(Result::class, $sel->get_result(), 'fresh after re-execute');
    }

    public function testBindResultAndFetch(): void
    {
        $this->seedPeople($this->db);
        $stmt = $this->db->prepare('SELECT name, age FROM people ORDER BY id');
        \assert($stmt instanceof Statement);
        self::assertTrue($stmt->execute());
        self::assertSame(3, $stmt->num_rows);

        $name = null;
        $age = null;
        self::assertTrue($stmt->bind_result($name, $age));
        self::assertTrue($stmt->store_result(), 'store_result is a buffered no-op');

        $seen = [];
        while ($stmt->fetch() === true) {
            $seen[] = [$name, $age];
        }
        self::assertSame([['Alice', 30], ['Bob', 25], ['Carol', null]], $seen);
        self::assertNull($stmt->fetch(), 'exhausted');
    }

    public function testBindParamCountMismatches(): void
    {
        $stmt = $this->db->prepare('SELECT ? AS a, ? AS b');
        \assert($stmt instanceof Statement);
        $a = 1;

        try {
            $stmt->bind_param('is', $a);
            self::fail('expected ArgumentCountError');
        } catch (\ArgumentCountError $e) {
            self::assertStringContainsString('type definition string', $e->getMessage());
        }

        try {
            $b = 2;
            $c = 3;
            $stmt->bind_param('iii', $a, $b, $c);
            self::fail('expected ArgumentCountError');
        } catch (\ArgumentCountError $e) {
            self::assertStringContainsString('number of variables must match', $e->getMessage());
        }
    }

    public function testInvalidTypeCharacterIsRejected(): void
    {
        $stmt = $this->db->prepare('SELECT ? AS a');
        \assert($stmt instanceof Statement);
        $v = 1;
        $this->expectException(\ValueError::class);
        $stmt->bind_param('x', $v);
    }

    public function testExecuteWithoutBindingRequiredParamsThrows(): void
    {
        $stmt = $this->db->prepare('SELECT ? AS a');
        \assert($stmt instanceof Statement);
        $this->expectException(\ArgumentCountError::class);
        $stmt->execute();
    }
}
