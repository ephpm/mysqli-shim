<?php

declare(strict_types=1);

namespace Ephpm\Mysqli\Tests;

use Ephpm\Mysqli\Connection;
use Ephpm\Mysqli\NotImplementedException;
use Ephpm\Mysqli\Result;

final class ConnectionTest extends ShimTestCase
{
    public function testConnectionArgsAreAcceptedAndIgnored(): void
    {
        $db = new Connection('db.example.com', 'user', 'secret', 'mydb', 3306, '/tmp/mysql.sock', ops: new \Ephpm\Mysqli\SqliteDbOps());
        self::assertTrue($db->ping());
        self::assertTrue($db->select_db('anything'));
        self::assertTrue($db->set_charset('utf8mb4'));
    }

    public function testInsertReturnsTrueWithMetadata(): void
    {
        $this->createPeopleTable($this->db);
        $ok = $this->db->query("INSERT INTO people (name) VALUES ('Zed')");
        self::assertTrue($ok);
        self::assertSame(1, $this->db->affected_rows);
        self::assertSame(1, $this->db->insert_id);

        $ok = $this->db->query("INSERT INTO people (name) VALUES ('Yara')");
        self::assertTrue($ok);
        self::assertSame(2, $this->db->insert_id);
    }

    public function testSelectReturnsResultWithNativeTypes(): void
    {
        $this->seedPeople($this->db);
        $result = $this->db->query('SELECT name, age, score, email FROM people ORDER BY id');
        self::assertInstanceOf(Result::class, $result);
        self::assertSame(3, $result->num_rows);
        self::assertSame(4, $result->field_count);
        self::assertSame(3, $this->db->affected_rows, 'affected_rows mirrors num_rows after SELECT');
        self::assertSame(0, $this->db->insert_id, 'insert_id resets after SELECT');

        $row = $result->fetch_assoc();
        self::assertSame(['name' => 'Alice', 'age' => 30, 'score' => 91.5, 'email' => 'alice@example.com'], $row);
        self::assertIsInt($row['age']);
        self::assertIsFloat($row['score']);

        $row3 = $result->fetch_assoc();
        $row3 = $result->fetch_assoc();
        self::assertNull($row3['age'], 'NULL comes back as native null');
        self::assertNull($result->fetch_assoc(), 'exhausted result returns null');
    }

    public function testZeroRowSelectIsAnEmptyResultNotTrue(): void
    {
        $this->createPeopleTable($this->db);
        $result = $this->db->query('SELECT * FROM people');
        self::assertInstanceOf(Result::class, $result);
        self::assertSame(0, $result->num_rows);
        // Column metadata now comes from the executed statement
        // (ephpm_db_run(), issue #262), so a zero-row rowset still reports
        // its five columns (id, name, age, score, email).
        self::assertSame(5, $result->field_count);
        self::assertSame(
            ['id', 'name', 'age', 'score', 'email'],
            \array_column($result->fetch_fields(), 'name'),
        );
    }

    public function testUpdateAndDeleteReportAffectedRows(): void
    {
        $this->seedPeople($this->db);
        self::assertTrue($this->db->query('UPDATE people SET age = age + 1 WHERE age IS NOT NULL'));
        self::assertSame(2, $this->db->affected_rows);
        self::assertTrue($this->db->query('DELETE FROM people'));
        self::assertSame(3, $this->db->affected_rows);
    }

    public function testRealQueryAndStoreResult(): void
    {
        $this->seedPeople($this->db);
        self::assertTrue($this->db->real_query('SELECT name FROM people ORDER BY id'));
        $result = $this->db->store_result();
        self::assertInstanceOf(Result::class, $result);
        self::assertSame(3, $result->num_rows);
        self::assertFalse($this->db->store_result(), 'second store_result has nothing staged');

        self::assertTrue($this->db->real_query("INSERT INTO people (name) VALUES ('Dave')"));
        self::assertFalse($this->db->store_result(), 'no rowset after INSERT');
    }

    public function testEscaping(): void
    {
        $db = $this->db;
        // Single quote is DOUBLED ('') — litewire's tenant parser rejects a
        // backslash-escaped `\'` as malformed SQL (db-wordpress issue #1).
        self::assertSame("''", $db->real_escape_string("'"));
        self::assertSame('\\"', $db->real_escape_string('"'));
        self::assertSame('\\\\', $db->real_escape_string('\\'));
        self::assertSame('\\n', $db->real_escape_string("\n"));
        self::assertSame('\\r', $db->real_escape_string("\r"));
        self::assertSame('\\0', $db->real_escape_string("\0"));
        self::assertSame('\\Z', $db->real_escape_string("\x1a"));
        self::assertSame(
            "O''Brien said \\\"hi\\\"",
            $db->escape_string('O\'Brien said "hi"')
        );
        self::assertSame('plain text', $db->real_escape_string('plain text'));
    }

    public function testEscapedApostropheRoundTripsThroughAQuotedLiteral(): void
    {
        // The doubled single quote ('') is valid in BOTH the MySQL dialect
        // litewire parses and raw SQLite, so it survives being embedded in a
        // quoted literal and read back — the concrete win of doubling over
        // the old `\'`, which litewire would have rejected as malformed SQL.
        // (Backslash escapes are MySQL-only and are decoded by litewire, not
        // by the raw-SQLite test backend, so they are not round-tripped here.)
        $this->createPeopleTable($this->db);
        $escaped = $this->db->real_escape_string("O'Brien");
        self::assertSame("O''Brien", $escaped);

        self::assertTrue($this->db->query("INSERT INTO people (name) VALUES ('{$escaped}')"));
        $row = $this->db->query('SELECT name FROM people')->fetch_assoc();
        self::assertSame("O'Brien", $row['name']);
    }

    public function testServerIdentity(): void
    {
        self::assertSame('8.0.36-litewire', $this->db->get_server_info());
        self::assertSame('8.0.36-litewire', $this->db->server_info);
        self::assertSame(80036, $this->db->server_version);
        self::assertSame('utf8mb4', $this->db->character_set_name());
        self::assertSame('utf8mb4', $this->db->get_charset()->charset);
        self::assertSame(0, $this->db->connect_errno);
        self::assertNull($this->db->connect_error);
        self::assertSame(0, $this->db->warning_count);
    }

    public function testThreadIdIsStablePerConnectionAndUnique(): void
    {
        $a = $this->db->thread_id;
        self::assertSame($a, $this->db->thread_id);
        self::assertIsInt($a);
        self::assertGreaterThan(0, $a);
        $other = $this->connect();
        self::assertNotSame($a, $other->thread_id);
    }

    public function testSetStatementsAreNoOps(): void
    {
        // Routed through execute; the (polyfilled) bridge answers OK.
        self::assertTrue($this->db->query("SET NAMES 'utf8mb4'"));
        self::assertTrue($this->db->query('SET SESSION sql_mode = "STRICT_ALL_TABLES"'));
    }

    public function testCloseThenUseThrowsError(): void
    {
        self::assertTrue($this->db->close());
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('already closed');
        $this->db->query('SELECT 1');
    }

    public function testMultiQueryIsNotImplemented(): void
    {
        $this->expectException(NotImplementedException::class);
        $this->expectExceptionMessage('multi_query');
        $this->db->multi_query('SELECT 1; SELECT 2');
    }

    public function testMoreAndNextResultAreAlwaysFalse(): void
    {
        self::assertFalse($this->db->more_results());
        self::assertFalse($this->db->next_result());
    }

    public function testResultIsIterable(): void
    {
        $this->seedPeople($this->db);
        $result = $this->db->query('SELECT name FROM people ORDER BY id');
        \assert($result instanceof Result);
        $names = [];
        foreach ($result as $row) {
            $names[] = $row['name'];
        }
        self::assertSame(['Alice', 'Bob', 'Carol'], $names);
    }
}
