<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group, RequiresPhpExtension};
use yii\base\{Exception, InvalidConfigException};
use yii\helpers\Json;
use yii\log\{FileTarget, Logger};
use yii2\extensions\psrbridge\http\Response;
use yii2\extensions\psrbridge\tests\provider\StatelessApplicationProvider;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function is_array;
use function str_contains;

#[Group('http')]
final class ApplicationErrorHandlerTest extends TestCase
{
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

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $route,
        ];

        $app = $this->statelessApplication(
            [
                'components' => [
                    'errorHandler' => ['errorAction' => $action],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

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
            "Response 'status code' should be '500' when an exception occurs.",
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
            "Logger should contain an error log entry with category '{$expectedCategory}' and message " .
            "'Exception error message.' when 'logException()' is called during exception handling.",
        );
        self::assertFalse(
            $app->flushLogger,
            "Test must keep logger messages in memory to assert on them; 'flushLogger' should be 'false'.",
        );
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
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $route,
        ];

        $app = $this->statelessApplication(
            [
                'components' => [
                    'response' => ['format' => $format],
                    'errorHandler' => ['errorAction' => null],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

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
}
