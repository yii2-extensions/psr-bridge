<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support;

use PHPUnit\Event\Test\{PreparationStarted, PreparationStartedSubscriber};
use PHPUnit\Event\TestSuite\{Started, StartedSubscriber};
use PHPUnit\Runner\Extension\{Extension, Facade, ParameterCollection};
use PHPUnit\TextUI\Configuration\Configuration;
use Xepozz\InternalMocker\{Mocker, MockerState};
use yii2\extensions\psrbridge\tests\support\stub\MockerFunctions;

/**
 * PHPUnit extension that registers internal-function mocks for test execution.
 */
final class MockerExtension implements Extension
{
    /**
     * Registers event subscribers that initialize and reset mock state.
     */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscribers(
            new class implements StartedSubscriber {
                public function notify(Started $event): void
                {
                    MockerExtension::load();
                }
            },
            new class implements PreparationStartedSubscriber {
                public function notify(PreparationStarted $event): void
                {
                    MockerState::resetState();
                }
            },
        );
    }

    /**
     * Loads configured function mocks and snapshots their initial state.
     */
    public static function load(): void
    {
        $mocks = [
            [
                'namespace' => 'yii2\extensions\psrbridge\emitter',
                'name' => 'flush',
                'function' => static fn() => MockerFunctions::flush(),
            ],
            [
                'namespace' => 'yii2\extensions\psrbridge\emitter',
                'name' => 'header',
                'function' => static fn(
                    string $string,
                    bool $replace = true,
                    int|null $http_response_code = null,
                ) => MockerFunctions::header(
                    $string,
                    $replace,
                    $http_response_code,
                ),
            ],
            [
                'namespace' => 'yii2\extensions\psrbridge\emitter',
                'name' => 'headers_list',
                'function' => static fn(): array => MockerFunctions::headers_list(),
            ],
            [
                'namespace' => 'yii2\extensions\psrbridge\emitter',
                'name' => 'header_remove',
                'function' => static fn(string|null $header = null) => MockerFunctions::header_remove($header),
            ],
            [
                'namespace' => 'yii2\extensions\psrbridge\emitter',
                'name' => 'headers_sent',
                'function' => static fn(&$file = null, &$line = null): bool => MockerFunctions::headers_sent(
                    $file,
                    $line,
                ),
            ],
            [
                'namespace' => 'yii2\extensions\psrbridge\http',
                'name' => 'http_response_code',
                'function' => static fn(int|null $response_code = null): int => MockerFunctions::http_response_code(
                    $response_code,
                ),
            ],
            [
                'namespace' => 'yii2\extensions\psrbridge\http',
                'name' => 'microtime',
                'function' => static fn(bool $as_float = false): float|string => MockerFunctions::microtime($as_float),
            ],
            [
                'namespace' => 'yii2\extensions\psrbridge\adapter',
                'name' => 'fseek',
                'function' => static fn(
                    $stream,
                    int $offset,
                    int $whence = SEEK_SET,
                ): int => MockerFunctions::fseek(
                    $stream,
                    $offset,
                    $whence,
                ),
            ],
            [
                'namespace' => 'yii2\extensions\psrbridge\tests\support\stub',
                'name' => 'time',
                'function' => static fn(): int => MockerFunctions::time(),
            ],
        ];

        $mocker = new Mocker(stubPath: __DIR__ . '/internal-mocker-stubs.php');
        $mocker->load($mocks);

        MockerState::saveState();
    }
}
