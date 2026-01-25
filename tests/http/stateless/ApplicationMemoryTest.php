<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPForge\Support\ReflectionHelper;
use PHPUnit\Framework\Attributes\{DataProviderExternal, Group, TestWith};
use ReflectionException;
use stdClass;
use yii\base\InvalidConfigException;
use yii2\extensions\psrbridge\tests\provider\StatelessApplicationProvider;
use yii2\extensions\psrbridge\tests\support\{FactoryHelper, TestCase};

use function array_fill;
use function end;
use function gc_disable;
use function gc_enable;
use function gc_status;
use function ini_get;
use function ini_set;
use function memory_get_usage;
use function ob_get_level;
use function ob_start;

use const PHP_INT_MAX;

/**
 * Test suite for {@see \yii2\extensions\psrbridge\http\StatelessApplication} memory management in stateless mode.
 *
 * Verifies correct memory limit handling, output buffer cleanup and garbage collection in stateless Yii2 applications.
 *
 * Test coverage.
 * - Confirms memory limit parsing, unlimited values, and positive/negative limit scenarios.
 * - Covers edge cases for memory thresholds, recursion, and buffer management.
 * - Ensures output buffer levels are cleaned after request handling.
 * - Validates garbage collection and memory usage before and after cleanup.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('http')]
final class ApplicationMemoryTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[DataProviderExternal(StatelessApplicationProvider::class, 'memoryThreshold')]
    public function testCleanBehaviorWithDifferentMemoryLimits(string $memoryLimit, string $assertionMessage): void
    {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', $memoryLimit);

        $app = $this->statelessApplication();

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertFalse($app->clean(), $assertionMessage);

        ini_set('memory_limit', $originalLimit);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testCleanReturnsTrueWhenMemoryUsageIsExactlyPercentThreshold(): void
    {
        $app = $this->statelessApplication();

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        $currentUsage = memory_get_usage(true);

        $exactLimit = (int) ($currentUsage / 0.9);

        $app->setMemoryLimit($exactLimit);

        self::assertTrue(
            $app->clean(),
            "Should return 'true' when memory usage is exactly at '90%' threshold (boundary condition).",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testClearOutputCleansLocalBuffers(): void
    {
        $levels = [];

        ob_start();
        $levels[] = ob_get_level();
        ob_start();
        $levels[] = ob_get_level();
        ob_start();
        $levels[] = ob_get_level();

        $app = $this->statelessApplication();

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertGreaterThanOrEqual(
            3,
            end($levels),
            "Should have at least '3' output buffer levels before clearing output.",
        );

        $app->errorHandler->clearOutput();

        $closed = true;

        foreach ($levels as $level) {
            $closed = $closed && (ob_get_level() < $level);
        }

        self::assertTrue(
            $closed,
            "Should close all local output buffers after calling 'clearOutput()'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[DataProviderExternal(StatelessApplicationProvider::class, 'garbageCollection')]
    public function testGarbageCollectionWithDifferentLoads(
        string $memoryLimit,
        int $iterations,
        bool $shouldCollectCycles,
        string $assertionMessage,
    ): void {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', $memoryLimit);

        gc_disable();

        $app = $this->statelessApplication();

        for ($i = 0; $i < $iterations; $i++) {
            $circular = new stdClass();

            $circular->self = $circular;

            $circular->data = array_fill(0, 100, "data-{$i}");

            $_SERVER = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => "site/index?iteration={$i}",
            ];

            $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

            $obj1 = new stdClass();
            $obj2 = new stdClass();

            $obj1->ref = $obj2;
            $obj2->ref = $obj1;
            $obj1->circular = $circular;

            unset($circular, $obj1, $obj2, $response);
        }

        if ($shouldCollectCycles === false) {
            $this->expectNotToPerformAssertions();
        }

        $gcStatsBefore = gc_status();
        $memoryBefore = memory_get_usage(true);

        $app->clean();

        $memoryAfter = memory_get_usage(true);
        $gcStatsAfter = gc_status();

        gc_enable();

        $cyclesCollected = $gcStatsAfter['collected'] - $gcStatsBefore['collected'];

        if ($shouldCollectCycles) {
            self::assertGreaterThan(0, $cyclesCollected, $assertionMessage);
            self::assertLessThanOrEqual(1_048_576, $memoryAfter - $memoryBefore, $assertionMessage);
        }

        ini_set('memory_limit', $originalLimit);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testMemoryLimitHandlesUnlimitedValues(): void
    {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', '-1');

        $app = $this->statelessApplication();

        self::assertSame(
            PHP_INT_MAX,
            $app->getMemoryLimit(),
            "Before 'handle()' memory limit should be PHP_INT_MAX when set to '-1' (unlimited).",
        );

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());
        $app->clean();

        self::assertSame(
            PHP_INT_MAX,
            $app->getMemoryLimit(),
            "After 'clean()' memory limit should remain PHP_INT_MAX when set to '-1'.",
        );

        ini_set('memory_limit', $originalLimit);
    }

    /**
     * @throws ReflectionException if the method does not exist or is inaccessible.
     */
    #[DataProviderExternal(StatelessApplicationProvider::class, 'parseMemoryLimit')]
    public function testParseMemoryLimitHandlesAllCasesCorrectly(
        string $input,
        int $expected,
        string $assertionMessage,
    ): void {
        $app = $this->statelessApplication();

        self::assertSame(
            $expected,
            ReflectionHelper::invokeMethod($app, 'parseMemoryLimit', [$input]),
            $assertionMessage,
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws ReflectionException if the property does not exist or is inaccessible.
     */
    #[TestWith([-1])]
    #[TestWith([0])]
    public function testSetMemoryLimitWithNonPositiveValueTriggersRecalculation(int $memoryLimit): void
    {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', '256M');

        $app = $this->statelessApplication();

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        $firstMemoryLimit = $app->getMemoryLimit();
        $shouldRecalculateMemoryLimit = ReflectionHelper::inaccessibleProperty($app, 'shouldRecalculateMemoryLimit');

        self::assertFalse(
            $shouldRecalculateMemoryLimit,
            "'shouldRecalculateMemoryLimit' should remain 'false' after 'handle()' if 'setMemoryLimit()' was not "
            . 'called.',
        );
        self::assertSame(
            268_435_456,
            $firstMemoryLimit,
            "Baseline should reflect '256M' ('268_435_456 bytes') before triggering recalculation.",
        );

        $app->setMemoryLimit($memoryLimit);
        $shouldRecalculateMemoryLimit = ReflectionHelper::inaccessibleProperty($app, 'shouldRecalculateMemoryLimit');

        self::assertTrue(
            $shouldRecalculateMemoryLimit,
            "'shouldRecalculateMemoryLimit' should be 'true' after calling 'setMemoryLimit()' with a non-positive "
            . 'value.',
        );

        ini_set('memory_limit', '128M');

        // after setting non-positive value, it should recalculate from the system
        $secondMemoryLimit = $app->getMemoryLimit();

        self::assertSame(
            134_217_728,
            $secondMemoryLimit,
            "'getMemoryLimit()' should return '128M' ('134_217_728 bytes') after recalculation from system when a "
            . 'non-positive override is applied.',
        );
        self::assertNotSame(
            $firstMemoryLimit,
            $secondMemoryLimit,
            "'getMemoryLimit()' should return a different value after recalculation when the system limit changes.",
        );

        ini_set('memory_limit', $originalLimit);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[DataProviderExternal(StatelessApplicationProvider::class, 'memoryLimitPositive')]
    public function testSetMemoryLimitWithPositiveValueSetsLimitDirectly(
        int $memoryLimit,
        string $assertionMessage,
    ): void {
        $app = $this->statelessApplication();

        $app->setMemoryLimit($memoryLimit);

        self::assertSame($memoryLimit, $app->getMemoryLimit(), $assertionMessage);

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame($memoryLimit, $app->getMemoryLimit(), $assertionMessage);
    }
}
