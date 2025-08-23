<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group, TestWith};
use stdClass;
use yii\base\InvalidConfigException;
use yii2\extensions\psrbridge\tests\provider\StatelessApplicationProvider;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

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

#[Group('http')]
final class ApplicationMemoryTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[DataProviderExternal(StatelessApplicationProvider::class, 'memoryThreshold')]
    public function testCleanBehaviorWithDifferentMemoryLimits(
        string $memoryLimit,
        bool $shouldTriggerSpecialTest,
        string $assertionMessage,
    ): void {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', $memoryLimit);

        $app = $this->statelessApplication();

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        if ($shouldTriggerSpecialTest === false) {
            self::assertFalse($app->clean(), $assertionMessage);
        }

        if ($shouldTriggerSpecialTest === true) {
            $currentUsage = memory_get_usage(true);

            $artificialLimit = (int) ($currentUsage / 0.9);

            $app->setMemoryLimit($artificialLimit);

            self::assertTrue($app->clean(), $assertionMessage);
        }

        ini_set('memory_limit', $originalLimit);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testCleanTriggersGarbageCollectionAndReducesMemoryUsage(): void
    {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', '512M');

        gc_disable();

        $app = $this->statelessApplication();

        for ($i = 0; $i < 25; $i++) {
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

        $gcStatsBefore = gc_status();
        $memoryBefore = memory_get_usage(true);
        $app->clean();
        $memoryAfter = memory_get_usage(true);
        $gcStatsAfter = gc_status();

        gc_enable();

        $cyclesCollected = $gcStatsAfter['collected'] - $gcStatsBefore['collected'];

        self::assertGreaterThan(
            0,
            $cyclesCollected,
            "'clean()' should trigger garbage collection that collects circular references, but no cycles were " .
            "collected. This indicates 'gc_collect_cycles()' was not called in 'StatelessApplication'.",
        );

        $memoryDifference = $memoryAfter - $memoryBefore;

        self::assertLessThanOrEqual(
            1_048_576,
            $memoryDifference,
            "'clean()' should reduce memory usage through garbage collection. Memory increased by {$memoryDifference}" .
            " bytes, suggesting 'gc_collect_cycles()' was not called in 'StatelessApplication'.",
        );

        ini_set('memory_limit', $originalLimit);
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
            'Should have at least 3 output buffer levels before clearing output.',
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
    public function testMemoryLimitHandlesUnlimitedValues(): void
    {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', '-1');

        $app = $this->statelessApplication();

        self::assertSame(
            PHP_INT_MAX,
            $app->getMemoryLimit(),
            "Before 'handle()' memory limit should be 'PHP_INT_MAX' when set to '-1' (unlimited).",
        );

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());
        $app->clean();

        self::assertSame(
            PHP_INT_MAX,
            $app->getMemoryLimit(),
            "After 'clean()' memory limit should remain 'PHP_INT_MAX' when set to '-1'.",
        );

        ini_set('memory_limit', $originalLimit);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
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

        self::assertSame(
            268_435_456,
            $firstMemoryLimit,
            "Baseline should reflect '256M' ('268_435_456 bytes') before triggering recalculation.",
        );

        $app->setMemoryLimit($memoryLimit);

        ini_set('memory_limit', '128M');

        // after setting non-positive value, it should recalculate from the system
        $secondMemoryLimit = $app->getMemoryLimit();

        self::assertSame(
            134_217_728,
            $secondMemoryLimit,
            "'getMemoryLimit()' should return '128M' ('134_217_728 bytes') after recalculation from system when a " .
            'non-positive override is applied.',
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
