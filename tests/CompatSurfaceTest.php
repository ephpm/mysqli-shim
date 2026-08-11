<?php

declare(strict_types=1);

namespace Ephpm\Mysqli\Tests;

use Ephpm\Mysqli\Compat;
use Ephpm\Mysqli\Protocol;
use Ephpm\Mysqli\Report;
use Ephpm\Mysqli\Result;
use PHPUnit\Framework\TestCase;

/**
 * Tests the guarded global surface (src/compat/mysqli.php).
 *
 * On a normal dev/CI PHP the real ext-mysqli is loaded, so the guarded
 * definitions are inert here — this class asserts (a) the guard itself,
 * (b) constant-value fidelity against the real extension, and (c) the
 * full global surface end to end in a CHILD process started with
 * `php -n` (no ext-mysqli).
 */
final class CompatSurfaceTest extends TestCase
{
    public function testCompatFileIsInertWhenExtMysqliIsLoaded(): void
    {
        if (!\extension_loaded('mysqli')) {
            self::markTestSkipped('needs the real ext-mysqli');
        }
        // The compat file was already loaded by composer's autoload.files;
        // the real extension's classes must have won.
        self::assertTrue((new \ReflectionClass(\mysqli::class))->isInternal());
        self::assertTrue((new \ReflectionClass(\mysqli_result::class))->isInternal());
        self::assertTrue((new \ReflectionFunction('mysqli_connect'))->isInternal());

        // And re-including it explicitly must be a no-op, not a fatal.
        require __DIR__ . '/../src/compat/mysqli.php';
        self::assertTrue((new \ReflectionClass(\mysqli::class))->isInternal());
    }

    public function testConstantValuesMatchTheRealExtension(): void
    {
        if (!\extension_loaded('mysqli')) {
            self::markTestSkipped('needs the real ext-mysqli to compare against');
        }
        foreach (Compat::CONSTANTS as $name => $value) {
            self::assertTrue(\defined($name), "real ext should define {$name}");
            self::assertSame(\constant($name), $value, "value of {$name}");
        }
    }

    public function testInternalConstantsMatchTheRealExtension(): void
    {
        if (!\extension_loaded('mysqli')) {
            self::markTestSkipped('needs the real ext-mysqli to compare against');
        }
        self::assertSame(\MYSQLI_ASSOC, Protocol::ASSOC);
        self::assertSame(\MYSQLI_NUM, Protocol::NUM);
        self::assertSame(\MYSQLI_BOTH, Protocol::BOTH);
        self::assertSame(\MYSQLI_TYPE_LONGLONG, Protocol::TYPE_LONGLONG);
        self::assertSame(\MYSQLI_TYPE_DOUBLE, Protocol::TYPE_DOUBLE);
        self::assertSame(\MYSQLI_TYPE_VAR_STRING, Protocol::TYPE_VAR_STRING);
        self::assertSame(\MYSQLI_TYPE_NULL, Protocol::TYPE_NULL);
        self::assertSame(\MYSQLI_NUM_FLAG, Protocol::NUM_FLAG);
        self::assertSame(\MYSQLI_REPORT_OFF, Report::OFF);
        self::assertSame(\MYSQLI_REPORT_ERROR, Report::ERROR);
        self::assertSame(\MYSQLI_REPORT_STRICT, Report::STRICT);
        self::assertSame(\MYSQLI_REPORT_ALL, Report::ALL);
        self::assertSame(\MYSQLI_STORE_RESULT, 0);
        self::assertSame(\MYSQLI_USE_RESULT, 1);
    }

    public function testResultDefaultsMatchDocumentedMysqliDefaults(): void
    {
        $r = new \ReflectionMethod(Result::class, 'fetch_all');
        self::assertSame(Protocol::NUM, $r->getParameters()[0]->getDefaultValue());
        $r = new \ReflectionMethod(Result::class, 'fetch_array');
        self::assertSame(Protocol::BOTH, $r->getParameters()[0]->getDefaultValue());
    }

    public function testGlobalSurfaceInChildProcessWithoutExtMysqli(): void
    {
        $php = \PHP_BINARY;
        $autoload = \realpath(__DIR__ . '/../vendor/autoload.php');
        $script = \realpath(__DIR__ . '/fixtures/compat_child.php');
        self::assertNotFalse($autoload);
        self::assertNotFalse($script);

        $cmd = \sprintf(
            '%s -n -d extension_dir=%s -d extension=sqlite3 %s %s 2>&1',
            \escapeshellarg($php),
            \escapeshellarg((string) \ini_get('extension_dir')),
            \escapeshellarg($script),
            \escapeshellarg($autoload)
        );
        \exec($cmd, $lines, $exitCode);
        $output = \implode("\n", $lines);
        self::assertSame(0, $exitCode, "child failed:\n{$output}");

        // The child may emit startup noise before the JSON; take the last line.
        $json = (string) \end($lines);
        $data = \json_decode($json, true);
        self::assertIsArray($data, "unparseable child output:\n{$output}");

        if (isset($data['skip'])) {
            self::markTestSkipped('child: ' . $data['skip']);
        }

        $report = $data['report'];
        self::assertTrue($report['class_mysqli_is_shim'], 'global mysqli is the shim Connection');
        self::assertSame(
            ['mysqli_result' => true, 'mysqli_stmt' => true, 'mysqli_sql_exception' => true],
            $report['classes']
        );
        self::assertSame(
            [
                'MYSQLI_ASSOC' => 1,
                'MYSQLI_NUM' => 2,
                'MYSQLI_BOTH' => 3,
                'MYSQLI_REPORT_ERROR' => 1,
                'MYSQLI_TYPE_LONGLONG' => 8,
            ],
            $report['constants']
        );
        self::assertTrue($report['connect_ok']);
        self::assertSame('8.0.36-litewire', $report['server_info']);
        self::assertSame(80036, $report['server_version']);
        self::assertSame(1, $report['insert_id']);
        self::assertSame(1, $report['affected_rows']);
        self::assertSame(2, $report['stmt_insert_id']);
        self::assertTrue($report['result_is_mysqli_result']);
        self::assertSame(2, $report['num_rows']);
        self::assertSame(
            [['id' => 1, 'name' => 'one'], ['id' => 2, 'name' => 'two']],
            $report['rows_assoc']
        );
        self::assertSame([1, 'one'], $report['row_num']);
        self::assertSame(['code' => 1062, 'sqlstate' => '23000'], $report['dup_error']);
        self::assertFalse($report['off_mode_result']);
        self::assertSame(1146, $report['off_mode_errno']);
        self::assertSame('42S02', $report['off_mode_sqlstate']);
        self::assertSame("O\\'Brien\\n", $report['escape']);
        self::assertTrue($report['close']);
        self::assertTrue($report['double_include_ok']);
    }
}
