<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group, RequiresPhpExtension};
use yii\base\{Event, Exception, InvalidConfigException};
use yii\helpers\Json;
use yii\log\{FileTarget, Logger};
use yii2\extensions\psrbridge\http\{Response, StatelessApplication};
use yii2\extensions\psrbridge\tests\provider\StatelessApplicationProvider;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function array_filter;
use function is_array;
use function ob_get_level;
use function ob_start;
use function restore_error_handler;
use function set_error_handler;
use function str_contains;

/**
 * Test suite for {@see StatelessApplication} error handling in stateless mode.
 *
 * Verifies correct error view rendering, event triggering, sensitive variable filtering, exception logging, and
 * fallback behaviors in stateless Yii2 applications.
 *
 * Test coverage.
 * - Checks that exception logging is performed during error handling.
 * - Confirms error view logic and status codes for various debug and route scenarios.
 * - Covers rendering of exceptions in different formats and error actions.
 * - Ensures after request event is triggered when handling exceptions.
 * - Tests fallback HTML error response and strict parsing exception cases.
 * - Validates filtering of sensitive server variables in fallback exception messages.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('http')]
final class ApplicationErrorHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->closeApplication();

        parent::tearDown();
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[DataProviderExternal(StatelessApplicationProvider::class, 'errorViewLogic')]
    #[RequiresPhpExtension('runkit7')]
    public function testErrorViewLogic(
        bool $debug,
        string $route,
        string $action,
        int $expectedStatusCode,
        string $expectedErrorViewContent,
        string $expectedAssertMessage,
    ): void {
        @\runkit_constant_redefine('YII_DEBUG', $debug);

        $app = $this->statelessApplication(
            [
                'components' => [
                    'errorHandler' => ['errorAction' => $action],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createRequest('GET', $route));

        self::assertSame(
            $expectedStatusCode,
            $response->getStatusCode(),
            "Expected HTTP '{$expectedStatusCode}' for route '{$route}'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'text/html; charset=UTF-8' for route '{$route}'.",
        );
        self::assertSame(
            self::normalizeLineEndings($expectedErrorViewContent),
            self::normalizeLineEndings($response->getBody()->getContents()),
            $expectedAssertMessage,
        );

        @\runkit_constant_redefine('YII_DEBUG', true);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testEventAfterRequestIsTriggeredWhenHandlingException(): void
    {
        $eventTriggered = false;
        $eventName = null;
        $eventSender = null;
        $eventCount = 0;

        $app = $this->statelessApplication(
            [
                'flushLogger' => false,
                'components' => [
                    'errorHandler' => ['errorAction' => null],
                ],
            ],
        );

        $app->on(
            StatelessApplication::EVENT_AFTER_REQUEST,
            static function (Event $event) use (&$eventTriggered, &$eventCount, &$eventName, &$eventSender): void {
                if ($event->name === StatelessApplication::EVENT_AFTER_REQUEST) {
                    $eventTriggered = true;
                    $eventName = $event->name;
                    $eventSender = $event->sender;
                    $eventCount++;
                }
            },
        );

        $response = $app->handle(FactoryHelper::createRequest('GET', '/site/trigger-exception'));

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
        self::assertStringContainsString(
            self::normalizeLineEndings(
                <<<HTML
                <pre>Exception (Exception) &apos;yii\base\Exception&apos; with message &apos;Exception error message.&apos;
                HTML,
            ),
            self::normalizeLineEndings($response->getBody()->getContents()),
            'Response body should contain content from Response object.',
        );
        self::assertTrue(
            $eventTriggered,
            'EVENT_AFTER_REQUEST should be triggered when handling an exception.',
        );
        self::assertSame(
            StatelessApplication::EVENT_AFTER_REQUEST,
            $eventName,
            'Triggered event should be EVENT_AFTER_REQUEST.',
        );
        self::assertSame(
            $app,
            $eventSender,
            'Event sender should be the StatelessApplication instance.',
        );
        self::assertSame(
            1,
            $eventCount,
            'EVENT_AFTER_REQUEST should be triggered exactly once when handling an exception.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFiltersSensitiveServerVariablesInFallbackExceptionMessage(): void
    {
        $_SERVER = [
            'API_KEY' => 'not-a-secret-api-key',
            'AUTH_TOKEN' => 'dummy-bearer-token',
            'DB_PASSWORD' => 'not-a-real-password',
            'HTTP_HOST' => 'example.com',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/nonexistent-action',
            'SAFE_VARIABLE' => 'this-should-appear',
            'SECRET_KEY' => 'not-a-real-secret-key',
        ];

        $app = $this->statelessApplication(
            [
                'components' => [
                    'errorHandler' => [
                        'errorAction' => 'invalid/nonexistent-action',
                    ],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Expected HTTP '500' for route 'site/nonexistent-action'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'text/html; charset=UTF-8' for route 'site/nonexistent-action'.",
        );

        $body = $response->getBody()->getContents();

        self::assertStringContainsString(
            'An Error occurred while handling another error:',
            $body,
            'Response body should contain fallback error message when ErrorHandler action is invalid.',
        );

        if (YII_DEBUG) {
            self::assertStringContainsString(
                '$_SERVER = [',
                $body,
                "Response body should contain '\$_SERVER = [' label.",
            );
            self::assertStringNotContainsString(
                'not-a-secret-api-key',
                $body,
                'Response body should NOT contain API_KEY value.',
            );
            self::assertStringNotContainsString(
                'dummy-bearer-token',
                $body,
                'Response body should NOT contain AUTH_TOKEN value.',
            );
            self::assertStringNotContainsString(
                'not-a-real-password',
                $body,
                'Response body should NOT contain DB_PASSWORD value.',
            );
            self::assertStringContainsString(
                'example.com',
                $body,
                'Response body should contain HTTP_HOST value.',
            );
            self::assertStringNotContainsString(
                'not-a-real-secret-key',
                $body,
                'Response body should NOT contain SECRET_KEY value.',
            );
            self::assertStringContainsString(
                'this-should-appear',
                $body,
                'Response body should contain SAFE_VARIABLE value.',
            );
        }
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testLogExceptionIsCalledWhenHandlingException(): void
    {
        $app = $this->statelessApplication(
            [
                'flushLogger' => false,
                'components' => [
                    'errorHandler' => ['errorAction' => null],
                    'log' => [
                        'traceLevel' => YII_DEBUG ? 1 : 0,
                        'targets' => [
                            [
                                'class' => FileTarget::class,
                                'levels' => ['error'],
                            ],
                        ],
                    ],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createRequest('GET', '/site/trigger-exception'));

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
        self::assertStringContainsString(
            self::normalizeLineEndings(
                <<<HTML
                <pre>Exception (Exception) &apos;yii\base\Exception&apos; with message &apos;Exception error message.&apos;
                HTML,
            ),
            self::normalizeLineEndings($response->getBody()->getContents()),
            'Response body should contain content from Response object.',
        );

        $logMessages = $app->getLog()->getLogger()->messages;

        self::assertNotEmpty(
            $logMessages,
            'Logger should contain log messages after handling an exception.',
        );

        $exceptionLogFound = false;

        $expectedCategory = Exception::class;

        foreach ($logMessages as $logMessage) {
            if (
                is_array($logMessage) &&
                isset($logMessage[0], $logMessage[1], $logMessage[2]) &&
                $logMessage[1] === Logger::LEVEL_ERROR &&
                $logMessage[0] instanceof Exception &&
                $logMessage[2] === $expectedCategory &&
                str_contains($logMessage[0]->getMessage(), 'Exception error message.')
            ) {
                $exceptionLogFound = true;

                break;
            }
        }

        self::assertTrue(
            $exceptionLogFound,
            "Logger should contain an error log entry with category '{$expectedCategory}' and message " .
            "'Exception error message'.",
        );
        self::assertFalse(
            $app->flushLogger,
            "Test must keep logger messages in memory to assert on them; 'flushLogger' should be 'false'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[RequiresPhpExtension('runkit7')]
    public function testRenderExceptionPassesExceptionParameterToTemplateView(): void
    {
        @\runkit_constant_redefine('YII_ENV_TEST', false);

        $initialBufferLevel = ob_get_level();

        $warningsCaptured = [];

        set_error_handler(
            static function ($errno, $errstr, $errfile, $errline) use (&$warningsCaptured): bool {
                if ($errno === E_WARNING || $errno === E_NOTICE) {
                    $warningsCaptured[] = [
                        'type' => $errno,
                        'message' => $errstr,
                        'file' => $errfile,
                        'line' => $errline,
                    ];
                }

                return false;
            },
        );

        try {
            $app = $this->statelessApplication(
                [
                    'components' => [
                        'errorHandler' => ['errorAction' => null],
                    ],
                ],
            );

            $response = $app->handle(FactoryHelper::createRequest('GET', '/site/trigger-exception'));

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

            $undefinedExceptionWarnings = array_filter(
                $warningsCaptured,
                static fn(array $warning): bool => str_contains($warning['message'], 'Undefined variable'),
            );

            self::assertEmpty(
                $undefinedExceptionWarnings,
                "Should be no 'Undefined variable' warnings.",
            );

            $responseBody = $response->getBody()->getContents();

            self::assertStringContainsString(
                Exception::class,
                $responseBody,
                'Response body should contain exception class.',
            );
            self::assertStringContainsString(
                'Stack trace:',
                $responseBody,
                "Response body should contain 'Stack trace:' section.",
            );
            self::assertStringContainsString(
                'Exception error message.',
                $responseBody,
                "Response body should contain the exact exception message 'Exception error message.'.",
            );
            self::assertStringContainsString(
                'SiteController.php',
                $responseBody,
                "Response body should contain reference to 'SiteController.php'.",
            );
        } finally {
            restore_error_handler();

            while (ob_get_level() < $initialBufferLevel) {
                ob_start();
            }

            @\runkit_constant_redefine('YII_ENV_TEST', true);
        }
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @phpstan-param string[] $expectedContent
     */
    #[DataProviderExternal(StatelessApplicationProvider::class, 'exceptionRenderingFormats')]
    public function testRenderExceptionWithDifferentFormats(
        string $format,
        string $expectedContentType,
        int $expectedStatusCode,
        string $route,
        array $expectedContent,
    ): void {
        $app = $this->statelessApplication(
            [
                'components' => [
                    'response' => ['format' => $format],
                    'errorHandler' => ['errorAction' => null],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createRequest('GET', $route));

        self::assertSame(
            $expectedStatusCode,
            $response->getStatusCode(),
            "Expected HTTP '{$expectedStatusCode}' for route '{$route}'.",
        );
        self::assertSame(
            $expectedContentType,
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type '{$expectedContentType}' for route '{$route}'.",
        );

        $body = $response->getBody()->getContents();

        foreach ($expectedContent as $content) {
            self::assertStringContainsString(
                $content,
                $body,
                "Response body should contain '{$content}' for {$format} format.",
            );
        }

        if ($format === Response::FORMAT_RAW) {
            self::assertStringNotContainsString(
                '<pre>',
                $body,
                "RAW format response should not contain HTML tag '<pre>'.",
            );
            self::assertStringNotContainsString(
                '</pre>',
                $body,
                "RAW format response should not contain HTML tag '</pre>'.",
            );
        }

        if ($format === Response::FORMAT_JSON) {
            $decodedResponse = Json::decode($body);

            self::assertIsArray(
                $decodedResponse,
                'JSON response should be decodable to array',
            );
            self::assertArrayHasKey(
                'message',
                $decodedResponse,
                'JSON error response should contain message key',
            );
        }
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[RequiresPhpExtension('runkit7')]
    public function testRenderExceptionWithErrorActionReturningResponseObject(): void
    {
        @\runkit_constant_redefine('YII_DEBUG', false);

        $app = $this->statelessApplication(
            [
                'components' => [
                    'errorHandler' => ['errorAction' => 'site/error-with-response'],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createRequest('GET', '/site/trigger-exception'));

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
            self::normalizeLineEndings(
                <<<HTML
                <div id="custom-response-error">
                Custom Response object from error action: Exception error message.
                </div>
                HTML,
            ),
            self::normalizeLineEndings($response->getBody()->getContents()),
            'Response body should contain content from Response object.',
        );

        @\runkit_constant_redefine('YII_DEBUG', true);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnHtmlErrorResponseWhenErrorHandlerActionIsInvalid(): void
    {
        $app = $this->statelessApplication(
            [
                'components' => [
                    'errorHandler' => ['errorAction' => 'invalid/nonexistent-action'],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createRequest('GET', '/site/nonexistent-action'));

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Expected HTTP '500' for route 'site/nonexistent-action'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'text/html; charset=UTF-8' for route 'site/nonexistent-action'.",
        );
        self::assertStringContainsString(
            self::normalizeLineEndings(
                <<<HTML
                <pre>An Error occurred while handling another error:
                yii\base\InvalidRouteException: Unable to resolve the request &quot;invalid/nonexistent-action&quot;.
                HTML,
            ),
            self::normalizeLineEndings($response->getBody()->getContents()),
            "Response body should contain error message about 'An Error occurred while handling another error'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testThrowableOccursDuringRequestHandling(): void
    {
        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createRequest('GET', '/nonexistent/invalidaction'));

        self::assertSame(
            404,
            $response->getStatusCode(),
            "Expected HTTP '404' for route 'nonexistent/invalidaction'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'text/html; charset=UTF-8' for route 'nonexistent/invalidaction'.",
        );
        self::assertStringContainsString(
            '<pre>Not Found: Page not found.</pre>',
            $response->getBody()->getContents(),
            "Response body should contain error message about 'Not Found: Page not found'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testThrowNotFoundHttpExceptionWhenStrictParsingDisabledAndRouteIsMissing(): void
    {
        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createRequest('GET', '/site/profile/123'));

        self::assertSame(
            404,
            $response->getStatusCode(),
            "Expected HTTP '404' for route 'site/profile/123'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'text/html; charset=UTF-8' for route 'site/profile/123'.",
        );
        self::assertStringContainsString(
            '<pre>Not Found: Page not found.</pre>',
            $response->getBody()->getContents(),
            "Response body should contain the default not found message '<pre>Not Found: Page not found.</pre>'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[RequiresPhpExtension('runkit7')]
    public function testThrowNotFoundHttpExceptionWhenStrictParsingEnabledAndRouteIsMissing(): void
    {
        @\runkit_constant_redefine('YII_ENV_TEST', false);

        $initialBufferLevel = ob_get_level();

        $app = $this->statelessApplication(
            [
                'components' => [
                    'errorHandler' => ['errorAction' => null],
                    'response' => ['format' => Response::FORMAT_JSON],
                    'urlManager' => [
                        'enableStrictParsing' => true,
                        'rules' => [],
                    ],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createRequest('GET', '/site/profile/123'));

        self::assertSame(
            404,
            $response->getStatusCode(),
            "Expected HTTP '404' for route 'site/profile/123'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/profile/123'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"name":"Not Found","message":"Page not found in StatelessApplication.","code":0,"status":404,"type":"yii\\\\web\\\\NotFoundHttpException"}
            JSON,
            $response->getBody()->getContents(),
            'Response body should contain JSON with NotFoundHttpException details.',
        );

        while (ob_get_level() < $initialBufferLevel) {
            ob_start();
        }

        @\runkit_constant_redefine('YII_ENV_TEST', true);
    }
}
