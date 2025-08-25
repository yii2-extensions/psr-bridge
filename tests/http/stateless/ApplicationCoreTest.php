<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use HttpSoft\Message\{ServerRequestFactory, StreamFactory, UploadedFileFactory};
use PHPUnit\Framework\Attributes\{Group, TestWith};
use Psr\Http\Message\{ServerRequestFactoryInterface, StreamFactoryInterface, UploadedFileFactoryInterface};
use ReflectionException;
use Yii;
use yii\base\{Event, InvalidConfigException, Security};
use yii\di\NotInstantiableException;
use yii\i18n\{Formatter, I18N};
use yii\log\Dispatcher;
use yii\web\{AssetManager, Session, UrlManager, User, View};
use yii2\extensions\psrbridge\http\{ErrorHandler, Request, Response, StatelessApplication};
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\support\stub\MockerFunctions;
use yii2\extensions\psrbridge\tests\TestCase;

use function array_filter;
use function dirname;
use function str_contains;

#[Group('http')]
final class ApplicationCoreTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->closeApplication();

        parent::tearDown();
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws NotInstantiableException if a class or service can't be instantiated.
     */
    public function testContainerResolvesPsrFactoriesWithDefinitions(): void
    {
        $app = $this->statelessApplication([
            'container' => [
                'definitions' => [
                    ServerRequestFactoryInterface::class => ServerRequestFactory::class,
                    StreamFactoryInterface::class => StreamFactory::class,
                    UploadedFileFactoryInterface::class => UploadedFileFactory::class,
                ],
            ],
        ]);

        $container = $app->container();

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertTrue(
            $container->has(ServerRequestFactoryInterface::class),
            'Container should have definition for ServerRequestFactoryInterface, ensuring PSR-7 request factory is ' .
            'available.',
        );
        self::assertTrue(
            $container->has(StreamFactoryInterface::class),
            'Container should have definition for StreamFactoryInterface, ensuring PSR-7 stream factory is ' .
            'available.',
        );
        self::assertTrue(
            $container->has(UploadedFileFactoryInterface::class),
            'Container should have definition for UploadedFileFactoryInterface, ensuring PSR-7 uploaded file ' .
            'factory is available.',
        );
        self::assertInstanceOf(
            ServerRequestFactory::class,
            $container->get(ServerRequestFactoryInterface::class),
            'Container should resolve ServerRequestFactoryInterface to an instance of ServerRequestFactory.',
        );
        self::assertInstanceOf(
            StreamFactory::class,
            $container->get(StreamFactoryInterface::class),
            'Container should resolve StreamFactoryInterface to an instance of StreamFactory.',
        );
        self::assertInstanceOf(
            UploadedFileFactory::class,
            $container->get(UploadedFileFactoryInterface::class),
            'Container should resolve UploadedFileFactoryInterface to an instance of UploadedFileFactory.',
        );
    }

    public function testEventOrderDuringHandle(): void
    {
        $app = $this->statelessApplication();
        $sequence = [];

        $app->on(
            StatelessApplication::EVENT_BEFORE_REQUEST,
            static function () use (&$sequence): void {
                $sequence[] = 'before';
            },
        );
        $app->on(
            StatelessApplication::EVENT_AFTER_REQUEST,
            static function () use (&$sequence): void {
                $sequence[] = 'after';
            },
        );

        $response = $app->handle(FactoryHelper::createRequest('GET', 'site/index'));

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/index'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/index'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"hello":"world"}
            JSON,
            $response->getBody()->getContents(),
            "Expected JSON Response body '{\"hello\":\"world\"}'.",
        );
        self::assertSame(
            ['before', 'after'],
            $sequence,
            "BEFORE should precede AFTER during 'handle()'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws ReflectionException if the property does not exist or is inaccessible.
     */
    public function testResponseAdapterCachingAndResetBehaviorAcrossMultipleRequests(): void
    {
        $app = $this->statelessApplication();

        // first request - verify adapter caching behavior
        $response1 = $app->handle(FactoryHelper::createRequest('GET', 'site/index'));

        self::assertSame(
            200,
            $response1->getStatusCode(),
            "Expected HTTP '200' for route 'site/index'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response1->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/index'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"hello":"world"}
            JSON,
            $response1->getBody()->getContents(),
            "Expected JSON Response body '{\"hello\":\"world\"}'.",
        );

        // access the Response component to test adapter behavior
        $bridgeResponse1 = $app->response;

        // get PSR-7 Response twice to test caching
        $bridgeResponse1->getPsr7Response();

        $adapter1 = self::inaccessibleProperty($bridgeResponse1, 'adapter');

        $bridgeResponse1->getPsr7Response();

        $adapter2 = self::inaccessibleProperty($bridgeResponse1, 'adapter');

        // verify adapter is cached (same instance across multiple calls)
        self::assertSame(
            $adapter1,
            $adapter2,
            "Multiple calls to 'getPsr7Response()' should return the same cached adapter instance, " .
            'confirming adapter caching behavior.',
        );

        // second request with different route - verify stateless behavior
        $response2 = $app->handle(FactoryHelper::createRequest('GET', 'site/statuscode'));

        self::assertSame(
            201,
            $response2->getStatusCode(),
            "Expected HTTP '201' for route 'site/statuscode'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response2->getHeaderLine('Content-Type'),
            "Expected Content-Type 'text/html; charset=UTF-8' for route 'site/statuscode'.",
        );

        // verify that the Response component is fresh for each request (stateless)
        $bridgeResponse2 = $app->response;

        self::assertNotSame(
            $bridgeResponse1,
            $bridgeResponse2,
            'Response component should be a different instance for each request, confirming stateless behavior.',
        );

        // test 'reset()' functionality
        $bridgeResponse2->getPsr7Response();

        $adapter3 = self::inaccessibleProperty($bridgeResponse2, 'adapter');

        $bridgeResponse2->reset();

        // after reset, adapter cache should be cleared
        self::assertNull(
            self::inaccessibleProperty($bridgeResponse2, 'adapter'),
            "'reset()' should nullify the cached adapter before the next 'getPsr7Response()' call.",
        );

        $bridgeResponse2->getPsr7Response();

        $adapter4 = self::inaccessibleProperty($bridgeResponse2, 'adapter');

        self::assertNotSame(
            $adapter3,
            $adapter4,
            "'reset()' should force creation of a new adapter instance; the cached adapter before and after reset " .
            'must be different.',
        );

        // third request - verify adapter isolation between requests
        $response3 = $app->handle(FactoryHelper::createRequest('GET', 'site/add-cookies-to-response'));

        self::assertSame(
            200,
            $response3->getStatusCode(),
            "Expected HTTP '200' for route 'site/add-cookies-to-response'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response3->getHeaderLine('Content-Type'),
            "Expected Content-Type 'text/html; charset=UTF-8' for route 'site/add-cookies-to-response'.",
        );

        $bridgeResponse3 = $app->response;

        $bridgeResponse3->getPsr7Response();

        $adapter5 = self::inaccessibleProperty($bridgeResponse3, 'adapter');

        // verify each request gets its own adapter instance
        self::assertNotSame(
            $adapter3,
            $adapter5,
            'Each request should get its own adapter instance, confirming adapter isolation between requests.',
        );

        $cookieHeaders = $response3->getHeader('Set-Cookie');

        // verify response headers are preserved correctly across adapter operations
        $hasCookieHeader = array_filter(
            $cookieHeaders,
            static fn(string $h): bool => str_contains($h, 'test=test') || str_contains($h, 'test2=test2'),
        ) !== [];

        self::assertTrue(
            $hasCookieHeader,
            "PSR-7 Response should contain 'test=test' or 'test2=test2' in 'Set-Cookie' headers, confirming correct " .
            'adapter behavior.',
        );
        self::assertContains(
            'test=test; Path=/; HttpOnly; SameSite=Lax',
            $cookieHeaders,
            'PSR-7 Response Set-Cookie headers should match the expected values, confirming correct adapter behavior.',
        );
        self::assertContains(
            'test2=test2; Path=/; HttpOnly; SameSite=Lax',
            $cookieHeaders,
            'PSR-7 Response Set-Cookie headers should match the expected values, confirming correct adapter behavior.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnCoreComponentsConfigurationAfterHandle(): void
    {
        $app = $this->statelessApplication();

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            [
                'log' => [
                    'class' => Dispatcher::class,
                ],
                'view' => [
                    'class' => View::class,
                ],
                'formatter' => [
                    'class' => Formatter::class,
                ],
                'i18n' => [
                    'class' => I18N::class,
                ],
                'urlManager' => [
                    'class' => UrlManager::class,
                ],
                'assetManager' => [
                    'class' => AssetManager::class,
                ],
                'security' => [
                    'class' => Security::class,
                ],
                'request' => [
                    'class' => Request::class,
                ],
                'response' => [
                    'class' => Response::class,
                ],
                'session' => [
                    'class' => Session::class,
                ],
                'user' => [
                    'class' => User::class,
                ],
                'errorHandler' => [
                    'class' => ErrorHandler::class,
                ],
            ],
            $app->coreComponents(),
            "'coreComponents()' should return the expected mapping of component IDs to class definitions after " .
            'handling a request.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testSetsPsr7RequestWithStatelessAppStartTimeHeader(): void
    {
        $mockedTime = 1640995200.123456;

        MockerFunctions::setMockedMicrotime($mockedTime);

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createRequest('GET', 'site/index'));

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/index'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/index'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"hello":"world"}
            JSON,
            $response->getBody()->getContents(),
            "Expected JSON Response body '{\"hello\":\"world\"}'.",
        );

        $psr7Request = $app->request->getPsr7Request();
        $statelessAppStartTime = $psr7Request->getHeaderLine('statelessAppStartTime');

        self::assertSame(
            (string) $mockedTime,
            $statelessAppStartTime,
            "PSR-7 request should contain 'statelessAppStartTime' header with the mocked microtime value.",
        );
        self::assertTrue(
            $psr7Request->hasHeader('statelessAppStartTime'),
            "PSR-7 request should have 'statelessAppStartTime' header set during adapter creation.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testSetWebAndWebrootAliasesAfterHandleRequest(): void
    {
        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createRequest('GET', 'site/index'));

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/index'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/index'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"hello":"world"}
            JSON,
            $response->getBody()->getContents(),
            "Expected JSON Response body '{\"hello\":\"world\"}'.",
        );
        self::assertSame(
            '',
            Yii::getAlias('@web'),
            "'@web' alias should be set to an empty string after handling a request.",
        );
        self::assertSame(
            dirname(__DIR__, 2),
            Yii::getAlias('@webroot'),
            "'@webroot' alias should be set to the parent directory of the test directory after handling a request.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[TestWith([StatelessApplication::EVENT_AFTER_REQUEST])]
    #[TestWith([StatelessApplication::EVENT_BEFORE_REQUEST])]
    public function testTriggerEventDuringHandle(string $eventName): void
    {
        $invocations = 0;
        $sender = null;
        $sequence = [];

        $app = $this->statelessApplication();

        $app->on(
            $eventName,
            static function (Event $event) use (&$invocations, &$sequence, &$sender): void {
                $invocations++;
                $sender = $event->sender ?? null;
                $sequence[] = $event->name;
            },
        );

        $response = $app->handle(FactoryHelper::createRequest('GET', 'site/index'));

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/index'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/index'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"hello":"world"}
            JSON,
            $response->getBody()->getContents(),
            "Expected JSON Response body '{\"hello\":\"world\"}'.",
        );
        self::assertSame(
            1,
            $invocations,
            "Should trigger '{$eventName}' exactly once during 'handle()'.",
        );
        self::assertSame(
            $app,
            $sender,
            'Event sender should be the application instance.',
        );
    }
}
