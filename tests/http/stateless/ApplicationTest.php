<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\{Group, RequiresPhpExtension, TestWith};
use yii\base\{Exception, InvalidConfigException};
use yii\helpers\Json;
use yii\log\{FileTarget, Logger};
use yii\web\NotFoundHttpException;
use yii2\extensions\psrbridge\exception\Message;
use yii2\extensions\psrbridge\http\{Response, StatelessApplication};
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function array_filter;
use function explode;
use function ini_get;
use function ini_set;
use function ob_get_level;
use function ob_start;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function str_contains;
use function str_starts_with;

#[Group('http')]
final class ApplicationTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->closeApplication();

        parent::tearDown();
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testLogExceptionIsCalledWhenHandlingException(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/trigger-exception',
        ];

        $app = $this->statelessApplication(
            [
                'flushLogger' => false,
                'components' => [
                    'errorHandler' => [
                        'errorAction' => null,
                    ],
                    'log' => [
                        'traceLevel' => YII_DEBUG ? 1 : 0,
                        'targets' => [
                            [
                                'class' => FileTarget::class,
                                'levels' => [
                                    'error',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());
        $logMessages = $app->getLog()->getLogger()->messages;

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Response 'status code' should be '500' when an exception occurs in 'StatelessApplication'.",
        );
        self::assertNotEmpty(
            $logMessages,
            "Logger should contain log messages after handling an exception in 'StatelessApplication'.",
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
            "Logger should contain an error log entry with category '{$expectedCategory}' and message 'Exception error message.' " .
            "when 'logException()' is called during exception handling in 'StatelessApplication'.",
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

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/trigger-exception',
        ];

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
                        'errorHandler' => [
                            'errorAction' => null,
                        ],
                    ],
                ],
            );

            $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

            $undefinedExceptionWarnings = array_filter(
                $warningsCaptured,
                static fn(array $warning): bool => str_contains($warning['message'], 'Undefined variable'),
            );

            self::assertEmpty(
                $undefinedExceptionWarnings,
                "Should be no 'Undefined variable' warnings, confirming that 'exception' parameter is defined in the " .
                "view context when rendering exception in 'StatelessApplication'.",
            );
            self::assertSame(
                500,
                $response->getStatusCode(),
                "Response 'status code' should be '500' when exception occurs and template rendering is used in " .
                "'StatelessApplication'.",
            );

            $responseBody = $response->getBody()->getContents();

            self::assertStringContainsString(
                Exception::class,
                $responseBody,
                "Response 'body' should contain exception class when 'exception' parameter is passed to 'renderFile()'.",
            );
            self::assertStringContainsString(
                'Stack trace:',
                $responseBody,
                "Response 'body' should contain 'Stack trace:' section, confirming exception object is available to template.",
            );
            self::assertStringContainsString(
                'Exception error message.',
                $responseBody,
                "Response 'body' should contain the exact exception message 'Exception error message.', " .
                'confirming the exception object was properly passed to the view.',
            );
            self::assertStringContainsString(
                'SiteController.php',
                $responseBody,
                "Response 'body' should contain reference to 'SiteController.php' where the exception was thrown, " .
                'confirming full exception details are available in the view.',
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
     */
    #[RequiresPhpExtension('runkit7')]
    public function testRenderExceptionSetsDisplayErrorsInDebugMode(): void
    {
        @\runkit_constant_redefine('YII_ENV_TEST', false);

        $initialBufferLevel = ob_get_level();

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/trigger-exception',
        ];

        $originalDisplayErrors = ini_get('display_errors');

        $app = $this->statelessApplication([
            'components' => [
                'errorHandler' => [
                    'errorAction' => null,
                ],
            ],
        ]);

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            '1',
            ini_get('display_errors'),
            "'display_errors' should be set to '1' when 'YII_DEBUG' is 'true' and rendering exception view.",
        );
        self::assertSame(
            500,
            $response->getStatusCode(),
            "Response 'status code' should be '500' for exception.",
        );
        self::assertStringContainsString(
            'yii\base\Exception: Exception error message.',
            $response->getBody()->getContents(),
            "Response should contain exception details when 'YII_DEBUG' is 'true'.",
        );

        ini_set('display_errors', $originalDisplayErrors);

        while (ob_get_level() < $initialBufferLevel) {
            ob_start();
        }

        @\runkit_constant_redefine('YII_ENV_TEST', true);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[RequiresPhpExtension('runkit7')]
    public function testRenderExceptionWithErrorActionReturningResponseObject(): void
    {
        @\runkit_constant_redefine('YII_DEBUG', false);

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/trigger-exception',
        ];

        $app = $this->statelessApplication(
            [
                'components' => [
                    'errorHandler' => [
                        'errorAction' => 'site/error-with-response',
                    ],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Response 'status code' should be '500' when 'errorAction' returns Response object.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'text/html; charset=UTF-8' when 'errorAction' returns Response object.",
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
            "Response 'body' should contain content from Response object returned by 'errorAction'.",
        );

        @\runkit_constant_redefine('YII_DEBUG', true);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnCookiesHeadersForSiteCookieRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/cookie',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/cookie' route in 'StatelessApplication'.",
        );

        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            // skip the session cookie header
            if (str_starts_with($cookie, $app->session->getName()) === false) {
                $params = explode('; ', $cookie);

                self::assertContains(
                    $params[0],
                    [
                        'test=test',
                        'test2=test2',
                    ],
                    sprintf(
                        "Cookie header should contain either 'test=test' or 'test2=test2', got '%s' for " .
                        "'site/cookie' route.",
                        $params[0],
                    ),
                );
                self::assertStringContainsString(
                    'Path=/',
                    $cookie,
                    "Cookie header should contain 'Path=/' for 'site/cookie' route.",
                );
                self::assertStringNotContainsString(
                    'Secure',
                    $cookie,
                    sprintf(
                        "Cookie header should not contain 'Secure' flag for '%s', got '%s' for 'site/cookie' route.",
                        $params[0],
                        $cookie,
                    ),
                );
                self::assertStringNotContainsString(
                    'HttpOnly',
                    $cookie,
                    sprintf(
                        "Cookie header should not contain 'HttpOnly' flag for '%s', got '%s' for 'site/cookie' route.",
                        $params[0],
                        $cookie,
                    ),
                );
                self::assertStringContainsString(
                    'SameSite=Lax',
                    $cookie,
                    sprintf(
                        "Cookie header should contain 'SameSite=Lax' for '%s', got '%s' for 'site/cookie' route.",
                        $params[0],
                        $cookie,
                    ),
                );
            }
        }
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnHtmlErrorResponseWhenErrorHandlerActionIsInvalid(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/nonexistent-action',
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
            "Response 'status code' should be '500' when 'ErrorHandler' is misconfigured and a nonexistent action is " .
            "requested in 'StatelessApplication'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'text/html; charset=UTF-8' for error response when ErrorHandler " .
            "action is invalid in 'StatelessApplication'.",
        );
        self::assertStringContainsString(
            self::normalizeLineEndings(
                <<<HTML
                <pre>An Error occurred while handling another error:
                yii\base\InvalidRouteException: Unable to resolve the request &quot;invalid/nonexistent-action&quot;.
                HTML,
            ),
            self::normalizeLineEndings($response->getBody()->getContents()),
            "Response 'body' should contain error message about 'An Error occurred while handling another error' and " .
            "the InvalidRouteException when errorHandler action is invalid in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnJsonResponseWithPostParametersForSitePostRoute(): void
    {
        $_POST = [
            'foo' => 'bar',
            'a' => [
                'b' => 'c',
            ],
        ];
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => 'site/post',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/post' route in 'StatelessApplication'.",
        );
        self::assertSame(
            <<<JSON
            {"foo":"bar","a":{"b":"c"}}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should match expected JSON string '{\"foo\":\"bar\",\"a\":{\"b\":\"c\"}}' for " .
            "'site/post' route in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnJsonResponseWithQueryParametersForSiteGetRoute(): void
    {
        $_GET = [
            'foo' => 'bar',
            'a' => [
                'b' => 'c',
            ],
        ];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/get',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/get' route in 'StatelessApplication'.",
        );
        self::assertSame(
            <<<JSON
            {"foo":"bar","a":{"b":"c"}}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should match expected JSON string '{\"foo\":\"bar\",\"a\":{\"b\":\"c\"}}' for " .
            "'site/get' route in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnJsonResponseWithQueryParamsForSiteQueryRoute(): void
    {
        $_GET = ['q' => '1'];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/query/foo?q=1',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/query/foo?q=1' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/query/foo?q=1' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            '{"test":"foo","q":"1","queryParams":{"test":"foo","q":"1"}}',
            $response->getBody()->getContents(),
            "Response 'body' should contain valid JSON with route and query parameters for 'site/query/foo?q=1' in " .
            "'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnJsonResponseWithRouteParameterForSiteUpdateRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/update/123',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response = $app->handle($request);

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/update/123' route in 'StatelessApplication', " .
            'indicating a successful update.',
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/update/123' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            '{"site/update":"123"}',
            $response->getBody()->getContents(),
            "Response 'body' should contain valid JSON with the route parameter for 'site/update/123' in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            'site/update/123',
            $request->getUri()->getPath(),
            "Request 'path' should be 'site/update/123' for 'site/update/123' route in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnPlainTextFileResponseForSiteFileRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/file',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/file' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'text/plain',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'text/plain' for 'site/file' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'This is a test file content.',
            $response->getBody()->getContents(),
            "Response 'body' should match expected plain text 'This is a test file content.' for 'site/file' route " .
            "in 'StatelessApplication'.",
        );
        self::assertSame(
            'attachment; filename="testfile.txt"',
            $response->getHeaderLine('Content-Disposition'),
            "Response 'Content-Disposition' should be 'attachment; filename=\"testfile.txt\"' for 'site/file' route " .
            "in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnPlainTextResponseWithFileContentForSiteStreamRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/stream',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/stream' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'text/plain',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'text/plain' for 'site/stream' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'This is a test file content.',
            $response->getBody()->getContents(),
            "Response 'body' should match expected plain text 'This is a test file content.' for 'site/stream' route " .
            "in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnRedirectResponseForSiteRedirectRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/redirect',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            302,
            $response->getStatusCode(),
            "Response 'status code' should be '302' for redirect route 'site/redirect' in 'StatelessApplication'.",
        );
        self::assertSame(
            '/site/index',
            $response->getHeaderLine('Location'),
            "Response 'Location' header should be '/site/index' for redirect route 'site/redirect' in " .
            "'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnRedirectResponseForSiteRefreshRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/refresh',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            302,
            $response->getStatusCode(),
            "Response 'status code' should be '302' for redirect route 'site/refresh' in 'StatelessApplication'.",
        );
        self::assertSame(
            'site/refresh#stateless',
            $response->getHeaderLine('Location'),
            "Response 'Location' header should be 'site/refresh#stateless' for redirect route 'site/refresh' in " .
            "'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnsJsonResponse(): void
    {
        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for successful 'StatelessApplication' handling.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for JSON output.",
        );
        self::assertSame(
            <<<JSON
            {"hello":"world"}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should match expected JSON string '{\"hello\":\"world\"}'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnsStatusCode201ForSiteStatusCodeRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/statuscode',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            201,
            $response->getStatusCode(),
            "Response 'status code' should be '201' for 'site/statuscode' route in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testThrowableOccursDuringRequestHandling(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'nonexistent/invalidaction',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            404,
            $response->getStatusCode(),
            "Response 'status code' should be '404' when handling a request to 'non-existent' route in " .
            "'StatelessApplication', confirming proper error handling in catch block.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'text/html; charset=UTF-8' for error response when 'Throwable' occurs " .
            "during request handling in 'StatelessApplication'.",
        );
        self::assertStringContainsString(
            '<pre>Not Found: Page not found.</pre>',
            $response->getBody()->getContents(),
            "Response 'body' should contain error message about 'Not Found: Page not found' when 'Throwable' occurs " .
            "during request handling in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testThrowNotFoundHttpExceptionWhenStrictParsingDisabledAndRouteIsMissing(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/profile/123',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            404,
            $response->getStatusCode(),
            "Response 'status code' should be '404' when accessing a non-existent route in 'StatelessApplication', " .
            "indicating a 'Not Found' error.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'text/html; charset=UTF-8' for 'NotFoundHttpException' in " .
            "'StatelessApplication'.",
        );
        self::assertStringContainsString(
            '<pre>Not Found: Page not found.</pre>',
            $response->getBody()->getContents(),
            "Response 'body' should contain the default not found message '<pre>Not Found: Page not found.</pre>' " .
            "when a 'NotFoundHttpException' is thrown in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testThrowNotFoundHttpExceptionWhenStrictParsingEnabledAndRouteIsMissing(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/profile/123',
        ];

        $app = $this->statelessApplication(
            [
                'components' => [
                    'urlManager' => [
                        'enableStrictParsing' => true,
                    ],
                ],
            ],
        );

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage(Message::PAGE_NOT_FOUND->getMessage());

        $app->request->resolve();
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[TestWith([StatelessApplication::EVENT_AFTER_REQUEST])]
    #[TestWith([StatelessApplication::EVENT_BEFORE_REQUEST])]
    public function testTriggerEventDuringHandle(string $eventName): void
    {
        $eventTriggered = false;

        $app = $this->statelessApplication();

        $app->on(
            $eventName,
            static function () use (&$eventTriggered): void {
                $eventTriggered = true;
            },
        );

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertTrue($eventTriggered, "Should trigger '{$eventName}' event during handle()");
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[RequiresPhpExtension('runkit7')]
    public function testUseErrorViewLogicWithDebugFalseAndException(): void
    {
        @\runkit_constant_redefine('YII_DEBUG', false);

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/trigger-exception',
        ];

        $app = $this->statelessApplication(
            [
                'components' => [
                    'errorHandler' => [
                        'errorAction' => 'site/error',
                    ],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Response 'status code' should be '500' when a 'Exception' occurs and 'debug' mode is disabled in " .
            "'StatelessApplication', indicating an 'internal server error'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'text/html; charset=UTF-8' for error response when 'Exception' " .
            "occurs and 'debug' mode is disabled in 'StatelessApplication'.",
        );
        self::assertSame(
            self::normalizeLineEndings(
                <<<HTML
                <div id="custom-error-action">
                Custom error page from errorAction.
                <span class="exception-type">
                yii\base\Exception
                </span>
                <span class="exception-message">
                Exception error message.
                </span>
                </div>
                HTML,
            ),
            self::normalizeLineEndings($response->getBody()->getContents()),
            "Response 'body' should contain 'Custom error page from errorAction' when 'Exception' is triggered " .
            "and 'debug' mode is disabled with errorAction configured in 'StatelessApplication'.",
        );

        @\runkit_constant_redefine('YII_DEBUG', true);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[RequiresPhpExtension('runkit7')]
    public function testUseErrorViewLogicWithDebugFalseAndUserException(): void
    {
        @\runkit_constant_redefine('YII_DEBUG', false);

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/trigger-user-exception',
        ];

        $app = $this->statelessApplication(
            [
                'components' => [
                    'errorHandler' => [
                        'errorAction' => 'site/error',
                    ],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Response 'status code' should be '500' when a 'UserException' occurs and 'debug' mode is disabled in " .
            "'StatelessApplication', indicating an 'internal server error'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'text/html; charset=UTF-8' for error response when 'UserException' " .
            "occurs and 'debug' mode is disabled in 'StatelessApplication'.",
        );
        self::assertSame(
            self::normalizeLineEndings(
                <<<HTML
                <div id="custom-error-action">
                Custom error page from errorAction.
                <span class="exception-type">
                yii\base\UserException
                </span>
                <span class="exception-message">
                User-friendly error message.
                </span>
                </div>
                HTML,
            ),
            self::normalizeLineEndings($response->getBody()->getContents()),
            "Response 'body' should contain 'Custom error page from errorAction' when 'UserException' is triggered " .
            "and 'debug' mode is disabled with errorAction configured in 'StatelessApplication'.",
        );

        @\runkit_constant_redefine('YII_DEBUG', true);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testUseErrorViewLogicWithDebugTrueAndUserException(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/trigger-user-exception',
        ];

        $app = $this->statelessApplication(
            [
                'components' => [
                    'errorHandler' => [
                        'errorAction' => 'site/error',
                    ],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Response 'status code' should be '500' when a 'UserException' occurs and 'debug' mode is enabled in " .
            "'StatelessApplication', indicating an 'internal server error'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'text/html; charset=UTF-8' for error response when 'UserException'" .
            "occurs and 'debug' mode is enabled in 'StatelessApplication'.",
        );
        self::assertSame(
            self::normalizeLineEndings(
                <<<HTML
                <div id="custom-error-action">
                Custom error page from errorAction.
                <span class="exception-type">
                yii\base\UserException
                </span>
                <span class="exception-message">
                User-friendly error message.
                </span>
                </div>
                HTML,
            ),
            self::normalizeLineEndings($response->getBody()->getContents()),
            "Response 'body' should contain 'User-friendly error message.' when 'UserException' is triggered and " .
            "'debug' mode is enabled in 'StatelessApplication'.",
        );
    }
}
