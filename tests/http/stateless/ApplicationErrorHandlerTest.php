<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use yii\base\{Exception, InvalidConfigException};
use yii\helpers\Json;
use yii\log\{FileTarget, Logger};
use yii\web\NotFoundHttpException;
use yii2\extensions\psrbridge\exception\Message;
use yii2\extensions\psrbridge\http\Response;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function array_filter;
use function ini_get;
use function ini_set;
use function is_array;
use function ob_get_level;
use function ob_start;
use function restore_error_handler;
use function set_error_handler;
use function str_contains;

final class ApplicationErrorHandlerTest extends TestCase
{
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
                "\n\$_SERVER = [",
                $body,
                "Response body should contain '\$_SERVER = [' in correct order (label before array) for fallback " .
                'exception debug output.',
            );
            self::assertStringNotContainsString(
                'not-a-secret-api-key',
                $body,
                'Response body should NOT contain API_KEY value in debug output for fallback exception.',
            );
            self::assertStringNotContainsString(
                'dummy-bearer-token',
                $body,
                'Response body should NOT contain AUTH_TOKEN value in debug output for fallback exception',
            );
            self::assertStringNotContainsString(
                'not-a-real-password',
                $body,
                'Response body should NOT contain DB_PASSWORD value in debug output for fallback exception.',
            );
            self::assertStringContainsString(
                'example.com',
                $body,
                'Response body should contain HTTP_HOST value in debug output for fallback exception.',
            );
            self::assertStringNotContainsString(
                'not-a-real-secret-key',
                $body,
                'Response body should NOT contain SECRET_KEY value in debug output for fallback exception.',
            );
            self::assertStringContainsString(
                'this-should-appear',
                $body,
                'Response body should contain SAFE_VARIABLE value in debug output for fallback exception.',
            );
        }
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

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

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

        $logMessages = $app->getLog()->getLogger()->messages;

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
            "Logger should contain an error log entry with category '{$expectedCategory}' and message 'Exception error message.' " .
            "when 'logException()' is called during exception handling.",
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
                        'errorHandler' => ['errorAction' => null],
                    ],
                ],
            );

            $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

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
                "Should be no 'Undefined variable' warnings, confirming that 'exception' parameter is defined in the " .
                'view context when rendering exception.',
            );

            $body = $response->getBody()->getContents();

            self::assertStringContainsString(
                Exception::class,
                $body,
                "Response body should contain exception class when 'exception' parameter is passed to 'renderFile()'.",
            );
            self::assertStringContainsString(
                'Stack trace:',
                $body,
                "Response body should contain 'Stack trace:' section, confirming exception object is available to template.",
            );
            self::assertStringContainsString(
                'Exception error message.',
                $body,
                "Response body should contain the exact exception message 'Exception error message.', " .
                'confirming the exception object was properly passed to the view.',
            );
            self::assertStringContainsString(
                'SiteController.php',
                $body,
                "Response body should contain reference to 'SiteController.php' where the exception was thrown, " .
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
                'errorHandler' => ['errorAction' => null],
            ],
        ]);

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

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
            "'display_errors' should be set to '1' when YII_DEBUG is 'true' and rendering exception view.",
        );
        self::assertStringContainsString(
            'yii\base\Exception: Exception error message.',
            $response->getBody()->getContents(),
            "Response should contain exception details when YII_DEBUG is 'true'.",
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
                    'errorHandler' => ['errorAction' => 'site/error-with-response'],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

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
            "Response body should contain content from Response object returned by 'errorAction'.",
        );

        @\runkit_constant_redefine('YII_DEBUG', true);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testRenderExceptionWithRawFormat(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/trigger-exception',
        ];

        $app = $this->statelessApplication(
            [
                'components' => [
                    'response' => ['format' => Response::FORMAT_RAW],
                    'errorHandler' => ['errorAction' => null],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Expected HTTP '500' for route 'site/trigger-exception'.",
        );
        self::assertEmpty(
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type empty string for route 'site/trigger-exception'.",
        );

        $body = $response->getBody()->getContents();

        self::assertStringContainsString(
            Exception::class,
            $body,
            'RAW format response should contain exception class name.',
        );
        self::assertStringContainsString(
            'Exception error message.',
            $body,
            'RAW format response should contain exception message.',
        );
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
                    'errorHandler' => ['errorAction' => 'invalid/nonexistent-action'],
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
        self::assertStringContainsString(
            self::normalizeLineEndings(
                <<<HTML
                <pre>An Error occurred while handling another error:
                yii\base\InvalidRouteException: Unable to resolve the request &quot;invalid/nonexistent-action&quot;.
                HTML,
            ),
            self::normalizeLineEndings($response->getBody()->getContents()),
            "Response body should contain error message about 'An Error occurred while handling another error' and " .
            'the InvalidRouteException when errorHandler action is invalid.',
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
            "Response body should contain error message about 'Not Found: Page not found' when 'Throwable' occurs " .
            'during request handling.',
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
            "Response body should contain the default not found message '<pre>Not Found: Page not found.</pre>' " .
            "when a 'NotFoundHttpException' is thrown.",
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
                    'urlManager' => ['enableStrictParsing' => true],
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
                    'errorHandler' => ['errorAction' => 'site/error'],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Expected HTTP '500' for route 'site/trigger-exception'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'text/html; charset=UTF-8' for route 'site/trigger-exception''.",
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
            "Response body should contain 'Custom error page from errorAction' when 'Exception' is triggered " .
            "and 'debug' mode is disabled with errorAction configured.",
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
                    'errorHandler' => ['errorAction' => 'site/error'],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Expected HTTP '500' for route 'site/trigger-user-exception'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'text/html; charset=UTF-8' for route 'site/trigger-user-exception''.",
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
            "Response body should contain 'Custom error page from errorAction' when 'UserException' is triggered " .
            "and 'debug' mode is disabled with errorAction configured.",
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
                    'errorHandler' => ['errorAction' => 'site/error'],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Expected HTTP '500' for route 'site/trigger-user-exception'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'text/html; charset=UTF-8' for route 'site/trigger-user-exception''.",
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
            "Response body should contain 'User-friendly error message.' when 'UserException' is triggered and " .
            "'debug' mode is enabled.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testUseErrorViewLogicWithNonHtmlFormat(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/trigger-exception',
        ];

        $app = $this->statelessApplication(
            [
                'components' => [
                    'errorHandler' => ['errorAction' => 'site/error'],
                    'response' => ['format' => Response::FORMAT_JSON],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Expected HTTP '500' for route 'site/error'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/error''.",
        );

        $body = $response->getBody()->getContents();

        self::assertStringNotContainsString(
            'Custom error page from errorAction.',
            $body,
            "Response body should NOT contain 'Custom error page from errorAction' when format is JSON " .
            "because 'useErrorView' should be 'false' regardless of YII_DEBUG or exception type.",
        );

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
