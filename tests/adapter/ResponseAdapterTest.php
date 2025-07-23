<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\adapter;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use yii\base\InvalidConfigException;
use yii\web\{Cookie, Response};
use yii2\extensions\psrbridge\adapter\ResponseAdapter;
use yii2\extensions\psrbridge\exception\Message;
use yii2\extensions\psrbridge\http\Request;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function dirname;
use function fclose;
use function fopen;
use function fwrite;
use function gmdate;
use function max;
use function preg_match;
use function str_repeat;
use function strlen;
use function substr;
use function tempnam;
use function time;
use function unlink;
use function urlencode;

#[Group('http')]
final class ResponseAdapterTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testConvertResponseWithContentFallsBackToRegularStream(): void
    {
        $this->mockWebApplication();

        $response = new Response();

        $response->content = null; // explicitly no content

        $response->setStatusCode(200);

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);

        $psr7Response = $adapter->toPsr7();
        $body = (string) $psr7Response->getBody();

        self::assertEmpty(
            $body,
            'PSR-7 response should fall back to regular content stream when no file stream is configured',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testConvertResponseWithFileStreamFullRange(): void
    {
        $this->mockWebApplication();

        $content = 'This is test content for streaming';

        $tempFile = $this->createTempFileWithContent($content);
        $handle = fopen($tempFile, 'rb');

        self::assertIsResource(
            $handle,
           'File handle should be a valid resource.',
        );

        $response = new Response();

        $response->stream = [$handle, 0, strlen($content) - 1];

        $response->setStatusCode(200);

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);

        $psr7Response = $adapter->toPsr7();

        self::assertSame(
            200,
            $psr7Response->getStatusCode(),
            'PSR-7 response should maintain the original status code',
        );

        $body = (string) $psr7Response->getBody();

        self::assertSame(
            $content,
            $body,
            'PSR-7 response body should contain the complete file content when streaming full range',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testConvertResponseWithFileStreamLargeRange(): void
    {
        $this->mockWebApplication();

        // create content larger than typical buffer sizes
        $content = str_repeat('0123456789ABCDEF', 1000); // 16KB
        $tempFile = $this->createTempFileWithContent($content);
        $handle = fopen($tempFile, 'rb');

        self::assertIsResource(
            $handle,
           'File handle should be a valid resource.',
        );

        $begin = 1000;
        $end = 5000;

        $expectedContent = substr($content, $begin, $end - $begin + 1);

        $response = new Response();

        $response->stream = [$handle, $begin, $end];

        $response->setStatusCode(206, 'Partial Content');

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);

        $psr7Response = $adapter->toPsr7();
        $body = (string) $psr7Response->getBody();

        self::assertSame(
            $expectedContent,
            $body,
            'PSR-7 response body should handle large range requests correctly.',
        );
        self::assertSame(
            $end - $begin + 1,
            strlen($body),
            'PSR-7 response body length should match the large range size.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testConvertResponseWithFileStreamPartialRange(): void
    {
        $this->mockWebApplication();

        $content = 'This is a longer test content for range streaming tests';

        $tempFile = $this->createTempFileWithContent($content);
        $handle = fopen($tempFile, 'rb');

        self::assertIsResource(
            $handle,
            'File handle should be a valid resource.',
        );

        $begin = 5;
        $end = 15;

        $expectedContent = substr($content, $begin, $end - $begin + 1);

        $response = new Response();

        $response->stream = [$handle, $begin, $end];

        $response->setStatusCode(206, 'Partial Content');

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);

        $psr7Response = $adapter->toPsr7();

        self::assertSame(
            206,
            $psr7Response->getStatusCode(),
            'PSR-7 response should maintain the partial content status code.',
        );

        $body = (string) $psr7Response->getBody();

        self::assertSame(
            $expectedContent,
            $body,
            'PSR-7 response body should contain only the requested range content.',
        );
        self::assertSame(
            strlen($expectedContent),
            strlen($body),
            'PSR-7 response body length should match the range size.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testConvertResponseWithFileStreamPreservesHeaders(): void
    {
        $this->mockWebApplication();

        $content = 'Content with headers preserved';

        $tempFile = $this->createTempFileWithContent($content);
        $handle = fopen($tempFile, 'rb');

        self::assertIsResource(
            $handle,
            'File handle should be a valid resource.',
        );

        $response = new Response();

        $response->stream = [$handle, 0, strlen($content) - 1];

        $response->setStatusCode(200);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Disposition', 'attachment; filename="test.txt"');
        $response->headers->set('X-Custom-Header', 'streaming-test');

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);

        $psr7Response = $adapter->toPsr7();

        self::assertSame(
            ['application/octet-stream'],
            $psr7Response->getHeader('Content-Type'),
            "'Content-Type' header should be preserved when streaming files.",
        );
        self::assertSame(
            ['attachment; filename="test.txt"'],
            $psr7Response->getHeader('Content-Disposition'),
            "'Content-Disposition' header should be preserved when streaming files.",
        );
        self::assertSame(
            ['streaming-test'],
            $psr7Response->getHeader('X-Custom-Header'),
            'Custom headers should be preserved when streaming files.',
        );

        $body = (string) $psr7Response->getBody();

        self::assertSame(
            $content,
            $body,
            'File content should be streamed correctly while preserving headers,',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testConvertResponseWithFileStreamSingleByte(): void
    {
        $this->mockWebApplication();

        $content = 'ABCDEFGHIJKLMNOP';

        $tempFile = $this->createTempFileWithContent($content);
        $handle = fopen($tempFile, 'rb');

        self::assertIsResource($handle, 'File handle should be a valid resource.');

        // extract single byte at position 7: "H"
        $begin = 7;
        $end = 7;

        $expectedContent = substr($content, $begin, 1);

        $response = new Response();

        $response->stream = [$handle, $begin, $end];

        $response->setStatusCode(206, 'Partial Content');

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);

        $psr7Response = $adapter->toPsr7();
        $body = (string) $psr7Response->getBody();

        self::assertSame($expectedContent, $body, "PSR-7 response body should contain the single requested 'byte'.");
        self::assertSame(1, strlen($body), "PSR-7 response body should contain exactly one 'byte'.");
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFileStreamTakesPrecedenceOverContent(): void
    {
        $this->mockWebApplication();

        $fileContent = 'File stream content';
        $responseContent = 'Response content that should be ignored';

        $tempFile = $this->createTempFileWithContent($fileContent);
        $handle = fopen($tempFile, 'rb');

        self::assertIsResource(
            $handle,
            'File handle should be a valid resource.',
        );

        $response = new Response();

        $response->content = $responseContent; // this should be ignored
        $response->stream = [$handle, 0, strlen($fileContent) - 1];

        $response->setStatusCode(200);

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);

        $psr7Response = $adapter->toPsr7();
        $body = (string) $psr7Response->getBody();

        self::assertSame(
            $fileContent,
            $body,
            'File stream should take precedence over response content property.',
        );
        self::assertNotSame(
            $responseContent,
            $body,
            'Response content property should be ignored when file stream is present.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFormatCookieWithAllAttributes(): void
    {
        $this->mockWebApplication();

        $futureTime = time() + 3600;

        $cookieConfig = [
            'name' => 'full_cookie',
            'value' => 'full_value',
            'expire' => $futureTime,
            'path' => '/test/path',
            'domain' => 'example.com',
            'secure' => true,
            'httpOnly' => true,
            'sameSite' => 'Strict',
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

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

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFormatCookieWithBasicAttributes(): void
    {
        $this->mockWebApplication();

        $cookieConfig = [
            'name' => 'test_cookie',
            'value' => 'test_value',
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

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

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFormatCookieWithDateTimeImmutableExpire(): void
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

        $pastDateTime = new DateTimeImmutable('-1 hour');

        $pastTimestamp = $pastDateTime->getTimestamp();

        $cookieConfig = [
            'name' => 'immutable_expire_cookie',
            'value' => 'immutable_expire_value',
            'expire' => $pastDateTime,
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header present in the response when a cookie with 'DateTimeImmutable' expire " .
            'is added.',
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertStringStartsWith(
            urlencode('immutable_expire_cookie') . '=' . urlencode('immutable_expire_value'),
            $cookieHeader,
            "Cookie should NOT be hashed when expire time is in the past ('DateTimeImmutable' object).",
        );
        self::assertStringContainsString(
            '; Expires=' . gmdate('D, d-M-Y H:i:s T', $pastTimestamp),
            $cookieHeader,
            "'Set-Cookie' header should contain correctly formatted expiration date from 'DateTimeImmutable' object.",
        );
        self::assertStringContainsString(
            '; Max-Age=0',
            $cookieHeader,
            "'Set-Cookie' header should have 'Max-Age=0' for expired cookie.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFormatCookieWithDefaultValidation(): void
    {
        $this->mockWebApplication();

        $cookieConfig = [
            'name' => 'test_cookie',
            'value' => 'test_value',
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header present in the response.",
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

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

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFormatCookieWithEmptyOptionalAttributes(): void
    {
        $this->mockWebApplication();

        $cookieConfig = [
            'name' => 'minimal_cookie',
            'value' => 'minimal_value',
            'path' => '',
            'domain' => '',
            'secure' => false,
            'httpOnly' => false,
            'sameSite' => null,
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header present in the response when a minimal cookie is added.",
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

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

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFormatCookieWithExpirationZero(): void
    {
        $this->mockWebApplication();

        $cookieConfig = [
            'name' => 'session_cookie',
            'value' => 'session_value',
            'expire' => 0,
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

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

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFormatCookieWithExpireAtCurrentTime(): void
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

        $currentTime = time();

        $cookieConfig = [
            'name' => 'current_time_cookie',
            'value' => 'current_time_value',
            'expire' => $currentTime, // Exactly current time
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header present in the response when a cookie expires at current time.",
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertStringStartsWith(
            urlencode('current_time_cookie') . '=',
            $cookieHeader,
            "'Set-Cookie' header should start with the encoded cookie 'name'.",
        );
        self::assertStringStartsNotWith(
            urlencode('current_time_cookie') . '=' . urlencode('current_time_value'),
            $cookieHeader,
            'Cookie should be hashed when expire equals current time (validation applies).',
        );
        self::assertStringContainsString(
            '; Max-Age=0',
            $cookieHeader,
            "'Set-Cookie' header should have 'Max-Age=0' when cookie expires at current time.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFormatCookieWithExpiredMaxAge(): void
    {
        $this->mockWebApplication();

        $pastTime = time() - 3600; // 1 hour ago

        $cookieConfig = [
            'name' => 'expired_cookie',
            'value' => 'expired_value',
            'expire' => $pastTime,
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header should be present for an expired cookie.",
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertStringContainsString(
            '; Max-Age=0',
            $cookieHeader,
            "'Max-Age' should be '0' (not negative) for expired cookies due to 'max(0, ...)' function.",
        );
        self::assertStringNotContainsString(
            '; Max-Age=-',
            $cookieHeader,
            "'Max-Age' should never be negative.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFormatCookieWithExpireSetToOne(): void
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

        $cookieConfig = [
            'name' => 'special_cookie',
            'value' => 'special_value',
            'expire' => 1, // Special case in Yii2 - no validation
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header present in the response when a cookie with 'expire=1' is added.",
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertStringStartsWith(
            urlencode('special_cookie') . '=' . urlencode('special_value'),
            $cookieHeader,
            "'Set-Cookie' header should contain the plain value when 'expire=1' (special case - no validation).",
        );
        self::assertStringContainsString(
            '; Expires=' . gmdate('D, d-M-Y H:i:s T', 1),
            $cookieHeader,
            "'Set-Cookie' header should contain expiration date for timestamp '1'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFormatCookieWithFutureMaxAge(): void
    {
        $this->mockWebApplication();

        $futureTime = time() + 7200; // 2 hours from now

        $cookieConfig = [
            'name' => 'future_cookie',
            'value' => 'future_value',
            'expire' => $futureTime,
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

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

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFormatCookieWithSpecialCharacters(): void
    {
        $this->mockWebApplication();

        $cookieConfig = [
            'name' => 'special cookie!',
            'value' => 'special value@#$%',
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

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
            "Cookie header should properly 'URL-encode' special characters in cookie 'name'.",
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

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFormatCookieWithStringExpire(): void
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

        $futureTime = time() + 3600;
        $futureTimeString = date('Y-m-d H:i:s', $futureTime);
        $futureStrToTime = strtotime($futureTimeString);

        $cookieConfig = [
            'name' => 'string_expire_cookie',
            'value' => 'string_expire_value',
            'expire' => $futureTimeString,
        ];

        self::assertNotFalse(
            $futureStrToTime,
            "Future time string '$futureTimeString' should be a valid 'date/time' format.",
        );

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header present in the response when a cookie with string expire time is added.",
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertStringStartsWith(
            urlencode('string_expire_cookie') . '=',
            $cookieHeader,
            "'Set-Cookie' header should start with the encoded cookie 'name'.",
        );
        self::assertStringStartsNotWith(
            urlencode('string_expire_cookie') . '=' . urlencode('string_expire_value'),
            $cookieHeader,
            'Cookie should be hashed when expire time is in the future (string format).',
        );
        self::assertStringContainsString(
            '; Expires=' . gmdate('D, d-M-Y H:i:s T', $futureStrToTime),
            $cookieHeader,
            "'Set-Cookie' header should contain correctly formatted expiration date from string expire time.",
        );
        self::assertStringContainsString(
            '; Max-Age=',
            $cookieHeader,
            "'Set-Cookie' header should contain 'Max-Age' attribute for future expiration.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFormatCookieWithStringExpireOne(): void
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

        $cookieConfig = [
            'name' => 'string_expire_cookie',
            'value' => 'string_expire_value',
            'expire' => '1', // String '1' should also bypass validation due to !== comparison
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header present in the response when a cookie with 'expire=1' (string) is added.",
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertStringStartsWith(
            urlencode('string_expire_cookie') . '=' . urlencode('string_expire_value'),
            $cookieHeader,
            "'Set-Cookie' header should contain the plain value when 'expire=1' (string - special case due to " .
            "'!==' comparison).",
        );
        self::assertStringContainsString(
            '; Expires=' . gmdate('D, d-M-Y H:i:s T', 1),
            $cookieHeader,
            "'Set-Cookie' header should contain expiration date for timestamp '1'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFormatCookieWithValidationDisabled(): void
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

        $cookieConfig = [
            'name' => 'past_cookie',
            'value' => 'past_value',
            'expire' => $pastTime,
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

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

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFormatCookieWithValidationDisabledNoKey(): void
    {
        $this->mockWebApplication([
            'components' => [
                'request' => [
                    'class' => Request::class,
                    'enableCookieValidation' => false,
                ],
            ],
        ]);

        $cookieConfig = [
            'name' => 'test_cookie',
            'value' => 'test_value',
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

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

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFormatCookieWithValidationEnabled(): void
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

        $cookieConfig = [
            'name' => 'expired_cookie',
            'value' => 'expired_value',
            'expire' => $pastTime,
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

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
            urlencode('expired_cookie') . '=' . urlencode('expired_value'),
            $cookieHeader,
            "'Set-Cookie' header should start with the encoded cookie 'name' when validation is enabled.",
        );
        self::assertStringContainsString(
            '; Expires=' . gmdate('D, d-M-Y H:i:s T', $pastTime),
            $cookieHeader,
            "'Set-Cookie' header should contain the correct 'Expires' attribute for the expired cookie.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFormatCookieWithValidationEnabledFutureExpiration(): void
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

        $cookieConfig = [
            'name' => 'valid_cookie',
            'value' => 'valid_value',
            'expire' => $futureTime,
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

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

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFormatCookieWithZeroMaxAge(): void
    {
        $this->mockWebApplication();

        $currentTime = time();

        $cookieConfig = [
            'name' => 'zero_max_age_cookie',
            'value' => 'zero_max_age_value',
            'expire' => $currentTime,
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

        $response->cookies->add($cookie);
        $psr7Response = $adapter->toPsr7();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "Exactly one 'Set-Cookie' header should be present.",
        );

        $cookieHeader = $setCookieHeaders[0] ?? '';

        self::assertMatchesRegularExpression(
            '/; Max-Age=0(?:;|$)/',
            $cookieHeader,
            "'Max-Age' should be exactly '0' when expire time equals current time.",
        );

        preg_match('/; Max-Age=(\d+)/', $cookieHeader, $matches);

        $maxAge = $matches[1] ?? null;

        self::assertSame(
            '0',
            $maxAge,
            "'Max-Age' must be exactly '0' (string) when cookie expires at current time.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFormatResponseWithCustomStatusAndCookie(): void
    {
        $this->mockWebApplication();

        $cookieConfig = [
            'name' => 'test',
            'value' => 'value',
        ];

        $response = new Response();

        $response->setStatusCode(201, 'Created');

        $response->content = 'Test response body';

        $response->headers->add('X-Custom-Header', 'Custom Value');

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

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
        self::assertStringNotContainsString(
            'test=value',
            $setCookieHeaders[0] ?? '',
            "'Set-Cookie' header should not contain plain value when validation is enabled.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testHashCookieValueIncludingName(): void
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

        $cookieConfig1 = [
            'name' => 'cookie_name_a',
            'value' => 'same_value',
        ];
        $cookieConfig2 = [
            'name' => 'cookie_name_b',
            'value' => 'same_value',
        ];

        $response1 = new Response();
        $response2 = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter1 = new ResponseAdapter($response1, $responseFactory, $streamFactory);
        $adapter2 = new ResponseAdapter($response2, $responseFactory, $streamFactory);
        $cookie1 = new Cookie($cookieConfig1);
        $cookie2 = new Cookie($cookieConfig2);

        $response1->cookies->add($cookie1);
        $response2->cookies->add($cookie2);
        $psr7Response1 = $adapter1->toPsr7();
        $psr7Response2 = $adapter2->toPsr7();
        $cookieHeader1 = $psr7Response1->getHeader('Set-Cookie')[0] ?? '';
        $cookieHeader2 = $psr7Response2->getHeader('Set-Cookie')[0] ?? '';

        preg_match('/^[^=]+=([^;]+)/', $cookieHeader1, $matches1);
        preg_match('/^[^=]+=([^;]+)/', $cookieHeader2, $matches2);

        $hashedValue1 = $matches1[1] ?? '';
        $hashedValue2 = $matches2[1] ?? '';

        self::assertNotEmpty(
            $hashedValue1,
            'First cookie should have a hashed value.',
        );
        self::assertNotEmpty(
            $hashedValue2,
            'Second cookie should have a hashed value.',
        );
        self::assertNotSame(
            $hashedValue1,
            $hashedValue2,
            "Cookies with same value but different names should produce different hashes ('name' is included in hash).",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testProduceMultipleCookieHeadersWhenAddingCookies(): void
    {
        $this->mockWebApplication();

        $cookieConfig1 = [
            'name' => 'cookie1',
            'value' => 'value1',
        ];
        $cookieConfig2 = [
            'name' => 'cookie2',
            'value' => 'value2',
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie1 = new Cookie($cookieConfig1);
        $cookie2 = new Cookie($cookieConfig2);

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
            'cookie1=',
            $headerValues,
            "First 'Set-Cookie' header must contain 'cookie1'.",
        );
        self::assertStringContainsString(
            'cookie2=',
            $headerValues,
            "Second 'Set-Cookie' header must contain 'cookie2'.",
        );
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

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testSkipCookieHeaderWhenValueEmpty(): void
    {
        $this->mockWebApplication();

        $validCookieConfig = [
            'name' => 'valid',
            'value' => 'value',
        ];
        $emptyCookieConfig = [
            'name' => 'empty',
            'value' => '',
        ];
        $nullCookieConfig = [
            'name' => 'null',
            'value' => null,
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $validCookie = new Cookie($validCookieConfig);
        $emptyCookie = new Cookie($emptyCookieConfig);
        $nullCookie = new Cookie($nullCookieConfig);

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
        self::assertStringNotContainsString(
            'valid=value',
            $setCookieHeaders[0] ?? '',
            "'Set-Cookie' header should contain hashed value when validation is enabled.",
        );
    }

    public function testThrowExceptionWhenStreamFormatIsInvalid(): void
    {
        $this->mockWebApplication();

        $tempFile = $this->createTempFileWithContent('test');
        $handle = fopen($tempFile, 'rb');

        self::assertIsResource($handle, 'File handle should be a valid resource.');

        $response = new Response();

        $response->stream = [$handle, 0]; // Missing end parameter

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(Message::RESPONSE_STREAM_FORMAT_INVALID->getMessage());

        $adapter->toPsr7();
    }

    public function testThrowExceptionWhenStreamHandleIsInvalid(): void
    {
        $this->mockWebApplication();

        $response = new Response();

        $response->stream = ['not-a-resource', 0, 100];

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(Message::RESPONSE_STREAM_HANDLE_INVALID->getMessage());

        $adapter->toPsr7();
    }

    public function testThrowExceptionWhenStreamRangeIsInvalid(): void
    {
        $this->mockWebApplication();

        $content = 'Test content';

        $tempFile = $this->createTempFileWithContent($content);
        $handle = fopen($tempFile, 'rb');

        self::assertIsResource($handle, 'File handle should be a valid resource.');

        // empty range: begin == end + 1 (invalid range, but testing edge case)
        $begin = 5;
        $end = 4; // invalid: end < begin

        $response = new Response();

        $response->stream = [$handle, $begin, $end];

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(Message::RESPONSE_STREAM_RANGE_INVALID->getMessage($begin, $end));

        $adapter->toPsr7()->getBody()->getContents();
    }

    public function testThrowExceptionWhenStreamRangeIsInvalidWithNegativeBegin(): void
    {
        $this->mockWebApplication();

        $tempFile = $this->createTempFileWithContent('test content');
        $handle = fopen($tempFile, 'rb');

        self::assertIsResource($handle, 'File handle should be a valid resource.');

        $response = new Response();

        $response->stream = [$handle, -1, 10]; // negative begin

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(Message::RESPONSE_STREAM_RANGE_INVALID->getMessage(-1, 10));

        $adapter->toPsr7();
    }

    public function testThrowExceptionWhenValidationKeyEmpty(): void
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

        $cookieConfig = [
            'name' => 'test_cookie',
            'value' => 'test_value',
        ];

        $response = new Response();

        $responseFactory = FactoryHelper::createResponseFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $adapter = new ResponseAdapter($response, $responseFactory, $streamFactory);
        $cookie = new Cookie($cookieConfig);

        $response->cookies->add($cookie);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(Message::COOKIE_VALIDATION_KEY_NOT_CONFIGURED->getMessage(Request::class));

        $adapter->toPsr7();
    }

    /**
     * Creates a temporary file with the specified content for testing.
     *
     * @param string $content Content to write to the temporary file.
     *
     * @return string Path to the created temporary file.
     */
    private function createTempFileWithContent(string $content): string
    {
        $runtime = dirname(__DIR__, 2) . '/runtime';
        $tempFile = tempnam($runtime, 'psr_bridge_test_');

        if ($tempFile === false) {
            throw new RuntimeException('Unable to create temporary file.');
        }

        $handle = fopen($tempFile, 'wb');

        if ($handle === false) {
            unlink($tempFile);

            throw new RuntimeException('Unable to open temporary file for writing.');
        }

        $bytesWritten = fwrite($handle, $content);
        fclose($handle);

        if ($bytesWritten === false || $bytesWritten !== strlen($content)) {
            unlink($tempFile);

            throw new RuntimeException('Unable to write content to temporary file.');
        }

        return $tempFile;
    }
}
