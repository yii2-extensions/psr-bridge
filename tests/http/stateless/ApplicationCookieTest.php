<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use stdClass;
use yii\base\InvalidConfigException;
use yii\helpers\Json;
use yii2\extensions\psrbridge\tests\provider\StatelessApplicationProvider;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function array_filter;
use function array_keys;
use function implode;
use function str_starts_with;

#[Group('http')]
final class ApplicationCookieTest extends TestCase
{
    /**
     * @phpstan-param array<string, string> $cookieParams
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[DataProviderExternal(StatelessApplicationProvider::class, 'cookies')]
    public function testReturnCookiesForValidationAndSignature(
        bool $enableCookieValidation,
        bool $signedCookies,
        array $cookieParams,
        string $expectedJson,
        string $expectedAssertMessage,
    ): void {
        $signedCookieParams = [];

        if ($signedCookies) {
            foreach ($cookieParams as $name => $value) {
                $signedCookieParams[$name] = $this->signCookie($name, $value);
            }
        }

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
            FactoryHelper::createRequest('GET', 'site/getcookies')
                ->withCookieParams($signedCookieParams !== [] ? $signedCookieParams : $cookieParams),
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
            $expectedJson,
            $response->getBody()->getContents(),
            $expectedAssertMessage,
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
        self::assertEqualsCanonicalizing(
            ['__PHP_Incomplete_Class_Name', 'property'],
            array_keys($objectValue),
            'Sanitized object should not contain unexpected keys.',
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
            static fn(string $header): bool => str_starts_with($header, $app->session->getName() . '=') === false,
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
}
