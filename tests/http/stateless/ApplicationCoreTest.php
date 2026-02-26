<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use JsonException;
use PHPForge\Support\ReflectionHelper;
use PHPUnit\Framework\Attributes\{Group, RequiresPhpExtension};
use ReflectionException;
use stdClass;
use Yii;
use yii\base\{InvalidConfigException, Security};
use yii\i18n\{Formatter, I18N};
use yii\log\{Dispatcher, FileTarget};
use yii\web\{AssetManager, JsonParser, RequestParserInterface, Session, UrlManager, User, View};
use yii2\extensions\psrbridge\http\{Application, ErrorHandler, Request, Response};
use yii2\extensions\psrbridge\tests\support\{ApplicationFactory, HelperFactory, TestCase};

use function array_filter;
use function dirname;
use function file_exists;
use function file_get_contents;
use function ini_get;
use function ini_set;
use function json_encode;
use function ob_get_level;
use function ob_start;
use function str_contains;
use function unlink;

/**
 * Unit tests for {@see Application} core behavior in stateless mode.
 *
 * Test coverage.
 * - Ensures lifecycle events run in the expected order during request handling.
 * - Ensures response adapters are cached per response instance and isolated per request.
 * - Validates parser behavior for configured, wildcard, and invalid parser definitions.
 * - Verifies container definitions resolve PSR-7 factory interfaces.
 * - Verifies core component mappings and aliases after handling.
 * - Verifies redirects, debug exception rendering, and immediate log flushing behavior.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('http')]
final class ApplicationCoreTest extends TestCase
{
    /**
     * Path to the log file used in tests.
     */
    private string $logFile = '';

    public function testEventOrderDuringHandle(): void
    {
        $app = ApplicationFactory::stateless();
        $sequence = [];

        $app->on(
            Application::EVENT_BEFORE_REQUEST,
            static function () use (&$sequence): void {
                $sequence[] = 'before';
            },
        );
        $app->on(
            Application::EVENT_AFTER_REQUEST,
            static function () use (&$sequence): void {
                $sequence[] = 'after';
            },
        );

        $response = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response,
        );
        self::assertSame(
            ['before', 'after'],
            $sequence,
            "BEFORE should precede AFTER during 'handle()'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFlushImmediateWritesLogsToFileImmediately(): void
    {
        $app = ApplicationFactory::stateless(
            [
                'flushLogger' => true,
                'components' => [
                    'log' => [
                        'traceLevel' => 0,
                        'targets' => [
                            [
                                'class' => FileTarget::class,
                                'categories' => ['test'],
                                'levels' => ['info'],
                                'logFile' => $this->logFile,
                                'maxFileSize' => 1024,
                                'maxLogFiles' => 1,
                            ],
                        ],
                    ],
                ],
            ],
        );

        $app->on(
            Application::EVENT_AFTER_REQUEST,
            static function (): void {
                Yii::info('Test log message after request.', 'test');
            },
        );

        $response = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response,
        );
        self::assertFileExists(
            $this->logFile,
            "Log file should exist after 'flush(true)'.",
        );
        self::assertFileIsReadable(
            $this->logFile,
            'Log file should be readable.',
        );

        $content = file_get_contents($this->logFile);

        self::assertIsString(
            $content,
            'Log file content should be a string.',
        );
        self::assertStringContainsString(
            'Test log message after request.',
            $content,
            "Log message should be written to file immediately with 'flush(true)'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws JsonException if JSON encoding fails.
     */
    public function testHandleThrowsExceptionWithCorrectMessageWhenFallbackParserIsInvalid(): void
    {
        $app = ApplicationFactory::stateless(
            [
                'components' => [
                    'request' => [
                        'parsers' => [
                            '*' => stdClass::class,
                        ],
                    ],
                ],
            ],
        );

        $payload = ['test' => 'data'];

        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $app->handle(
            HelperFactory::createRequest(
                method: 'POST',
                uri: 'site/post',
                headers: ['Content-Type' => 'application/vnd.unknown'],
            )->withBody(HelperFactory::createStreamFactory()->createStream($jsonPayload)),
        );

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Expected HTTP '500' for route 'site/post'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'text/html; charset=UTF-8' for route 'site/post'.",
        );
        self::assertStringContainsString(
            'Fallback request parser is invalid. It must implement the &apos;' . RequestParserInterface::class
            . '&apos;.',
            $response->getBody()->getContents(),
            'Response body should contain the expected error message for invalid fallback parser.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testHandleThrowsExceptionWithCorrectMessageWhenSpecificParserIsInvalid(): void
    {
        $app = ApplicationFactory::stateless(
            [
                'components' => [
                    'request' => [
                        'parsers' => [
                            'application/xml' => stdClass::class,
                        ],
                    ],
                    'errorHandler' => ['errorAction' => null],
                ],
            ],
        );

        $payload = '<root>test</root>';

        $response = $app->handle(
            HelperFactory::createRequest(
                method: 'POST',
                uri: 'site/post',
                headers: ['Content-Type' => 'application/xml'],
            )->withBody(HelperFactory::createStreamFactory()->createStream($payload)),
        );

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Expected HTTP '500' for route 'site/post'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'text/html; charset=UTF-8' for route 'site/post'.",
        );
        self::assertStringContainsString(
            'The &apos;application/xml&apos; request parser is invalid. It must implement the &apos;'
            . RequestParserInterface::class . '&apos;.',
            $response->getBody()->getContents(),
            'Response body should contain the expected error message for invalid fallback parser.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testPersistentCacheComponentKeepsSameInstanceAcrossRequests(): void
    {
        $app = ApplicationFactory::stateless();

        $request = HelperFactory::createRequest('GET', 'site/index');

        $app->handle($request);

        $firstCache = $app->cache;

        $app->handle($request);

        $secondCache = $app->cache;

        self::assertSame(
            $firstCache,
            $secondCache,
            "'cache' component should keep the same instance across requests when configured as persistent.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testRedirectWhenRouteIsSiteRedirect(): void
    {
        $app = ApplicationFactory::stateless();

        $response = $app->handle(HelperFactory::createRequest('GET', 'site/redirect'));

        self::assertSame(
            302,
            $response->getStatusCode(),
            "Expected HTTP '302' for route 'site/redirect'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'text/html; charset=UTF-8' for route 'site/redirect'.",
        );
        self::assertEmpty(
            $response->getBody()->getContents(),
            'Expected Response body to be empty for redirect responses.',
        );
        self::assertSame(
            '/site/index',
            $response->getHeaderLine('Location'),
            "Expected redirect to '/site/index'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[RequiresPhpExtension('runkit7')]
    public function testRenderExceptionSetsDisplayErrorsInDebugMode(): void
    {
        @\runkit_constant_redefine('YII_ENV_TEST', false);

        $initialBufferLevel = ob_get_level();

        $originalDisplayErrors = ini_get('display_errors');

        try {
            $app = ApplicationFactory::stateless(
                [
                    'components' => [
                        'errorHandler' => ['errorAction' => null],
                    ],
                ],
            );

            $response = $app->handle(HelperFactory::createRequest('GET', 'site/trigger-exception'));

            self::assertSame(
                500,
                $response->getStatusCode(),
                "Expected HTTP '500' for route 'site/trigger-exception'.",
            );
            self::assertSame(
                'text/html; charset=UTF-8',
                $response->getHeaderLine('Content-Type'),
                "Expected Content-Type 'text/html; charset=UTF-8' for route 'site/trigger-exception'.",
            );
            self::assertSame(
                '1',
                ini_get('display_errors'),
                "'display_errors' should be set to '1' when YII_DEBUG mode is enabled and rendering exception view.",
            );
            self::assertStringContainsString(
                'yii\base\Exception: Exception error message.',
                $response->getBody()->getContents(),
                'Response should contain exception details when YII_DEBUG mode is enabled.',
            );
        } finally {
            ini_set('display_errors', $originalDisplayErrors);

            while (ob_get_level() < $initialBufferLevel) {
                ob_start();
            }

            @\runkit_constant_redefine('YII_ENV_TEST', true);
        }
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws JsonException if JSON encoding fails.
     */
    public function testRequestParsesBodyWithConfiguredParsers(): void
    {
        $app = ApplicationFactory::stateless();

        $payload = [
            'foo' => 'bar',
            'number' => 123,
        ];

        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $response = $app->handle(
            HelperFactory::createRequest(
                method: 'POST',
                uri: 'site/post',
                headers: ['Content-Type' => 'application/json; charset=UTF-8'],
            )->withBody(HelperFactory::createStreamFactory()->createStream($jsonPayload)),
        );

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' from 'site/post'.",
        );
        self::assertJsonStringEqualsJsonString(
            $jsonPayload,
            $response->getBody()->getContents(),
            "Response body should contain the parsed JSON returned by 'SiteController::actionPost()'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws JsonException if JSON encoding fails.
     */
    public function testRequestUsesWildcardParser(): void
    {
        $app = ApplicationFactory::stateless(
            [
                'components' => [
                    'request' => [
                        'parsers' => [
                            '*' => JsonParser::class,
                        ],
                    ],
                ],
            ],
        );

        $payload = ['wildcard' => 'works'];

        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $response = $app->handle(
            HelperFactory::createRequest(
                method: 'POST',
                uri: 'site/post',
                headers: ['Content-Type' => 'application/vnd.custom+json'],
            )->withBody(HelperFactory::createStreamFactory()->createStream($jsonPayload)),
        );

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' from 'site/post'.",
        );
        self::assertJsonStringEqualsJsonString(
            $jsonPayload,
            $response->getBody()->getContents(),
            'Response body should contain the data parsed by the wildcard parser.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws ReflectionException if the property does not exist or is inaccessible.
     */
    public function testResponseAdapterCachingAndResetBehaviorAcrossMultipleRequests(): void
    {
        $app = ApplicationFactory::stateless();

        // first request - verify adapter caching behavior
        $response1 = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response1,
        );

        // access the Response component to test adapter behavior
        $bridgeResponse1 = $app->response;

        // get PSR-7 Response twice to test caching
        $bridgeResponse1->getPsr7Response();

        $adapter1 = ReflectionHelper::inaccessibleProperty($bridgeResponse1, 'adapter');

        self::assertNotNull(
            $adapter1,
            'Response adapter must be initialized (non-null) after handling the first request.',
        );

        $bridgeResponse1->getPsr7Response();

        // verify adapter is cached (same instance across multiple calls)
        self::assertSame(
            $adapter1,
            ReflectionHelper::inaccessibleProperty($bridgeResponse1, 'adapter'),
            "Multiple calls to 'getPsr7Response()' should return the same cached adapter instance, "
            . 'confirming adapter caching behavior.',
        );

        // second request with different route - verify stateless behavior
        $response2 = $app->handle(HelperFactory::createRequest('GET', 'site/statuscode'));

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

        // get PSR-7 Response twice to test caching and reset behavior
        $bridgeResponse2->getPsr7Response();

        $adapter2 = ReflectionHelper::inaccessibleProperty($bridgeResponse2, 'adapter');

        self::assertNotNull(
            $adapter2,
            'Response adapter must be initialized (non-null) after handling the second request.',
        );
        self::assertNotSame(
            $bridgeResponse1,
            $bridgeResponse2,
            'Response component should be a different instance for each request, confirming stateless behavior.',
        );
        self::assertNotSame(
            $adapter1,
            $adapter2,
            'Each request should get its own adapter instance, confirming stateless behavior.',
        );

        // third request - verify adapter isolation between requests
        $response3 = $app->handle(HelperFactory::createRequest('GET', 'site/add-cookies-to-response'));

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

        $adapter3 = ReflectionHelper::inaccessibleProperty($bridgeResponse3, 'adapter');

        self::assertNotNull(
            $adapter3,
            'Response adapter must be initialized (non-null) after handling the third request.',
        );
        self::assertNotSame(
            $bridgeResponse1,
            $bridgeResponse3,
            'Response component should be a different instance for each request, confirming stateless behavior.',
        );
        self::assertNotSame(
            $adapter2,
            $adapter3,
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
            "PSR-7 Response should contain 'test=test' or 'test2=test2' in 'Set-Cookie' headers, confirming correct "
            . 'adapter behavior.',
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
        $app = ApplicationFactory::stateless();

        $app->handle(HelperFactory::createServerRequestCreator()->createFromGlobals());

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
            "'coreComponents()' should return the expected mapping of component IDs to class definitions after "
            . 'handling a request.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testSetWebAndWebrootAliasesAfterHandleRequest(): void
    {
        $app = ApplicationFactory::stateless();

        $response = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response,
        );
        self::assertSame(
            '',
            Yii::getAlias('@web'),
            "'@web' alias should be set to an empty string after handling a request.",
        );
        self::assertSame(
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'support',
            Yii::getAlias('@webroot'),
            "'@webroot' alias should be set to the 'tests/support' directory after handling a request.",
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = dirname(__DIR__, 3) . '/runtime/logs/flush-test.log';

        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    protected function tearDown(): void
    {
        $this->closeApplication();

        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }
}
