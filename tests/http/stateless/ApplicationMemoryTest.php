<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
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
    public function testCleanCalculatesCorrectMemoryThresholdWith90Percent(): void
    {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', '100M');

        $app = $this->statelessApplication();

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());
        $memoryLimit = $app->getMemoryLimit();

        self::assertFalse(
            $app->clean(),
            "'clean()' should return 'false' when memory usage is below the '90%' threshold of the configured memory " .
            "limit ('100M'), confirming that no cleanup is needed in 'StatelessApplication'.",
        );
        self::assertSame(
            104_857_600,
            $memoryLimit,
            "Memory limit should be exactly '104_857_600' bytes ('100M') for threshold calculation test in" .
            "'StatelessApplication'.",
        );
        self::assertSame(
            94_371_840.0,
            $memoryLimit * 0.9,
            "'90%' of '100M' should be exactly '94_371_840' bytes, not a division result like '116_508_444' bytes " .
            "('100M' / '0.9') in 'StatelessApplication'.",
        );

        ini_set('memory_limit', $originalLimit);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testCleanReturnsTrueWhenMemoryUsageExactlyEqualsThreshold(): void
    {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', '2G');

        $app = $this->statelessApplication();

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        $currentUsage = memory_get_usage(true);

        $artificialLimit = (int) ($currentUsage / 0.9);

        $app->setMemoryLimit($artificialLimit);
        $memoryLimit = $app->getMemoryLimit();

        self::assertTrue(
            $app->clean(),
            "'clean()' should return 'true' when memory usage is exactly at or above '90%' threshold ('>=' operator)" .
            ", not only when strictly greater than ('>' operator) in 'StatelessApplication'.",
        );
        self::assertSame(
            $artificialLimit,
            $memoryLimit,
            "Memory limit should be set to the artificial limit '{$artificialLimit}' for threshold calculation test " .
            "in 'StatelessApplication'.",
        );

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
    public function testGetMemoryLimitHandlesUnlimitedMemoryCorrectly(): void
    {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', '-1');

        $app = $this->statelessApplication();

        self::assertSame(
            PHP_INT_MAX,
            $app->getMemoryLimit(),
            "Memory limit should be 'PHP_INT_MAX' when set to '-1' (unlimited) in 'StatelessApplication'.",
        );

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());
        $app->clean();

        ini_set('memory_limit', $originalLimit);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testRecalculateMemoryLimitAfterResetAndIniChange(): void
    {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', '256M');

        $app = $this->statelessApplication();

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        $firstCalculation = $app->getMemoryLimit();
        $app->setMemoryLimit(0);

        ini_set('memory_limit', '128M');

        $secondCalculation = $app->getMemoryLimit();

        self::assertSame(
            134_217_728,
            $secondCalculation,
            "'getMemoryLimit()' should return '134_217_728' ('128M') after resetting and updating 'memory_limit' to " .
            "'128M' in 'StatelessApplication'.",
        );
        self::assertNotSame(
            $firstCalculation,
            $secondCalculation,
            "'getMemoryLimit()' should return a different value after recalculation when 'memory_limit' changes in " .
            "'StatelessApplication'.",
        );

        ini_set('memory_limit', $originalLimit);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnFalseFromCleanWhenMemoryUsageIsBelowThreshold(): void
    {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', '1G');

        $app = $this->statelessApplication();

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertFalse(
            $app->clean(),
            "'clean()' should return 'false' when memory usage is below '90%' of the configured 'memory_limit' in " .
            "'StatelessApplication'.",
        );

        ini_set('memory_limit', $originalLimit);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnPhpIntMaxWhenMemoryLimitIsUnlimited(): void
    {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', '-1');

        $app = $this->statelessApplication();

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            PHP_INT_MAX,
            $app->getMemoryLimit(),
            "'getMemoryLimit()' should return 'PHP_INT_MAX' when 'memory_limit' is set to '-1' (unlimited) in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            PHP_INT_MAX,
            $app->getMemoryLimit(),
            "'getMemoryLimit()' should remain 'PHP_INT_MAX' after handling a request with unlimited memory in " .
            "'StatelessApplication'.",
        );

        ini_set('memory_limit', $originalLimit);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testSetMemoryLimitWithPositiveValueOverridesSystemRecalculation(): void
    {
        $originalLimit = ini_get('memory_limit');

        $app = $this->statelessApplication();

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());
        $app->setMemoryLimit(0);

        ini_set('memory_limit', '256M');

        $systemBasedLimit = $app->getMemoryLimit();

        $customLimit = 104_857_600; // '100MB' in bytes (different from system)

        $app->setMemoryLimit($customLimit);

        ini_set('memory_limit', '512M');

        self::assertSame(
            $customLimit,
            $app->getMemoryLimit(),
            'Memory limit should maintain custom value and ignore system changes when set to positive value.',
        );
        self::assertNotSame(
            $systemBasedLimit,
            $app->getMemoryLimit(),
            'Memory limit should override system-based calculation when positive value is set.',
        );

        ini_set('memory_limit', $originalLimit);
    }

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
