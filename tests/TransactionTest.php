<?php

declare(strict_types=1);

namespace Ephpm\Mysqli\Tests;

final class TransactionTest extends ShimTestCase
{
    private function countPeople(): int
    {
        return $this->db->query('SELECT COUNT(*) AS c FROM people')->fetch_assoc()['c'];
    }

    public function testBeginCommitPersists(): void
    {
        $this->createPeopleTable($this->db);
        self::assertTrue($this->db->begin_transaction());
        $this->db->query("INSERT INTO people (name) VALUES ('T1')");
        self::assertTrue($this->db->commit());
        self::assertSame(1, $this->countPeople());
    }

    public function testBeginRollbackReverts(): void
    {
        $this->createPeopleTable($this->db);
        self::assertTrue($this->db->begin_transaction());
        $this->db->query("INSERT INTO people (name) VALUES ('T2')");
        self::assertTrue($this->db->rollback());
        self::assertSame(0, $this->countPeople());
    }

    public function testCommitWithoutTransactionIsNoOpTrue(): void
    {
        self::assertTrue($this->db->commit());
        self::assertTrue($this->db->rollback());
    }

    public function testRawBeginViaQueryIsTracked(): void
    {
        $this->createPeopleTable($this->db);
        self::assertTrue($this->db->query('BEGIN'));
        $this->db->query("INSERT INTO people (name) VALUES ('T3')");
        self::assertTrue($this->db->rollback(), 'rollback() sends SQL because query(BEGIN) was tracked');
        self::assertSame(0, $this->countPeople());
    }

    public function testAutocommitOffOpensImplicitTransactions(): void
    {
        $this->createPeopleTable($this->db);
        self::assertTrue($this->db->autocommit(false));

        $this->db->query("INSERT INTO people (name) VALUES ('A1')");
        self::assertTrue($this->db->rollback());
        self::assertSame(0, $this->countPeople(), 'implicit BEGIN made the insert rollbackable');

        // After COMMIT/ROLLBACK the next statement opens a fresh transaction.
        $this->db->query("INSERT INTO people (name) VALUES ('A2')");
        self::assertTrue($this->db->commit());
        self::assertSame(1, $this->countPeople());

        $this->db->query("INSERT INTO people (name) VALUES ('A3')");
        self::assertTrue($this->db->rollback());
        self::assertSame(1, $this->countPeople());
    }

    public function testEnablingAutocommitCommitsOpenTransaction(): void
    {
        $this->createPeopleTable($this->db);
        $this->db->autocommit(false);
        $this->db->query("INSERT INTO people (name) VALUES ('A4')");
        self::assertTrue($this->db->autocommit(true));
        self::assertSame(1, $this->countPeople());

        // Back in autocommit mode: rollback is a no-op, the row stays.
        $this->db->query("INSERT INTO people (name) VALUES ('A5')");
        $this->db->rollback();
        self::assertSame(2, $this->countPeople());
    }

    public function testBridgeInTransactionStateIsAuthoritativeWhenAvailable(): void
    {
        // A backend that answers inTransaction() (as SapiDbOps does from the
        // native ephpm_db_in_transaction()) overrides the shim's keyword
        // tracking. Here the bridge insists it is NOT in a transaction even
        // after a raw BEGIN, so commit() must treat it as a no-op and send no
        // COMMIT — the opposite of what keyword tracking alone would do.
        $ops = new class(new \Ephpm\Mysqli\SqliteDbOps()) implements \Ephpm\Mysqli\DbOpsInterface {
            /** @var list<string> */
            public array $recorded = [];

            public ?bool $bridgeInTx = false;

            public function __construct(private readonly \Ephpm\Mysqli\DbOpsInterface $inner)
            {
            }

            public function query(string $sql, array $params = []): array
            {
                $this->recorded[] = $sql;

                return $this->inner->query($sql, $params);
            }

            public function execute(string $sql, array $params = []): array
            {
                $this->recorded[] = $sql;

                return $this->inner->execute($sql, $params);
            }

            public function run(string $sql, array $params = []): array
            {
                $this->recorded[] = $sql;

                return $this->inner->run($sql, $params);
            }

            public function inTransaction(): ?bool
            {
                return $this->bridgeInTx;
            }
        };

        $db = new \Ephpm\Mysqli\Connection(ops: $ops);
        $this->createPeopleTable($db);

        self::assertTrue($db->query('BEGIN'));
        // Authoritative state is false, so commit() is a no-op and emits no SQL.
        self::assertTrue($db->commit());
        self::assertNotContains('COMMIT', $ops->recorded);
    }

    public function testSavepointsPassThroughAsSql(): void
    {
        // SQLite (like MySQL) supports SAVEPOINT / ROLLBACK TO.
        $this->createPeopleTable($this->db);
        $this->db->begin_transaction();
        $this->db->query("INSERT INTO people (name) VALUES ('S1')");
        self::assertTrue($this->db->savepoint('sp1'));
        $this->db->query("INSERT INTO people (name) VALUES ('S2')");
        self::assertTrue($this->db->query('ROLLBACK TO SAVEPOINT `sp1`'));
        self::assertTrue($this->db->commit(), 'ROLLBACK TO must not clear the tracked transaction');
        self::assertSame(1, $this->countPeople());
    }
}
