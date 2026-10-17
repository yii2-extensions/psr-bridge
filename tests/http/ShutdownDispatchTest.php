<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use PHPUnit\Framework\Attributes\{PreserveGlobalState, RunTestsInSeparateProcesses, TestWith};
use WeakReference;
use yii2\extensions\psrbridge\http\{ErrorHandler, Session};
use yii2\extensions\psrbridge\tests\support\{ShutdownProbe, TestCase};

/**
 * Verifies that native dispatchers target only the current weakly held instance.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ShutdownDispatchTest extends TestCase
{
    #[TestWith([true])]
    #[TestWith([false])]
    public function testDispatcherToleratesCollectionOfItsCurrentTarget(bool $errorHandler): void
    {
        require_once __DIR__ . '/../support/ShutdownProbe.php';

        if ($errorHandler) {
            $current = new ErrorHandler();

            $current->register();
            $current->unregister();
        } else {
            $current = new Session();
        }

        $weak = WeakReference::create($current);

        unset($current);
        gc_collect_cycles();

        self::assertNull(
            $weak->get(),
            'Dispatcher must not retain its target.',
        );
        self::assertCount(
            1,
            ShutdownProbe::$callbacks,
            'Exactly one dispatcher must be registered.',
        );

        (ShutdownProbe::$callbacks[0] ?? throw new \RuntimeException('Missing dispatcher.'))();
    }

    public function testErrorDispatcherIsRegisteredOnceAndDoesNotRetainOldHandlers(): void
    {
        require_once __DIR__ . '/../support/ShutdownProbe.php';

        $references = [];
        $handler = null;

        for ($i = 0; $i < 4; ++$i) {
            $handler = new class extends ErrorHandler {
                public int $fatalCalls = 0;
                public function handleFatalError(): void
                {
                    ++$this->fatalCalls;
                }
            };
            $handler->register();
            $handler->unregister();

            $references[] = WeakReference::create($handler);
        }

        gc_collect_cycles();

        self::assertNull(
            ($references[0] ?? throw new \RuntimeException('Missing weak reference.'))->get(),
            'Old handlers must be released.',
        );
        self::assertCount(
            1,
            ShutdownProbe::$callbacks,
            'Exactly one error dispatcher must be registered.',
        );

        (ShutdownProbe::$callbacks[0] ?? throw new \RuntimeException('Missing dispatcher.'))();

        self::assertNotNull(
            $handler,
            'Current error handler must remain available.',
        );
        self::assertSame(
            1,
            $handler->fatalCalls,
            'Dispatcher must invoke the current error handler once.',
        );
    }

    public function testSessionDispatcherIsRegisteredOnceAndClosesTheCurrentSubclass(): void
    {
        require_once __DIR__ . '/../support/ShutdownProbe.php';

        $references = [];
        $session = null;

        for ($i = 0; $i < 4; ++$i) {
            $session = new class extends Session {
                public int $closed = 0;
                public function close(): void
                {
                    ++$this->closed;
                    parent::close();
                }
            };

            $references[] = WeakReference::create($session);
        }

        gc_collect_cycles();

        self::assertNull(
            ($references[0] ?? throw new \RuntimeException('Missing weak reference.'))->get(),
            'Old sessions must be released.',
        );
        self::assertCount(
            1,
            ShutdownProbe::$callbacks,
            'Exactly one session dispatcher must be registered.',
        );

        (ShutdownProbe::$callbacks[0] ?? throw new \RuntimeException('Missing dispatcher.'))();

        self::assertNotNull(
            $session,
            'Current session must remain available.',
        );
        self::assertSame(
            1,
            $session->closed,
            'Dispatcher must close the current session once.',
        );
    }
}
