<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use PHPUnit\Framework\Attributes\{PreserveGlobalState, RunTestsInSeparateProcesses, TestWith};
use RuntimeException;
use Throwable;
use yii2\extensions\psrbridge\http\{ErrorHandler, Response};
use yii2\extensions\psrbridge\tests\support\TestCase;

/**
 * Verifies public PHP handler ownership and fatal-handler resource release.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ErrorLifecycleTest extends TestCase
{
    public function testCliFatalProcessingDoesNotRestoreTheRegistrationDirectory(): void
    {
        $handler = new ErrorHandler();

        $original = getcwd();

        self::assertIsString(
            $original,
            'The current working directory should be a string.',
        );

        $directory = sys_get_temp_dir();

        try {
            $handler->register();

            chdir($directory);
            error_clear_last();

            $handler->handleFatalError();

            self::assertSame(
                $directory,
                getcwd(),
                'The current working directory should be restored to the temporary directory after fatal error handling.',
            );
        } finally {
            chdir($original);

            $handler->unregister();
        }
    }

    public function testConfiguredReserveIsReleasedBeforeFatalProcessing(): void
    {
        $handler = new ErrorHandler(['memoryReserveSize' => 2097152]);

        $before = memory_get_usage();

        try {
            $handler->register();
            $reserved = memory_get_usage();

            self::assertGreaterThan(
                1048576,
                $reserved - $before,
                'The reserved memory should be greater than 1MB before fatal error handling.',
            );

            error_clear_last();

            $handler->handleFatalError();

            self::assertGreaterThan(
                1048576,
                $reserved - memory_get_usage(),
                'The reserved memory should be greater than 1MB after fatal error handling.',
            );
        } finally {
            $handler->unregister();
        }
    }

    public function testFatalProcessingDispatchesTheInheritedShutdownEvent(): void
    {
        require_once __DIR__ . '/../support/FatalErrorProbe.php';

        $handler = new class extends ErrorHandler {
            public Throwable|null $rendered = null;

            public function logException($exception): void {}

            protected function renderException($exception): Response
            {
                $this->rendered = $exception;
                return new Response(['charset' => 'UTF-8']);
            }
        };

        $handler->discardExistingOutput = false;

        $calls = 0;

        $handler->on(
            ErrorHandler::EVENT_SHUTDOWN,
            static function () use (&$calls): void {
                ++$calls;
            },
        );

        \yii2\extensions\psrbridge\tests\support\FatalErrorProbe::$error = [
            'type' => E_ERROR,
            'message' => 'Synthetic fatal record',
            'file' => __FILE__,
            'line' => __LINE__,
        ];

        try {
            $handler->handleFatalError();

            self::assertSame(
                1,
                $calls,
                'The shutdown event should have been dispatched exactly once.',
            );
            self::assertInstanceOf(
                \yii\base\ErrorException::class,
                $handler->rendered,
                'Fatal record must render as an ErrorException.',
            );
            self::assertSame(
                'Synthetic fatal record',
                $handler->rendered->getMessage(),
                'Fatal message must be preserved.',
            );
            self::assertCount(
                1,
                \yii2\extensions\psrbridge\tests\support\FatalErrorProbe::$exitCallbacks,
                'Fatal handling must register one exit callback.',
            );
        } finally {
            \yii2\extensions\psrbridge\tests\support\FatalErrorProbe::$error = null;
        }
    }

    public function testNonCliFatalProcessingRestoresItsRegistrationDirectory(): void
    {
        require_once __DIR__ . '/../support/NonCliSapi.php';

        \yii2\extensions\psrbridge\tests\support\NonCliSapi::configure('yii2\\extensions\\psrbridge\\http');

        $handler = new ErrorHandler();

        $original = getcwd();

        self::assertIsString(
            $original,
            'Working directory must be available for restoration.',
        );

        try {
            $handler->register();

            chdir(sys_get_temp_dir());
            error_clear_last();

            $handler->handleFatalError();

            self::assertSame(
                $original,
                getcwd(),
                'Registration directory must be restored.',
            );
        } finally {
            chdir($original);

            $handler->unregister();
        }
    }

    #[TestWith([-1])]
    #[TestWith([0])]
    public function testNonpositiveReserveRemainsAValidDisabledConfiguration(int $size): void
    {
        $handler = new ErrorHandler(['memoryReserveSize' => $size]);

        try {
            $handler->register();

            error_clear_last();

            $handler->handleFatalError();

            self::assertSame(
                '0',
                ini_get('display_errors'),
                'Fatal handling must keep display_errors disabled.',
            );
        } finally {
            $handler->unregister();
        }
    }

    public function testRegistrationOwnsAndRestoresBothPhpHandlerStacks(): void
    {
        $sentinelError = static fn(): bool => false;
        $sentinelException = static function (Throwable $exception): void {};

        set_error_handler($sentinelError);
        set_exception_handler($sentinelException);

        $display = ini_get('display_errors');
        $handler = new class extends ErrorHandler {
            public Throwable|null $seen = null;

            public function handleException($exception): Response
            {
                $this->seen = $exception;
                return new Response(['charset' => 'UTF-8']);
            }
        };

        try {
            ini_set('display_errors', '1');

            $handler->register();

            self::assertSame(
                '0',
                ini_get('display_errors'),
                'Registration must disable display_errors.',
            );

            $currentError = set_error_handler($sentinelError);

            restore_error_handler();

            self::assertSame(
                [$handler, 'handleError'],
                $currentError,
                'Error handler must remain registered beneath the sentinel.',
            );

            $currentException = set_exception_handler($sentinelException);

            restore_exception_handler();

            self::assertIsCallable(
                $currentException,
                'Registered exception handler must be callable.',
            );

            $exception = new RuntimeException('probe');

            $currentException($exception);

            self::assertSame(
                $exception,
                $handler->seen,
                'Exception handler must receive the original exception.',
            );

            $handler->unregister();
            $handler->unregister();

            self::assertSame(
                $sentinelError,
                set_error_handler($sentinelError),
                'Unregistering must restore the previous error handler.',
            );

            restore_error_handler();

            self::assertSame(
                $sentinelException,
                set_exception_handler($sentinelException),
                'Unregistering must restore the previous exception handler.',
            );

            restore_exception_handler();

            $handler->register();

            self::assertSame(
                [$handler, 'handleError'],
                set_error_handler($sentinelError),
                'Re-registration must install the error handler.',
            );

            restore_error_handler();

            $handler->unregister();
        } finally {
            $handler->unregister();

            restore_error_handler();
            restore_exception_handler();
            ini_set('display_errors', $display);
        }
    }
}
