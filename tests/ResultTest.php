<?php

declare(strict_types=1);

namespace Ephpm\Mysqli\Tests;

use Ephpm\Mysqli\Protocol;
use Ephpm\Mysqli\Result;

final class ResultTest extends ShimTestCase
{
    private function people(): Result
    {
        $this->seedPeople($this->db);
        $result = $this->db->query('SELECT id, name, age, score FROM people ORDER BY id');
        \assert($result instanceof Result);

        return $result;
    }

    public function testFetchRowAndArrayModes(): void
    {
        $result = $this->people();

        $row = $result->fetch_row();
        self::assertSame([1, 'Alice', 30, 91.5], $row);

        $assoc = $result->fetch_array(Protocol::ASSOC);
        self::assertSame(['id' => 2, 'name' => 'Bob', 'age' => 25, 'score' => 82.25], $assoc);

        $result->data_seek(1);
        $num = $result->fetch_array(Protocol::NUM);
        self::assertSame([2, 'Bob', 25, 82.25], $num);

        $result->data_seek(1);
        $both = $result->fetch_array(); // default BOTH
        self::assertSame(2, $both[0]);
        self::assertSame(2, $both['id']);
        self::assertSame('Bob', $both[1]);
        self::assertSame('Bob', $both['name']);
    }

    public function testFetchObject(): void
    {
        $result = $this->people();
        $obj = $result->fetch_object();
        self::assertInstanceOf(\stdClass::class, $obj);
        self::assertSame('Alice', $obj->name);
        self::assertSame(30, $obj->age);
    }

    public function testFetchAllDefaultsToNum(): void
    {
        $result = $this->people();
        $all = $result->fetch_all();
        self::assertCount(3, $all);
        self::assertSame([1, 'Alice', 30, 91.5], $all[0]);

        $result->data_seek(0);
        $allAssoc = $result->fetch_all(Protocol::ASSOC);
        self::assertSame('Carol', $allAssoc[2]['name']);
    }

    public function testFetchAllContinuesFromCursor(): void
    {
        $result = $this->people();
        $result->fetch_assoc();
        self::assertCount(2, $result->fetch_all(Protocol::ASSOC));
    }

    public function testFetchColumn(): void
    {
        $result = $this->people();
        self::assertSame(1, $result->fetch_column());
        self::assertSame('Bob', $result->fetch_column(1));
        $result->fetch_column();
        self::assertFalse($result->fetch_column(), 'exhausted');
    }

    public function testDataSeekBounds(): void
    {
        $result = $this->people();
        self::assertTrue($result->data_seek(2));
        self::assertSame('Carol', $result->fetch_assoc()['name']);
        self::assertFalse($result->data_seek(3));
        self::assertFalse($result->data_seek(-1));
    }

    public function testFieldMetadataBestEffort(): void
    {
        $result = $this->people();
        $fields = $result->fetch_fields();
        self::assertCount(4, $fields);
        self::assertSame(['id', 'name', 'age', 'score'], \array_column($fields, 'name'));
        self::assertSame(['id', 'name', 'age', 'score'], \array_column($fields, 'orgname'));
        self::assertSame(Protocol::TYPE_LONGLONG, $fields[0]->type);
        self::assertSame(Protocol::TYPE_VAR_STRING, $fields[1]->type);
        self::assertSame(Protocol::TYPE_LONGLONG, $fields[2]->type);
        self::assertSame(Protocol::TYPE_DOUBLE, $fields[3]->type);
        self::assertSame(Protocol::NUM_FLAG, $fields[0]->flags);
        self::assertSame(Protocol::CHARSETNR_UTF8MB4, $fields[1]->charsetnr);
        self::assertSame(5, $fields[1]->max_length, "longest name is 'Alice'");

        // cursor-based fetch_field
        $first = $result->fetch_field();
        self::assertSame('id', $first->name);
        self::assertSame(1, $result->current_field);
        self::assertTrue($result->field_seek(3));
        self::assertSame('score', $result->fetch_field()->name);

        $direct = $result->fetch_field_direct(1);
        self::assertSame('name', $direct->name);
        self::assertFalse($result->fetch_field_direct(99));
    }

    public function testLengthsProperty(): void
    {
        $this->seedPeople($this->db);
        $result = $this->db->query('SELECT name, age FROM people WHERE name = \'Carol\'');
        \assert($result instanceof Result);
        self::assertNull($result->lengths, 'null before any fetch');
        $result->fetch_assoc();
        self::assertSame([5, 0], $result->lengths, 'strlen per column; null counts 0');
    }

    public function testFreedResultThrowsOnUse(): void
    {
        $result = $this->people();
        $result->free();
        $this->expectException(\Error::class);
        $result->fetch_assoc();
    }
}
