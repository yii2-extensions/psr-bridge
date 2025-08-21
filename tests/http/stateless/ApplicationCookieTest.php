<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use stdClass;
use yii\base\InvalidConfigException;
use yii\helpers\Json;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function array_filter;
use function implode;
use function str_starts_with;

final class ApplicationCookieTest extends TestCase
{
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
            "Response 'status code' should be '200' for 'site/getcookies' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/getcookies' route in " .
            "'StatelessApplication'.",
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
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/getcookies' route in " .
            "'StatelessApplication'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"test":{"name":"test","value":"test","domain":"","expire":null,"path":"/","secure":false,"httpOnly":true,"sameSite":"Lax"}}
            JSON,
            $response->getBody()->getContents(),
            "Response body should contain the 'test' cookie with its properties.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnMultipleValidatedCookiesWhenValidationEnabledWithMultipleValidCookies(): void
    {
        $cookies = [
            'language' => 'en_US_012',
            'session_id' => 'session_value_123',
            'theme' => 'dark_theme_789',
            'user_pref' => 'preference_value_456',
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
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/getcookies' route in " .
            "'StatelessApplication'.",
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
            "Response 'status code' should be '200' for 'site/getcookies' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/getcookies' route in " .
            "'StatelessApplication'.",
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
            "Response 'status code' should be '200' for 'site/getcookies' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/getcookies' route in " .
            "'StatelessApplication'.",
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
            "Response 'status code' should be '200' for 'site/getcookies' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/getcookies' route in " .
            "'StatelessApplication'.",
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
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/getcookies' route in " .
            "'StatelessApplication'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"valid_session":{"name":"valid_session","value":"abc123session","domain":"","expire":null,"path":"/","secure":false,"httpOnly":true,"sameSite":"Lax"}}
            JSON,
            $response->getBody()->getContents(),
            "Response body should contain the 'valid_session' cookie with its properties.",
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
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/getcookies' route in " .
            "'StatelessApplication'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"validated_session":{"name":"validated_session","value":"secure_session_value","domain":"","expire":null,"path":"/","secure":false,"httpOnly":true,"sameSite":"Lax"}}
            JSON,
            $response->getBody()->getContents(),
            "Response body should contain the 'validated_session' cookie with its properties.",
        );
    }
}
