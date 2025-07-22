<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use PHPUnit\Framework\Attributes\Group;
use yii\base\InvalidConfigException;
use yii\web\{Cookie, Response};
use yii2\extensions\psrbridge\adapter\ResponseAdapter;
use yii2\extensions\psrbridge\exception\Message;
use yii2\extensions\psrbridge\http\Request;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function gmdate;
use function max;
use function time;
use function urlencode;

#[Group('http')]
final class PSR7ResponseTest extends TestCase
{
    public function testAddMultipleCookiesProducesMultipleSetCookieHeaders(): void
    {
        $this->mockWebApplication();

        $response = new Response();
        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();
        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie1 = new Cookie(
            [
                'name' => 'cookie1',
                'value' => 'value1',
            ],
        );
        $cookie2 = new Cookie(
            [
                'name' => 'cookie2',
                'value' => 'value2',
            ],
        );

        $response->cookies->add($cookie1);
        $response->cookies->add($cookie2);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            2,
            $setCookieHeaders,
            "PSR-7 response must contain exactly two 'Set-Cookie' headers when two cookies are added.",
        );

        // With validation enabled by default, cookies should be hashed
        $headerValues = implode(' ', $setCookieHeaders);

        self::assertStringContainsString(
            'cookie1=',
            $headerValues,
            "First 'Set-Cookie' header must contain cookie1.",
        );
        self::assertStringContainsString(
            'cookie2=',
            $headerValues,
            "Second 'Set-Cookie' header must contain cookie2.",
        );
        // Should NOT contain plain values when validation is enabled
        self::assertStringNotContainsString(
            'cookie1=value1',
            $headerValues,
            'Cookies should be hashed when validation is enabled.',
        );
        self::assertStringNotContainsString(
            'cookie2=value2',
            $headerValues,
            'Cookies should be hashed when validation is enabled.',
        );
    }

    public function testCookieHeaderSkipEmptyValueCookies(): void
    {
        $this->mockWebApplication();

        $response = new Response();
        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();
        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $validCookie = new Cookie(
            [
                'name' => 'valid',
                'value' => 'value',
            ],
        );
        $emptyCookie = new Cookie(
            [
                'name' => 'empty',
                'value' => '',
            ],
        );
        $nullCookie = new Cookie(
            [
                'name' => 'null',
                'value' => null,
            ],
        );

        $response->cookies->add($validCookie);
        $response->cookies->add($emptyCookie);
        $response->cookies->add($nullCookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "PSR-7 response should include only cookies with non-empty values in the 'Set-Cookie' header.",
        );
        self::assertStringContainsString(
            'valid=',
            $setCookieHeaders[0] ?? '',
            "'Set-Cookie' header should contain the valid cookie.",
        );
        // Should be hashed, not plain value
        self::assertStringNotContainsString(
            'valid=value',
            $setCookieHeaders[0] ?? '',
            "'Set-Cookie' header should contain hashed value when validation is enabled.",
        );
    }

    public function testCookieHeaderWithAllAttributes(): void
    {
        $this->mockWebApplication();

        $futureTime = time() + 3600;

        $response = new Response();
        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();
        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie(
            [
                'name' => 'full_cookie',
                'value' => 'full_value',
                'expire' => $futureTime,
                'path' => '/test/path',
                'domain' => 'example.com',
                'secure' => true,
                'httpOnly' => true,
                'sameSite' => 'Strict',
            ],
        );

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header present in the response when a single cookie with all attributes is " .
            'added.',
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertStringContainsString(
            urlencode('full_cookie') . '=',
            $cookieHeader,
            "'Set-Cookie' header should contain the encoded cookie 'name'.",
        );
        // Should be hashed, not plain value
        self::assertStringNotContainsString(
            urlencode('full_cookie') . '=' . urlencode('full_value'),
            $cookieHeader,
            "'Set-Cookie' header should not contain plain value when validation is enabled.",
        );
        self::assertStringContainsString(
            '; Expires=' . gmdate('D, d-M-Y H:i:s T', $futureTime),
            $cookieHeader,
            "'Set-Cookie' header should contain the correctly formatted expiration date.",
        );
        self::assertStringContainsString(
            '; Max-Age=',
            $cookieHeader,
            "'Set-Cookie' header should contain the 'Max-Age' attribute when an expiration is set.",
        );
        self::assertStringContainsString(
            '; Path=/test/path',
            $cookieHeader,
            "'Set-Cookie' header should contain the specified 'Path' attribute.",
        );
        self::assertStringContainsString(
            '; Domain=example.com',
            $cookieHeader,
            "'Set-Cookie' header should contain the specified 'Domain' attribute.",
        );
        self::assertStringContainsString(
            '; Secure',
            $cookieHeader,
            "'Set-Cookie' header should contain the 'Secure' flag when set to 'true'.",
        );
        self::assertStringContainsString(
            '; HttpOnly',
            $cookieHeader,
            "'Set-Cookie' header should contain the 'HttpOnly' flag when set to 'true'.",
        );
        self::assertStringContainsString(
            '; SameSite=Strict',
            $cookieHeader,
            "'Set-Cookie' header should contain the specified 'SameSite=Strict' attribute.",
        );
    }

    public function testCookieHeaderWithBasicCookie(): void
    {
        $this->mockWebApplication();

        $response = new Response();
        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();
        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie(
            [
                'name' => 'test_cookie',
                'value' => 'test_value',
            ],
        );

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header present in the response when a single cookie is added.",
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertStringStartsWith(
            urlencode('test_cookie') . '=',
            $cookieHeader,
            "'Set-Cookie' header should start with the encoded cookie 'name'.",
        );
        // Should be hashed, not plain value
        self::assertStringStartsNotWith(
            urlencode('test_cookie') . '=' . urlencode('test_value'),
            $cookieHeader,
            "'Set-Cookie' header should not start with plain value when validation is enabled.",
        );
        self::assertStringContainsString(
            '; Path=/',
            $cookieHeader,
            "'Set-Cookie' header should include the default 'path' attribute ('; Path=/').",
        );
        self::assertStringContainsString(
            '; HttpOnly',
            $cookieHeader,
            "'Set-Cookie' header should include the 'HttpOnly' flag by default.",
        );
        self::assertStringContainsString(
            '; SameSite=Lax',
            $cookieHeader,
            "'Set-Cookie' header should include the default 'SameSite=Lax' attribute.",
        );
    }

    public function testCookieHeaderWithCustomStatusHeaderAndCookie(): void
    {
        $this->mockWebApplication();

        $response = new Response();

        $response->setStatusCode(201, 'Created');

        $response->content = 'Test response body';

        $response->headers->add('X-Custom-Header', 'Custom Value');

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();
        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie(
            [
                'name' => 'test',
                'value' => 'value',
            ],
        );

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();

        self::assertSame(
            201,
            $psr7Response->getStatusCode(),
            "PSR-7 response should have status code '201' when set explicitly on the Yii2 response.",
        );
        self::assertSame(
            'Created',
            $psr7Response->getReasonPhrase(),
            "PSR-7 response should have reason phrase 'Created' when set explicitly on the Yii2 response.",
        );
        self::assertSame(
            'Test response body',
            (string) $psr7Response->getBody(),
            'PSR-7 response body should match the Yii2 response content.',
        );
        self::assertSame(
            ['Custom Value'],
            $psr7Response->getHeader('X-Custom-Header'),
            "PSR-7 response should include the custom 'X-Custom-Header' with the correct value.",
        );

        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header should be present when a single cookie is added.",
        );
        self::assertStringContainsString(
            'test=',
            $setCookieHeaders[0] ?? '',
            "'Set-Cookie' header should contain the cookie name.",
        );
        // Should be hashed, not plain value
        self::assertStringNotContainsString(
            'test=value',
            $setCookieHeaders[0] ?? '',
            "'Set-Cookie' header should not contain plain value when validation is enabled.",
        );
    }

    public function testCookieHeaderWithEmptyOptionalAttributes(): void
    {
        $this->mockWebApplication();

        $response = new Response();
        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();
        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie(
            [
                'name' => 'minimal_cookie',
                'value' => 'minimal_value',
                'path' => '',
                'domain' => '',
                'secure' => false,
                'httpOnly' => false,
                'sameSite' => null,
            ],
        );

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header present in the response when a minimal cookie is added.",
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        // Cookie should be hashed when validation is enabled
        self::assertStringStartsWith(
            urlencode('minimal_cookie') . '=',
            $cookieHeader,
            "'Set-Cookie' header should start with the encoded cookie 'name'.",
        );
        self::assertStringStartsNotWith(
            urlencode('minimal_cookie') . '=' . urlencode('minimal_value'),
            $cookieHeader,
            "'Set-Cookie' header should not contain plain value when validation is enabled.",
        );
        self::assertStringNotContainsString(
            '; Path=',
            $cookieHeader,
            "'Set-Cookie' header should not contain the 'Path' attribute when it is empty.",
        );
        self::assertStringNotContainsString(
            '; Domain=',
            $cookieHeader,
            "'Set-Cookie' header should not contain the 'Domain' attribute when it is empty.",
        );
        self::assertStringNotContainsString(
            '; Secure',
            $cookieHeader,
            "'Set-Cookie' header should not contain the 'Secure' flag when it is set to 'false'.",
        );
        self::assertStringNotContainsString(
            '; HttpOnly',
            $cookieHeader,
            "'Set-Cookie' header should not contain the 'HttpOnly' flag when it is set to 'false'.",
        );
        self::assertStringNotContainsString(
            '; SameSite=',
            $cookieHeader,
            "'Set-Cookie' header should not contain the 'SameSite' attribute when it is 'null'.",
        );
    }

    public function testCookieHeaderWithEmptyValidationKey(): void
    {
        $this->mockWebApplication(
            [
                'components' => [
                    'request' => [
                        'class' => Request::class,
                        'enableCookieValidation' => true,
                        'cookieValidationKey' => '',
                    ],
                ],
            ],
        );

        $response = new Response();
        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();
        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie(
            [
                'name' => 'test_cookie',
                'value' => 'test_value',
            ],
        );

        $response->cookies->add($cookie);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(Message::COOKIE_VALIDATION_KEY_NOT_CONFIGURED->getMessage(Request::class));

        $adapter->toPsr7();
    }

    public function testCookieHeaderWithExpirationZero(): void
    {
        $this->mockWebApplication();

        $response = new Response();
        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();
        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);

        $cookie = new Cookie(
            [
                'name' => 'session_cookie',
                'value' => 'session_value',
                'expire' => 0,
            ],
        );

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header present in the response when a cookie with 'expire=0' is added.",
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertStringContainsString(
            urlencode('session_cookie') . '=',
            $cookieHeader,
            "'Set-Cookie' header should contain the encoded cookie 'name'.",
        );
        // Should be hashed with expire=0
        self::assertStringNotContainsString(
            urlencode('session_cookie') . '=' . urlencode('session_value'),
            $cookieHeader,
            "'Set-Cookie' header should not contain plain value when validation is enabled.",
        );
        self::assertStringNotContainsString(
            '; Expires=',
            $cookieHeader,
            "'Set-Cookie' header should not contain the 'Expires' attribute when expire is '0'.",
        );
        self::assertStringNotContainsString(
            '; Max-Age=',
            $cookieHeader,
            "The 'Set-Cookie' header should not contain the 'Max-Age' attribute when expire is '0'.",
        );
        self::assertStringContainsString(
            '; Path=/',
            $cookieHeader,
            "'Set-Cookie' header should include the default 'Path' attribute ('; Path=/').",
        );
        self::assertStringContainsString(
            '; HttpOnly',
            $cookieHeader,
            "'Set-Cookie' header should include the 'HttpOnly' flag by default.",
        );
        self::assertStringContainsString(
            '; SameSite=Lax',
            $cookieHeader,
            "'Set-Cookie' header should include the default 'SameSite=Lax' attribute.",
        );
    }

    public function testCookieHeaderWithExpireSetToOne(): void
    {
        $this->mockWebApplication(
            [
                'components' => [
                    'request' => [
                        'class' => Request::class,
                        'enableCookieValidation' => true,
                        'cookieValidationKey' => 'test-validation-key-32-characters',
                    ],
                ],
            ],
        );

        $response = new Response();
        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();
        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie(
            [
                'name' => 'special_cookie',
                'value' => 'special_value',
                'expire' => 1, // Special case in Yii2 - no validation
            ],
        );

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header present in the response when a cookie with expire=1 is added.",
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertStringStartsWith(
            urlencode('special_cookie') . '=' . urlencode('special_value'),
            $cookieHeader,
            "'Set-Cookie' header should contain the plain value when expire=1 (special case - no validation).",
        );
        self::assertStringContainsString(
            '; Expires=' . gmdate('D, d-M-Y H:i:s T', 1),
            $cookieHeader,
            "'Set-Cookie' header should contain expiration date for timestamp 1.",
        );
    }

    public function testCookieHeaderWithExpireSetToOneAsString(): void
    {
        $this->mockWebApplication(
            [
                'components' => [
                    'request' => [
                        'class' => Request::class,
                        'enableCookieValidation' => true,
                        'cookieValidationKey' => 'test-validation-key-32-characters',
                    ],
                ],
            ],
        );

        $response = new Response();
        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();
        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie(
            [
                'name' => 'string_expire_cookie',
                'value' => 'string_expire_value',
                'expire' => '1', // String '1' should also bypass validation due to != comparison
            ],
        );

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header present in the response when a cookie with expire='1' (string) is added.",
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertStringStartsWith(
            urlencode('string_expire_cookie') . '=' . urlencode('string_expire_value'),
            $cookieHeader,
            "'Set-Cookie' header should contain the plain value when expire='1' (string - special case due to != comparison).",
        );
        self::assertStringContainsString(
            '; Expires=' . gmdate('D, d-M-Y H:i:s T', 1),
            $cookieHeader,
            "'Set-Cookie' header should contain expiration date for timestamp 1.",
        );
    }

    public function testCookieHeaderWithMaxAgeCalculation(): void
    {
        $this->mockWebApplication();

        $futureTime = time() + 7200; // 2 hours from now

        $response = new Response();
        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();
        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie(
            [
                'name' => 'future_cookie',
                'value' => 'future_value',
                'expire' => $futureTime,
            ],
        );

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header should be present when a future-expiring cookie is added.",
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertStringContainsString(
            '; Max-Age=',
            $cookieHeader,
            "'Set-Cookie' header must include the 'Max-Age' attribute when the cookie has a future expiration.",
        );
        self::assertStringContainsString(
            '; Max-Age=' . max(0, $futureTime - time()),
            $cookieHeader,
            "'Set-Cookie' header must include the correctly calculated 'Max-Age' value for the future expiration.",
        );
    }

    public function testCookieHeaderWithSpecialCharactersInNameAndValue(): void
    {
        $this->mockWebApplication();

        $response = new Response();
        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();
        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie(
            [
                'name' => 'special cookie!',
                'value' => 'special value@#$%',
            ],
        );

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header present in the response when a cookie with special characters is added.",
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertStringStartsWith(
            urlencode('special cookie!') . '=',
            $cookieHeader,
            "Cookie header should properly URL-encode special characters in cookie 'name'.",
        );
        // Should be hashed when validation is enabled
        self::assertStringStartsNotWith(
            urlencode('special cookie!') . '=' . urlencode('special value@#$%'),
            $cookieHeader,
            'Cookie header should not contain plain value when validation is enabled.',
        );
        self::assertStringContainsString(
            '; Path=/',
            $cookieHeader,
            "'Set-Cookie' header should include the default 'Path' attribute.",
        );
        self::assertStringContainsString(
            '; HttpOnly',
            $cookieHeader,
            "'Set-Cookie' header should include the 'HttpOnly' flag by default.",
        );
        self::assertStringContainsString(
            '; SameSite=Lax',
            $cookieHeader,
            "'Set-Cookie' header should include the default 'SameSite=Lax' attribute.",
        );
    }

    public function testCookieHeaderWithValidationDisabled(): void
    {
        $this->mockWebApplication([
            'components' => [
                'request' => [
                    'class' => Request::class,
                    'enableCookieValidation' => false,
                    'cookieValidationKey' => 'some-key',
                ],
            ],
        ]);

        $pastTime = time() - 3600; // 1 hour ago (expired cookie)

        $response = new Response();
        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();
        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie(
            [
                'name' => 'past_cookie',
                'value' => 'past_value',
                'expire' => $pastTime,
            ],
        );

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header present in the response when a cookie is added with validation disabled " .
            'and expired.',
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertStringContainsString(
            urlencode('past_cookie') . '=' . urlencode('past_value'),
            $cookieHeader,
            "'Set-Cookie' header should contain the original cookie value when validation is disabled, even if the " .
            'cookie is expired.',
        );
        self::assertStringContainsString(
            '; Expires=' . gmdate('D, d-M-Y H:i:s T', $pastTime),
            $cookieHeader,
            "'Set-Cookie' header should contain the correctly formatted expiration date for the expired cookie.",
        );
    }

    public function testCookieHeaderWithValidationDisabledAndNoKey(): void
    {
        $this->mockWebApplication([
            'components' => [
                'request' => [
                    'class' => Request::class,
                    'enableCookieValidation' => false,
                ],
            ],
        ]);

        $response = new Response();
        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();
        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie(
            [
                'name' => 'test_cookie',
                'value' => 'test_value',
            ],
        );

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header present in the response.",
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertSame(
            urlencode('test_cookie') . '=' . urlencode('test_value') . '; Path=/; HttpOnly; SameSite=Lax',
            $cookieHeader,
            'Cookie header should contain plain value when validation is disabled.',
        );
    }

    public function testCookieHeaderWithValidationEnabledAndValidKey(): void
    {
        $this->mockWebApplication(
            [
                'components' => [
                    'request' => [
                        'class' => Request::class,
                        'enableCookieValidation' => true,
                        'cookieValidationKey' => 'test-validation-key-32-characters',
                    ],
                ],
            ],
        );

        $pastTime = time() - 3600; // 1 hour ago (expired cookie)

        $response = new Response();
        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();
        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie(
            [
                'name' => 'expired_cookie',
                'value' => 'expired_value',
                'expire' => $pastTime,
            ],
        );

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header present in the response when an expired cookie is added with validation " .
            'enabled.',
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertStringStartsWith(
            urlencode('expired_cookie') . '=',
            $cookieHeader,
            "'Set-Cookie' header should start with the encoded cookie 'name' when validation is enabled.",
        );
        self::assertStringStartsNotWith(
            urlencode('expired_cookie') . '=' . urlencode('expired_value'),
            $cookieHeader,
            "'Set-Cookie' header should not contain the plain cookie 'value' when validation is enabled.",
        );
        self::assertStringContainsString(
            '; Expires=' . gmdate('D, d-M-Y H:i:s T', $pastTime),
            $cookieHeader,
            "'Set-Cookie' header should contain the correct 'Expires' attribute for the expired cookie.",
        );
    }

    public function testCookieHeaderWithValidationEnabledByDefault(): void
    {
        $this->mockWebApplication();

        $response = new Response();
        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();
        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie(
            [
                'name' => 'test_cookie',
                'value' => 'test_value',
            ],
        );

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header present in the response.",
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        // With validation enabled by default and a non-empty key, cookies should be hashed
        self::assertStringStartsWith(
            urlencode('test_cookie') . '=',
            $cookieHeader,
            "'Set-Cookie' header should start with the encoded cookie 'name'.",
        );
        self::assertStringStartsNotWith(
            urlencode('test_cookie') . '=' . urlencode('test_value'),
            $cookieHeader,
            'Cookie header should not contain plain value when validation is enabled with a valid key.',
        );
    }

    public function testCookieHeaderWithValidationEnabledForNormalCookie(): void
    {
        $this->mockWebApplication(
            [
                'components' => [
                    'request' => [
                        'class' => Request::class,
                        'enableCookieValidation' => true,
                        'cookieValidationKey' => 'test-validation-key-32-characters',
                    ],
                ],
            ],
        );

        $futureTime = time() + 3600; // 1 hour in the future

        $response = new Response();
        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();
        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie(
            [
                'name' => 'valid_cookie',
                'value' => 'valid_value',
                'expire' => $futureTime,
            ],
        );

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header present in the response when a valid cookie is added with validation " .
            'enabled.',
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertStringStartsWith(
            urlencode('valid_cookie') . '=',
            $cookieHeader,
            "'Set-Cookie' header should start with the encoded cookie 'name' when validation is enabled.",
        );
        self::assertStringStartsNotWith(
            urlencode('valid_cookie') . '=' . urlencode('valid_value'),
            $cookieHeader,
            "'Set-Cookie' header should not contain the plain cookie 'value' when validation is enabled for a " .
            'valid (non-expired) cookie.',
        );
        self::assertStringContainsString(
            '; Expires=' . gmdate('D, d-M-Y H:i:s T', $futureTime),
            $cookieHeader,
            "'Set-Cookie' header should contain the correct 'Expires' attribute for the future cookie.",
        );
    }
}
