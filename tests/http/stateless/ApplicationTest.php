<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group, RequiresPhpExtension};
use stdClass;
use yii\base\{Exception, InvalidConfigException};
use yii\helpers\Json;
use yii\log\{FileTarget, Logger};
use yii\web\NotFoundHttpException;
use yii2\extensions\psrbridge\exception\Message;
use yii2\extensions\psrbridge\http\Response;
use yii2\extensions\psrbridge\tests\provider\StatelessApplicationProvider;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function array_filter;
use function base64_encode;
use function explode;
use function implode;
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
            "Response 'status code' should be '500' when 'ErrorHandler' triggers fallback exception handling in " .
            "'StatelessApplication'.",
        );

        $responseBody = $response->getBody()->getContents();

        self::assertStringContainsString(
            'An Error occurred while handling another error:',
            $responseBody,
            "Response 'body' should contain fallback error message when 'ErrorHandler' action is invalid in " .
            "'StatelessApplication'.",
        );

        if (YII_DEBUG) {
            self::assertStringContainsString(
                "\n\$_SERVER = [",
                $responseBody,
                "Response 'body' should contain '\$_SERVER = [' in correct order (label before array) for fallback " .
                "exception debug output in 'StatelessApplication'.",
            );
            self::assertStringNotContainsString(
                'not-a-secret-api-key',
                $responseBody,
                "Response 'body' should NOT contain 'API_KEY' value in debug output for fallback exception in " .
                "'StatelessApplication'.",
            );
            self::assertStringNotContainsString(
                'dummy-bearer-token',
                $responseBody,
                "Response 'body' should NOT contain 'AUTH_TOKEN' value in debug output for fallback exception in " .
                "'StatelessApplication'.",
            );
            self::assertStringNotContainsString(
                'not-a-real-password',
                $responseBody,
                "Response 'body' should NOT contain 'DB_PASSWORD' value in debug output for fallback exception in " .
                "'StatelessApplication'.",
            );
            self::assertStringContainsString(
                'example.com',
                $responseBody,
                "Response 'body' should contain 'HTTP_HOST' value in debug output for fallback exception in " .
                "'StatelessApplication'.",
            );
            self::assertStringNotContainsString(
                'not-a-real-secret-key',
                $responseBody,
                "Response 'body' should NOT contain 'SECRET_KEY' value in debug output for fallback exception in " .
                "'StatelessApplication'.",
            );
            self::assertStringContainsString(
                'this-should-appear',
                $responseBody,
                "Response 'body' should contain 'SAFE_VARIABLE' value in debug output for fallback exception in " .
                "'StatelessApplication'.",
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
    public function testRenderExceptionWithRawFormat(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/trigger-exception',
        ];

        $app = $this->statelessApplication(
            [
                'components' => [
                    'response' => [
                        'format' => Response::FORMAT_RAW,
                    ],
                    'errorHandler' => [
                        'errorAction' => null,
                    ],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Response 'status code' should be '500' for exception with RAW format.",
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
    public function testReturnCredentialsWhenValidBasicAuthorizationHeaderIsPresent(): void
    {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('user:pass'),
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/auth',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/auth' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/auth' route in " .
            "'StatelessApplication'.",
        );

        $responseData = Json::decode($response->getBody()->getContents());

        self::assertIsArray(
            $responseData,
            "Response 'body' should be an array after decoding JSON response from 'site/auth' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            'user',
            $responseData['username'] ?? '',
            "Should extract 'user' with correct 'Basic ' ('6' chars) prefix",
        );
        self::assertSame(
            'pass',
            $responseData['password'] ?? '',
            "Should extract 'pass' with correct 'Basic ' ('6' chars) prefix",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnCredentialsWithMultibyteCharacters(): void
    {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => "basic\xC2\xA0" . base64_encode('user:pass'),
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/auth',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/auth' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/auth' route in " .
            "'StatelessApplication'.",
        );

        $responseData = Json::decode($response->getBody()->getContents());

        self::assertIsArray(
            $responseData,
            "Response 'body' should be an array after decoding JSON response from 'site/auth' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            'user',
            $responseData['username'] ?? '',
            "Should handle multibyte characters in 'username'",
        );
        self::assertSame(
            'pass',
            $responseData['password'] ?? '',
            "Should handle multibyte characters in 'password'",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnEmptyCookieCollectionWhenValidationEnabledWithInvalidCookies(): void
    {
        $app = $this->statelessApplication(
            [
                'components' => [
                    'request' => [
                        'cookieValidationKey' => self::COOKIE_VALIDATION_KEY,
                        'enableCookieValidation' => true,
                    ],
                ],
            ],
        );

        $response = $app->handle(
            FactoryHelper::createRequest('GET', 'site/getcookies')
                ->withCookieParams(
                    [
                        'invalid_cookie' => 'invalid_data',
                        'empty_cookie' => '',
                    ],
                ),
        );

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/getcookies' with validation enabled.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/getcookies'.",
        );
        self::assertSame(
            '[]',
            $response->getBody()->getContents(),
            'CookieCollection should be empty when validation is enabled but cookies are invalid.',
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
    public function testReturnJsonResponseWithCookiesForSiteGetCookiesRoute(): void
    {
        $_COOKIE = [
            'test' => 'test',
        ];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/getcookies',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/getcookies' route in 'StatelessApplication'.",
        );
        self::assertSame(
            <<<JSON
            {"test":{"name":"test","value":"test","domain":"","expire":null,"path":"/","secure":false,"httpOnly":true,"sameSite":"Lax"}}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should match expected JSON string for cookie 'test' on 'site/getcookies' route in " .
            "'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnJsonResponseWithCredentialsForSiteAuthRoute(): void
    {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('admin:admin'),
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/auth',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/auth' route in 'StatelessApplication'.",
        );
        self::assertSame(
            <<<JSON
            {"username":"admin","password":"admin"}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should match expected JSON string '{\"username\":\"admin\",\"password\":\"admin\"}' " .
            "for 'site/auth' route in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnJsonResponseWithNullCredentialsForMalformedAuthorizationHeader(): void
    {
        $_SERVER = [
            'HTTP_authorization' => 'Basic foo:bar',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/auth',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/auth' route with malformed authorization header in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            <<<JSON
            {"username":null,"password":null}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should match expected JSON string '{\"username\":null,\"password\":null}' for malformed " .
            "authorization header on 'site/auth' route in 'StatelessApplication'.",
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
    public function testReturnMultipleValidatedCookiesWhenValidationEnabledWithMultipleValidCookies(): void
    {
        $cookies = [
            'session_id' => 'session_value_123',
            'user_pref' => 'preference_value_456',
            'theme' => 'dark_theme_789',
            'language' => 'en_US_012',
        ];

        $signedCookies = [];

        foreach ($cookies as $name => $value) {
            $signedCookies[$name] = $this->signCookie($name, $value);
        }

        $app = $this->statelessApplication(
            [
                'components' => [
                    'request' => [
                        'cookieValidationKey' => self::COOKIE_VALIDATION_KEY,
                        'enableCookieValidation' => true,
                    ],
                ],
            ],
        );

        $response = $app->handle(
            FactoryHelper::createRequest('GET', 'site/getcookies')
                ->withCookieParams($signedCookies),
        );

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/getcookies' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/getcookies'.",
        );

        /**
         * @phpstan-var array<
         *   string,
         *   array{
         *     name: string,
         *     value: string,
         *     domain: string,
         *     expire: ?int,
         *     path: string,
         *     secure: bool,
         *     httpOnly: bool,
         *     sameSite: string
         *   }
         * > $expectedCookies
         */
        $expectedCookies = Json::decode($response->getBody()->getContents());

        self::assertCount(
            4,
            $expectedCookies,
            "Should return all '4' validated cookies, not just '1'.",
        );

        foreach ($cookies as $name => $value) {
            self::assertSame(
                $name,
                $expectedCookies[$name]['name'] ?? null,
                "Cookie name for '{$name}' should match the original cookie name in 'StatelessApplication'.",
            );
            self::assertSame(
                $value,
                $expectedCookies[$name]['value'],
                "Cookie value for '{$name}' should match the original cookie value in 'StatelessApplication'.",
            );
            self::assertEmpty(
                $expectedCookies[$name]['domain'],
                "Cookie 'domain' for '{$name}' should be an empty string in 'StatelessApplication'.",
            );
            self::assertNull(
                $expectedCookies[$name]['expire'],
                "Cookie 'expire' for '{$name}' should be 'null' in 'StatelessApplication'.",
            );
            self::assertSame(
                '/',
                $expectedCookies[$name]['path'],
                "Cookie 'path' for '{$name}' should be '/' in 'StatelessApplication'.",
            );
            self::assertFalse(
                $expectedCookies[$name]['secure'],
                "Cookie 'secure' flag for '{$name}' should be 'false' in 'StatelessApplication'.",
            );
            self::assertTrue(
                $expectedCookies[$name]['httpOnly'],
                "Cookie 'httpOnly' flag for '{$name}' should be 'true' in 'StatelessApplication'.",
            );
            self::assertSame(
                'Lax',
                $expectedCookies[$name]['sameSite'],
                "Cookie 'sameSite' for '{$name}' should be 'Lax' in 'StatelessApplication'.",
            );
        }
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnNullCredentialsWhenAuthorizationHeaderHasInvalidBasicPrefix(): void
    {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => 'basix ' . base64_encode('user:pass'),
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/auth',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/auth' route with 'basix' 'HTTP_AUTHORIZATION' header " .
            "in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/auth' route with 'basix' " .
            "'HTTP_AUTHORIZATION' header in 'StatelessApplication'.",
        );
        self::assertSame(
            <<<JSON
            {"username":null,"password":null}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should return 'null' credentials when the 'HTTP_AUTHORIZATION' header does not start " .
            "with 'Basic ' (case-insensitive) for 'site/auth' route in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnNullCredentialsWhenBasicAuthorizationHeaderHasInvalidBase64DueToMissingSpace(): void
    {
        $criticalBase64 = base64_encode('a:b'); // "YTpi"

        $_SERVER = [
            'HTTP_AUTHORIZATION' => "Basic{$criticalBase64}", // "BasicYTpi"
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/auth',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/auth' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/auth' route in " .
            "'StatelessApplication'.",
        );

        $responseData = Json::decode($response->getBody()->getContents());

        self::assertIsArray(
            $responseData,
            "Response 'body' should be an array after decoding JSON response from 'site/auth' route in " .
            "'StatelessApplication'.",
        );
        self::assertNull(
            $responseData['username'] ?? null,
            'Should be null when cutting at position 6 produces invalid base64',
        );
        self::assertNull(
            $responseData['password'] ?? null,
            'Should be null when cutting at position 6 produces invalid base64',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnNullCredentialsWhenBasicAuthorizationHeaderLacksSpace(): void
    {
        $base64Token = base64_encode('user:pass'); // 'dXNlcjpwYXNz'

        $_SERVER = [
            'HTTP_AUTHORIZATION' => "Basic{$base64Token}", // 'BasicdXNlcjpwYXNz'
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/auth',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/auth' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/auth' route in " .
            "'StatelessApplication'.",
        );

        $responseData = Json::decode($response->getBody()->getContents());

        self::assertIsArray(
            $responseData,
            "Response 'body' should be an array after decoding JSON response from 'site/auth' route in " .
            "'StatelessApplication'.",
        );
        self::assertNull(
            $responseData['username'] ?? null,
            "Should be 'null' when 'Basic' lacks the required space after the scheme",
        );
        self::assertNull(
            $responseData['password'] ?? null,
            'Should be null when the Authorization header lacks a space after Basic (invalid base64 segment)',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnPartialCredentialsWhenOnlyUsernameIsPresent(): void
    {
        $_SERVER = [
            'PHP_AUTH_USER' => 'admin',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/auth',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/auth' route with only 'PHP_AUTH_USER' set in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/auth' route with only " .
            "'PHP_AUTH_USER' set in 'StatelessApplication'.",
        );
        self::assertSame(
            <<<JSON
            {"username":"admin","password":null}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should return username 'admin' and 'null' password when only 'PHP_AUTH_USER' is " .
            "present, confirming OR logic works correctly for 'site/auth' route in 'StatelessApplication'.",
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
    public function testReturnSerializedObjectAndPrimitiveCookiesForGetCookiesRoute(): void
    {
        $cookieObject = new stdClass();

        $cookieObject->property = 'object_value';

        $app = $this->statelessApplication([
            'components' => [
                'request' => [
                    'cookieValidationKey' => self::COOKIE_VALIDATION_KEY,
                    'enableCookieValidation' => true,
                ],
            ],
        ]);

        $response = $app->handle(
            FactoryHelper::createRequest('GET', 'site/getcookies')
                ->withCookieParams(
                    [
                        'object_session' => $this->signCookie('object_session', $cookieObject),
                        'validated_session' => $this->signCookie('validated_session', 'safe_value'),
                    ],
                ),
        );

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Should return status code '200' for 'site/getcookies' route with serialized 'object' and primitive " .
            'cookies.',
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/getcookies' route " .
            'with validation enabled.',
        );

        $responseBody = $response->getBody()->getContents();

        $cookies = Json::decode($responseBody);

        self::assertIsArray(
            $cookies,
            "Response 'body' should be decodable to array of cookies for 'site/getcookies' route.",
        );
        self::assertArrayHasKey(
            'object_session',
            $cookies,
            "Response should contain the 'object_session' cookie entry.",
        );
        self::assertArrayHasKey(
            'validated_session',
            $cookies,
            "Response should contain the 'validated_session' cookie entry.",
        );

        $objectCookie = $cookies['object_session'] ?? null;

        self::assertIsArray(
            $objectCookie,
            "'object_session' cookie payload should be an array.",
        );
        self::assertSame(
            'object_session',
            $objectCookie['name'] ?? null,
            "Object cookie 'name' should be 'object_session'.",
        );

        $objectValue = $objectCookie['value'] ?? null;

        self::assertIsArray(
            $objectValue,
            "Object cookie 'value' should be sanitized to an array (incomplete class representation).",
        );
        self::assertSame(
            'stdClass',
            $objectValue['__PHP_Incomplete_Class_Name'] ?? null,
            "Sanitized object should include '__PHP_Incomplete_Class_Name' => 'stdClass'.",
        );
        self::assertSame(
            'object_value',
            $objectValue['property'] ?? null,
            "Sanitized object should preserve the original 'property' value.",
        );
        self::assertIsArray(
            $cookies['validated_session'] ?? null,
            "'validated_session' cookie payload should be an array.",
        );
        self::assertSame(
            'validated_session',
            $cookies['validated_session']['name'] ?? null,
            "Validated primitive cookie 'name' should be 'validated_session'.",
        );
        self::assertSame(
            'safe_value',
            $cookies['validated_session']['value'] ?? null,
            "Validated primitive cookie should preserve its 'value' as 'safe_value'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnSetCookieHeadersForCookieDeletionWithEmptyValues(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/deletecookie',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/deletecookie' route in 'StatelessApplication'.",
        );

        $deletionHeaderFound = false;
        $deletionHeader = '';

        foreach ($response->getHeader('Set-Cookie') as $header) {
            // skip session cookie headers
            if (
                str_starts_with($header, 'user_preference=') &&
                str_starts_with($header, $app->session->getName()) === false
            ) {
                $deletionHeaderFound = true;
                $deletionHeader = $header;

                break;
            }
        }

        self::assertTrue(
            $deletionHeaderFound,
            "Response 'Set-Cookie' headers should contain cookie deletion header for 'user_preference' cookie in " .
            "'StatelessApplication'.",
        );
        self::assertStringContainsString(
            'user_preference=',
            $deletionHeader,
            "Cookie deletion header should contain cookie name 'user_preference' for 'site/deletecookie' route in " .
            "'StatelessApplication'.",
        );
        self::assertStringContainsString(
            'Path=/app',
            $deletionHeader,
            "Cookie deletion header should preserve 'Path=/app' attribute for 'user_preference' cookie in " .
            "'StatelessApplication'.",
        );
        self::assertStringContainsString(
            'HttpOnly',
            $deletionHeader,
            "Cookie deletion header should preserve 'HttpOnly' attribute for 'user_preference' cookie in " .
            "'StatelessApplication'.",
        );
        self::assertStringContainsString(
            'Secure',
            $deletionHeader,
            "Cookie deletion header should preserve 'Secure' attribute for 'user_preference' cookie in " .
            "'StatelessApplication'.",
        );
        self::assertStringContainsString(
            'Expires=',
            $deletionHeader,
            "Cookie deletion header should contain 'Expires' attribute with past date for 'user_preference' cookie " .
            "in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnSetCookieHeadersForMultipleCookieTypesIncludingDeletion(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/multiplecookies',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/multiplecookies' route in 'StatelessApplication'.",
        );

        // filter out session cookies to focus on test cookies
        $testCookieHeaders = array_filter(
            $response->getHeader('Set-Cookie'),
            static fn(string $header): bool => str_starts_with($header, $app->session->getName()) === false,
        );

        self::assertCount(
            2,
            $testCookieHeaders,
            "Response should contain exactly '2' non-session 'Set-Cookie' headers for 'site/multiplecookies' route " .
            "in 'StatelessApplication'.",
        );

        $headerString = implode('|', $testCookieHeaders);

        self::assertStringContainsString(
            'theme=dark',
            $headerString,
            "Response 'Set-Cookie' headers should contain 'theme=dark' for 'site/multiplecookies' route in " .
            "'StatelessApplication'.",
        );
        self::assertStringContainsString(
            'old_session=',
            $headerString,
            "Response 'Set-Cookie' headers should contain 'old_session=' for cookie deletion in " .
            "'site/multiplecookies' route in 'StatelessApplication'.",
        );
        self::assertStringNotContainsString(
            'temp_data=',
            $headerString,
            "Response 'Set-Cookie' headers should NOT contain 'temp_data=' for deleted cookie in " .
            "'site/multiplecookies' route in 'StatelessApplication'.",
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
    public function testReturnUsernameOnlyWhenNoColonSeparatorInCredentials(): void
    {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('usernameonly'),
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/auth',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/auth' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/auth' route in " .
            "'StatelessApplication'.",
        );

        $responseData = Json::decode($response->getBody()->getContents());

        self::assertIsArray(
            $responseData,
            "Response 'body' should be an array after decoding JSON response from 'site/auth' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            'usernameonly',
            $responseData['username'] ?? '',
            "Should return 'username' when no colon separator is present in credentials.",
        );
        self::assertNull(
            $responseData['password'] ?? null,
            "Should return 'null' 'password' when no colon separator is present in credentials.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnValidatedCookiesWhenValidationEnabledWithValidCookies(): void
    {
        $app = $this->statelessApplication(
            [
                'components' => [
                    'request' => [
                        'cookieValidationKey' => self::COOKIE_VALIDATION_KEY,
                        'enableCookieValidation' => true,
                    ],
                ],
            ],
        );

        $response = $app->handle(
            FactoryHelper::createRequest('GET', 'site/getcookies')
                ->withCookieParams(
                    [
                        'invalid_cookie' => 'invalid_data',
                        'valid_session' => $this->signCookie('valid_session', 'abc123session'),
                    ],
                ),
        );

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/getcookies' route in 'StatelessApplication'.",
        );
        self::assertSame(
            <<<JSON
            {"valid_session":{"name":"valid_session","value":"abc123session","domain":"","expire":null,"path":"/","secure":false,"httpOnly":true,"sameSite":"Lax"}}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should match expected JSON string for cookie 'valid_session' on 'site/getcookies' " .
            "route in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnValidatedCookieWithCorrectNamePropertyWhenValidationEnabled(): void
    {
        $app = $this->statelessApplication(
            [
                'components' => [
                    'request' => [
                        'cookieValidationKey' => self::COOKIE_VALIDATION_KEY,
                        'enableCookieValidation' => true,
                    ],
                ],
            ],
        );

        $response = $app->handle(
            FactoryHelper::createRequest('GET', 'site/getcookies')
                ->withCookieParams(
                    [
                        'validated_session' => $this->signCookie('validated_session', 'secure_session_value')],
                ),
        );

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/getcookies' route in 'StatelessApplication'.",
        );
        self::assertSame(
            <<<JSON
            {"validated_session":{"name":"validated_session","value":"secure_session_value","domain":"","expire":null,"path":"/","secure":false,"httpOnly":true,"sameSite":"Lax"}}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should match expected JSON string for cookie 'validated_session' on 'site/getcookies' " .
            "route in 'StatelessApplication'.",
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
    #[DataProviderExternal(StatelessApplicationProvider::class, 'eventDataProvider')]
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
                    'errorHandler' => [
                        'errorAction' => 'site/error',
                    ],
                    'response' => [
                        'format' => Response::FORMAT_JSON,
                    ],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());
        $responseBody = $response->getBody()->getContents();

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Response 'status code' should be '500' when a 'Exception' occurs with JSON format in " .
            "'StatelessApplication', indicating an 'internal server error'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for error response when 'Exception'" .
            "occurs with JSON format in 'StatelessApplication'.",
        );
        self::assertStringNotContainsString(
            'Custom error page from errorAction.',
            $responseBody,
            "Response 'body' should NOT contain 'Custom error page from errorAction' when format is JSON " .
            "because useErrorView should be false regardless of YII_DEBUG or exception type in 'StatelessApplication'.",
        );

        $decodedResponse = Json::decode($responseBody);

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
