<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use PHPUnit\Framework\Attributes\Group;
use yii\web\{Cookie, Response};
use yii2\extensions\psrbridge\adapter\ResponseAdapter;
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

        $headerValues = implode(' ', $setCookieHeaders);

        self::assertStringContainsString(
            'cookie1=value1',
            $headerValues,
            "First 'Set-Cookie' header must contain the correct 'name' and 'value' for 'cookie1'.",
        );
        self::assertStringContainsString(
            'cookie2=value2',
            $headerValues,
            "Second 'Set-Cookie' header must contain the correct 'name' and 'value' for 'cookie2'.",
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
            'valid=value',
            $setCookieHeaders[0] ?? '',
            "'Set-Cookie' header should contain the valid cookie with a non-empty value.",
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
            urlencode('full_cookie') . '=' . urlencode('full_value'),
            $cookieHeader,
            "'Set-Cookie' header should contain the properly encoded cookie 'name' and 'value'.",
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
            urlencode('test_cookie') . '=' . urlencode('test_value'),
            $cookieHeader,
            "'Set-Cookie' header should start with the encoded cookie 'name' and 'value'.",
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
            'test=value',
            $setCookieHeaders[0] ?? '',
            "'Set-Cookie' header should contain the cookie name and value ('test=value').",
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

        self::assertSame(
            urlencode('minimal_cookie') . '=' . urlencode('minimal_value'),
            $cookieHeader,
            "'Set-Cookie' header should only contain the encoded cookie 'name' and 'value' when all optional " .
            "attributes are empty or set to 'false'.",
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
            urlencode('session_cookie') . '=' . urlencode('session_value'),
            $cookieHeader,
            "'Set-Cookie' header should contain the encoded cookie 'name' and 'value'.",
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
            "Exactly one 'Set-Cookie' header should be present when a future-expiring cookie is added with validation " .
            'disabled.',
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
            "Exactly one 'Set-Cookie' header present in the response when a cookie is added with validation disabled " .
            'and expired.',
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertSame(
            urlencode('special cookie!') . '=' . urlencode('special value@#$%') . '; Path=/; HttpOnly; SameSite=Lax',
            $cookieHeader,
            "Cookie header should properly URL-encode special characters in cookie 'name' and 'value' with default " .
            'attributes.',
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
            "'Set-Cookie' header should not contain the plain cookie 'value' when validation is enabled for an " .
            'expired cookie.',
        );
        self::assertStringContainsString(
            '; Expires=' . gmdate('D, d-M-Y H:i:s T', $pastTime),
            $cookieHeader,
            "'Set-Cookie' header should contain the correct 'Expires' attribute for the expired cookie.",
        );
    }

    public function testCookieHeaderWithValidationEnabledButNullKey(): void
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
            "Exactly one 'Set-Cookie' header present in the response when an expired cookie is added with validation " .
            'enabled.',
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertSame(
            urlencode('test_cookie') . '=' . urlencode('test_value') . '; Path=/; HttpOnly; SameSite=Lax',
            $cookieHeader,
            'Cookie header should contain original value with default attributes when validation is enabled but ' .
            "validation key is 'null'.",
        );
    }
}
