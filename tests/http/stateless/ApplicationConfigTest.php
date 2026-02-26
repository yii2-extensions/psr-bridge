<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use HttpSoft\Message\{ServerRequestFactory, StreamFactory, UploadedFileFactory};
use PHPForge\Support\ReflectionHelper;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\{ServerRequestFactoryInterface, StreamFactoryInterface, UploadedFileFactoryInterface};
use ReflectionException;
use RuntimeException;
use stdClass;
use Yii;
use yii\base\InvalidConfigException;
use yii\di\NotInstantiableException;
use yii2\extensions\psrbridge\http\Application;
use yii2\extensions\psrbridge\tests\support\{ApplicationFactory, HelperFactory, TestCase};

/**
 * Unit tests for {@see Application} configuration and reinitialization behavior in stateless mode.
 *
 * Test coverage.
 * - Verifies container definitions and singletons lifecycle across requests.
 * - Verifies invalid or missing `components` configuration handling.
 * - Verifies request-scoped component filtering in reinitialization config.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('http')]
final class ApplicationConfigTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testBootstrapContainerAppliesDefinitionsBeforeFirstRequest(): void
    {
        $app = ApplicationFactory::stateless(
            [
                'container' => [
                    'definitions' => [
                        'bootstrapContainerDefinition' => stdClass::class,
                    ],
                ],
            ],
        );

        $app->bootstrapContainer();

        self::assertInstanceOf(
            stdClass::class,
            Yii::$container->get('bootstrapContainerDefinition'),
            'Container definitions should be resolvable after calling bootstrapContainer() before any request.',
        );

        Yii::$container->clear('bootstrapContainerDefinition');
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws ReflectionException if inaccessible method invocation fails.
     */
    public function testBuildReinitializationConfigKeepsDefinitionForUnloadedPersistentComponent(): void
    {
        $app = ApplicationFactory::stateless(
            [
                'persistentComponents' => ['lazyPersistent'],
                'container' => [
                    'definitions' => [
                        'lazyServiceDefinition' => stdClass::class,
                    ],
                ],
                'components' => [
                    'lazyPersistent' => [
                        'class' => stdClass::class,
                    ],
                ],
            ],
        );

        $request = HelperFactory::createRequest('GET', 'site/index');

        $app->handle($request);

        /** @phpstan-var array<string, mixed> $nextConfig */
        $nextConfig = ReflectionHelper::invokeMethod($app, 'buildReinitializationConfig');

        self::assertTrue(
            isset($nextConfig['components']),
            "'buildReinitializationConfig()' should return a configuration array containing 'components' key.",
        );
        self::assertIsArray(
            $nextConfig['components'],
            "'components' key in the reinitialization config should be an array.",
        );
        self::assertArrayHasKey(
            'lazyPersistent',
            $nextConfig['components'],
            "'buildReinitializationConfig()' should keep definitions for persistent components that are not loaded.",
        );
        self::assertTrue(
            Yii::$container->has('lazyServiceDefinition'),
            'Container definition should remain available when it is not declared as an application component.',
        );
    }

    /**
     * @throws ReflectionException if inaccessible method invocation fails.
     */
    public function testBuildReinitializationConfigReturnsConfigWhenComponentsAreNotArray(): void
    {
        $app = new Application(
            [
                'id' => 'test-app',
                'components' => 'invalid',
            ],
        );

        /** @phpstan-var array<string, mixed> $nextConfig */
        $nextConfig = ReflectionHelper::invokeMethod($app, 'buildReinitializationConfig');

        self::assertSame(
            [
                'id' => 'test-app',
                'components' => 'invalid',
            ],
            $nextConfig,
            "'buildReinitializationConfig()' should return unchanged config when 'components' is not an array.",
        );
    }

    /**
     * @throws ReflectionException if inaccessible method invocation fails.
     */
    public function testBuildReinitializationConfigReturnsEmptyConfigWhenComponentsAreMissing(): void
    {
        $app = new Application([]);

        /** @phpstan-var array<mixed> $nextConfig */
        $nextConfig = ReflectionHelper::invokeMethod($app, 'buildReinitializationConfig');

        self::assertSame(
            [],
            $nextConfig,
            "'buildReinitializationConfig()' should return unchanged empty config when 'components' key is missing.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testContainerConfigurationIsAppliedOnlyOncePerWorker(): void
    {
        $app = ApplicationFactory::stateless(
            [
                'container' => [
                    'definitions' => [
                        'phase2Definition' => stdClass::class,
                    ],
                ],
            ],
        );

        $request = HelperFactory::createRequest('GET', 'site/index');

        $app->handle($request);

        self::assertInstanceOf(
            stdClass::class,
            Yii::$container->get('phase2Definition'),
            'First request should use the container definition provided by application configuration.',
        );

        Yii::$container->set('phase2Definition', RuntimeException::class);

        $app->handle($request);

        self::assertInstanceOf(
            RuntimeException::class,
            Yii::$container->get('phase2Definition'),
            'Container definitions should not be reapplied on subsequent requests of the same worker.',
        );

        Yii::$container->clear('phase2Definition');
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws NotInstantiableException if a class or service can't be instantiated.
     */
    public function testContainerResolvesPsrFactoriesWithDefinitions(): void
    {
        $app = ApplicationFactory::stateless([
            'container' => [
                'definitions' => [
                    ServerRequestFactoryInterface::class => ServerRequestFactory::class,
                    StreamFactoryInterface::class => StreamFactory::class,
                    UploadedFileFactoryInterface::class => UploadedFileFactory::class,
                ],
            ],
        ]);

        $app->handle(HelperFactory::createServerRequestCreator()->createFromGlobals());

        self::assertTrue(
            Yii::$container->has(ServerRequestFactoryInterface::class),
            'Container should have definition for ServerRequestFactoryInterface, ensuring PSR-7 request factory is '
            . 'available.',
        );
        self::assertTrue(
            Yii::$container->has(StreamFactoryInterface::class),
            'Container should have definition for StreamFactoryInterface, ensuring PSR-7 stream factory is '
            . 'available.',
        );
        self::assertTrue(
            Yii::$container->has(UploadedFileFactoryInterface::class),
            'Container should have definition for UploadedFileFactoryInterface, ensuring PSR-7 uploaded file '
            . 'factory is available.',
        );
        self::assertInstanceOf(
            ServerRequestFactory::class,
            Yii::$container->get(ServerRequestFactoryInterface::class),
            'Container should resolve ServerRequestFactoryInterface to an instance of ServerRequestFactory.',
        );
        self::assertInstanceOf(
            StreamFactory::class,
            Yii::$container->get(StreamFactoryInterface::class),
            'Container should resolve StreamFactoryInterface to an instance of StreamFactory.',
        );
        self::assertInstanceOf(
            UploadedFileFactory::class,
            Yii::$container->get(UploadedFileFactoryInterface::class),
            'Container should resolve UploadedFileFactoryInterface to an instance of UploadedFileFactory.',
        );

        Yii::$container->clear(ServerRequestFactoryInterface::class);
        Yii::$container->clear(StreamFactoryInterface::class);
        Yii::$container->clear(UploadedFileFactoryInterface::class);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testGlobalContainerSingletonDefinitionsPersistAcrossRequests(): void
    {
        $app = ApplicationFactory::stateless(
            [
                'container' => [
                    'singletons' => [
                        'demoSingleton' => stdClass::class,
                    ],
                ],
            ],
        );

        $request = HelperFactory::createRequest('GET', 'site/index');

        $app->handle($request);

        $firstSingleton = Yii::$container->get('demoSingleton');

        $app->handle($request);

        $secondSingleton = Yii::$container->get('demoSingleton');

        self::assertSame(
            $firstSingleton,
            $secondSingleton,
            'Global container singleton definitions should keep the same instance across requests in worker mode.',
        );

        Yii::$container->clear('demoSingleton');
    }
}
