<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group, PreserveGlobalState, RunTestsInSeparateProcesses};
use PHPUnit\TextUI\Configuration\Registry;
use RuntimeException;
use Yii;
use yii2\extensions\psrbridge\tests\provider\WorkerRegressionProvider;
use yii2\extensions\psrbridge\tests\support\TestCase;

use function count;
use function fclose;
use function glob;
use function proc_close;
use function proc_open;
use function rmdir;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function yii2\extensions\psrbridge\tests\support\scenario\run;

use const PHP_BINARY;

/**
 * Exercises worker contracts in fresh PHP processes with native session cookies disabled.
 */
#[Group('http')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WorkerRegressionTest extends TestCase
{
    public function testHandlesFatalShutdownOnceAfterManyRequests(): void
    {
        self::assertSame(
            ['shutdownCalls' => 1],
            $this->runScenario('fatal-shutdown', 1),
            'Shutdown must run exactly once.',
        );
    }

    /**
     * @see WorkerRegressionProvider::workerContracts()
     *
     * @param array<string, mixed> $expected
     */
    #[DataProviderExternal(WorkerRegressionProvider::class, 'workerContracts')]
    public function testPreservesWorkerContract(string $scenario, array $expected): void
    {
        self::assertSame(
            $expected,
            $this->runScenario($scenario),
            "Worker contract mismatch for {$scenario}.",
        );
    }

    public function testReleasesCompletedRequestObjects(): void
    {
        $alive = $this->runScenario('memory');

        foreach (['responses', 'handlers', 'sessions'] as $type) {
            $value = $alive[$type] ?? null;

            self::assertIsInt(
                $value,
                "Completed {$type} count must be an integer.",
            );
            self::assertLessThanOrEqual(
                1,
                $value,
                "Completed {$type} must be released.",
            );
        }
    }

    /**
     * @return array<mixed>
     */
    private function runScenario(string $scenario, int $expectedExit = 0): array
    {
        $directory = sys_get_temp_dir() . '/bridge-regression-' . uniqid();

        if ($scenario !== 'fatal-shutdown') {
            mkdir($directory, 0o700, true);
            ini_set('session.use_cookies', '0');
            ini_set('session.save_path', $directory);

            require_once __DIR__ . '/../support/WorkerScenarios.php';

            try {
                return run($scenario);
            } finally {
                if (Yii::$app !== null && Yii::$app->has('errorHandler', true)) {
                    Yii::$app->errorHandler->unregister();
                }

                if (Yii::$app !== null && Yii::$app->has('session', true)) {
                    Yii::$app->session->close();
                }

                $files = glob($directory . '/*');

                self::assertIsArray(
                    $files,
                    'Temporary session files must be listed.',
                );

                foreach ($files as $file) {
                    unlink($file);
                }

                rmdir($directory);
            }
        }

        // @phpstan-ignore staticMethod.internalClass (The native fatal fixture must reuse PHPUnit 10's active mutant bootstrap.)
        $bootstrap = Registry::get()->bootstrap();

        $process = proc_open(
            [
                PHP_BINARY,
                '-d',
                'session.use_cookies=0',
                '-d',
                "auto_prepend_file={$bootstrap}",
                __DIR__ . '/../support/worker-regressions.php',
                $scenario,
                $directory,
            ],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        self::assertIsResource(
            $process,
            'Native fixture process must start.',
        );

        $stdoutPipe = $pipes[1] ?? throw new RuntimeException('Missing stdout pipe.');
        $stderrPipe = $pipes[2] ?? throw new RuntimeException('Missing stderr pipe.');

        try {
            $stdout = $stderr = '';
            $streams = [1 => $stdoutPipe, 2 => $stderrPipe];

            stream_set_blocking($stdoutPipe, false);
            stream_set_blocking($stderrPipe, false);

            $deadline = microtime(true) + 5;
            $timedOut = false;

            while ($streams !== []) {
                if (microtime(true) > $deadline) {
                    proc_terminate($process, 9);

                    $timedOut = true;

                    break;
                }

                $read = array_values($streams);
                $write = $except = null;

                if (stream_select($read, $write, $except, 0, 200000) === false) {
                    throw new RuntimeException('Cannot read the native fixture output.');
                }

                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream);

                    self::assertIsString(
                        $chunk,
                        'Fixture output chunks must be strings.',
                    );

                    if ($stream === $stdoutPipe) {
                        $stdout = substr($stdout . $chunk, 0, 131072);
                    } else {
                        $stderr = substr($stderr . $chunk, 0, 131072);
                    }
                }

                foreach ($streams as $key => $stream) {
                    if (feof($stream)) {
                        fclose($stream);
                        unset($streams[$key]);
                    }
                }
            }

            foreach ($streams as $stream) {
                fclose($stream);
            }

            $exit = proc_close($process);

            self::assertFalse(
                $timedOut,
                "Native fixture must finish within five seconds: {$stderr}",
            );
            self::assertSame(
                $expectedExit,
                $exit,
                "Unexpected exit code for {$scenario}: {$stderr}",
            );
            self::assertFileExists(
                "{$directory}/shutdown.txt",
                'Shutdown marker must be written.',
            );

            $lines = file("{$directory}/shutdown.txt", FILE_IGNORE_NEW_LINES);

            self::assertIsArray(
                $lines,
                'Shutdown marker lines must be readable.',
            );

            $result = ['shutdownCalls' => count($lines)];

            return $result;
        } finally {
            $files = glob($directory . '/*');

            foreach ($files === false ? [] : $files as $file) {
                unlink($file);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
