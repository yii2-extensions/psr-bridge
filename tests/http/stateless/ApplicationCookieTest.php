<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group};
use yii\base\InvalidConfigException;
use yii\helpers\Json;
use yii2\extensions\psrbridge\tests\provider\StatelessApplicationProvider;
use yii2\extensions\psrbridge\tests\support\{FactoryHelper, TestCase};

use function array_filter;
use function implode;
use function str_starts_with;

/**
 * Unit tests for {@see \yii2\extensions\psrbridge\http\Application} cookie handling in stateless mode.
 *
 * Test coverage.
 * - Ensures cookie validation and signature handling return expected JSON payloads.
 * - Verifies deletion responses emit expected Set-Cookie headers.
 * - Verifies multiple cookie responses include created cookies and omit deleted cookies.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('http')]
final class ApplicationCookieTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @phpstan-param array<string, string|object> $cookieParams
     */
    #[DataProviderExternal(StatelessApplicationProvider::class, 'cookies')]
    public function testReturnCookiesForValidationAndSignature(
        bool $enableCookieValidation,
        bool $signedCookies,
        array $cookieParams,
        string $expectedJson,
        string $expectedAssertMessage,
    ): void {
        $cookies = $signedCookies ? $this->signCookies($cookieParams) : $cookieParams;

        $app = $this->statelessApplication(
            [
                'components' => [
                    'request' => [
                        'cookieValidationKey' => self::COOKIE_VALIDATION_KEY,
                        'enableCookieValidation' => $enableCookieValidation,
                    ],
                ],
            ],
        );

        $response = $app->handle(
            FactoryHelper::createRequest('GET', 'site/getcookies')->withCookieParams($cookies),
        );

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/getcookies'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/getcookies'.",
        );
        self::assertJsonStringEqualsJsonString(
            $expectedJson,
            $response->getBody()->getContents(),
            $expectedAssertMessage,
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
            "Expected HTTP '200' for route 'site/deletecookie'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/deletecookie'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            ["user_preference=; Expires=Fri, 22-Aug-2025 13:03:16 GMT; Max-Age=0; Path=/app; Secure; HttpOnly; SameSite=Lax"]
            JSON,
            Json::encode($response->getHeader('Set-Cookie')),
            "Response Set-Cookie headers should contain the deletion header for 'user_preference' cookie.",
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
            "Expected HTTP '200' for route 'site/multiplecookies'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/multiplecookies'.",
        );

        // filter out session cookies to focus on test cookies
        $testCookieHeaders = array_filter(
            $response->getHeader('Set-Cookie'),
            static fn(string $header): bool => str_starts_with($header, $app->session->getName() . '=') === false,
        );

        self::assertCount(
            2,
            $testCookieHeaders,
            "Response should contain exactly '2' non-session Set-Cookie headers.",
        );

        $headerString = implode('|', $testCookieHeaders);

        self::assertStringContainsString(
            'theme=dark',
            $headerString,
            "Response Set-Cookie headers should contain 'theme=dark' for regular cookie.",
        );
        self::assertStringContainsString(
            'old_session=',
            $headerString,
            "Response Set-Cookie headers should contain 'old_session=' for regular cookie.",
        );
        self::assertStringNotContainsString(
            'temp_data=',
            $headerString,
            "Response Set-Cookie headers should NOT contain 'temp_data=' for deleted cookie.",
        );
    }
}
