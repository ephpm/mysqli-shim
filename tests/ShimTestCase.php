<?php

declare(strict_types=1);

namespace Ephpm\Mysqli\Tests;

use Ephpm\Mysqli\Connection;
use Ephpm\Mysqli\SqliteDbOps;
use Ephpm\Mysqli\Report;
use PHPUnit\Framework\TestCase;

/**
 * Base class: a Connection backed by the sqlite3 bridge polyfill,
 * with the report mode restored around every test.
 *
 * NOTE: on a normal dev/CI PHP the real ext-mysqli IS loaded, so these
 * tests target the namespaced implementation directly — the guarded
 * global surface is exercised in a child process by CompatSurfaceTest.
 */
abstract class ShimTestCase extends TestCase
{
    protected Connection $db;

    protected function setUp(): void
    {
        if (!\extension_loaded('sqlite3')) {
            self::markTestSkipped('sqlite3 is required for the bridge polyfill');
        }
        Report::set(Report::ERROR | Report::STRICT);
        $this->db = $this->connect();
    }

    protected function tearDown(): void
    {
        Report::set(Report::ERROR | Report::STRICT);
    }

    protected function connect(): Connection
    {
        return new Connection(ops: new SqliteDbOps());
    }

    protected function createPeopleTable(Connection $db): void
    {
        $db->query(
            'CREATE TABLE people ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'name TEXT NOT NULL, '
            . 'age INTEGER, '
            . 'score REAL, '
            . 'email TEXT UNIQUE)'
        );
    }

    protected function seedPeople(Connection $db): void
    {
        $this->createPeopleTable($db);
        $db->query("INSERT INTO people (name, age, score, email) VALUES ('Alice', 30, 91.5, 'alice@example.com')");
        $db->query("INSERT INTO people (name, age, score, email) VALUES ('Bob', 25, 82.25, 'bob@example.com')");
        $db->query("INSERT INTO people (name, age, score, email) VALUES ('Carol', NULL, NULL, 'carol@example.com')");
    }
}
