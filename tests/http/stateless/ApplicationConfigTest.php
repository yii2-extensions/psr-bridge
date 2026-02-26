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
use yii\base\{Component, InvalidConfigException};
use yii\di\NotInstantiableException;
use yii2\extensions\psrbridge\http\Application;
use yii2\extensions\psrbridge\tests\support\{ApplicationFactory, HelperFactory, TestCase};

/**
 * Unit tests for the {@see Application} class configuration behavior in stateless mode.
 *
 * Test coverage.
 * - Ensures bootstrap container definitions are available before the first request.
 * - Ensures container configuration is applied once per worker lifecycle.
 * - Ensures global container singleton definitions persist across requests.
 * - Verifies PSR factory interfaces resolve from configured container definitions.
 * - Verifies reinitialization config keeps definitions for unloaded persistent components.
 * - Verifies reinitialization config remains unchanged when components are missing.
 * - Verifies reinitialization config remains unchanged when components are not an array.
 * - Verifies wildcard persistence filtering removes loaded matching components and keeps non-matching components.
 *
 * @copyright Copyright (C) 2026 Terabytesoftw.
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

        self::assertArrayHasKey(
            'components',
            $nextConfig,
            "'buildReinitializationConfig()' should return a configuration array containing 'components' key.",
        );
        self::assertIsArray(
            $nextConfig['components'] ?? null,
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
     * @throws ReflectionException if inaccessible method invocation fails.
     */
    public function testBuildReinitializationConfigSupportsWildcardPersistentComponents(): void
    {
        $app = ApplicationFactory::stateless(
            [
                'persistentComponents' => ['redis*'],
                'components' => [
                    'redis1' => [
                        'class' => Component::class,
                    ],
                    'redis2' => [
                        'class' => Component::class,
                    ],
                    'redis3' => [
                        'class' => Component::class,
                    ],
                    'mailer' => [
                        'class' => Component::class,
                    ],
                ],
            ],
        );

        $request = HelperFactory::createRequest('GET', 'site/index');

        $app->handle($request);

        // Load persistent components to simulate real runtime usage.
        $app->get('redis1');
        $app->get('redis2');
        $app->get('redis3');
        $app->get('mailer');

        /** @phpstan-var array<string, mixed> $nextConfig */
        $nextConfig = ReflectionHelper::invokeMethod($app, 'buildReinitializationConfig');
        $components = $nextConfig['components'] ?? null;

        self::assertIsArray(
            $components,
            "'buildReinitializationConfig()' should return a 'components' array in reinitialization config.",
        );
        self::assertArrayNotHasKey(
            'redis1',
            $components,
            "'buildReinitializationConfig()' should remove loaded persistent component matching wildcard 'redis*'.",
        );
        self::assertArrayNotHasKey(
            'redis2',
            $components,
            "'buildReinitializationConfig()' should remove loaded persistent component matching wildcard 'redis*'.",
        );
        self::assertArrayNotHasKey(
            'redis3',
            $components,
            "'buildReinitializationConfig()' should remove loaded persistent component matching wildcard 'redis*'.",
        );
        self::assertArrayHasKey(
            'mailer',
            $components,
            "'buildReinitializationConfig()' should keep non-matching components in the reinitialization config.",
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

        $response = $app->handle(HelperFactory::createServerRequestCreator()->createFromGlobals());

        $this->assertSiteIndexJsonResponse(
            $response,
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
    }
}
