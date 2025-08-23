<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group};
use yii\base\InvalidConfigException;
use yii\helpers\Json;
use yii2\extensions\psrbridge\http\Response;
use yii2\extensions\psrbridge\tests\provider\StatelessApplicationProvider;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

#[Group('http')]
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
