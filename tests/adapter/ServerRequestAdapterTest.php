<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\adapter;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group};
use Psr\Http\Message\ServerRequestInterface;
use yii\base\InvalidConfigException;
use yii2\extensions\psrbridge\http\Request;
use yii2\extensions\psrbridge\tests\provider\RequestProvider;
use yii2\extensions\psrbridge\tests\support\{FactoryHelper, TestCase};

/**
 * Test suite for {@see Request} handling functionality and behavior.
 *
 * Verifies correct behavior of the Request handling when using PSR-7 requests, including body parameters, HTTP method
 * overrides, query string and params, raw body, and parsed body logic.
 *
 * Test coverage.
 * - Data provider integration for query string and URL cases.
 * - Extraction and override of HTTP methods from body and headers.
 * - Handling of body parameters, including method param removal.
 * - Parent fallback logic when adapter is not set.
 * - Query string and query params precedence and fallback.
 * - Raw body and parsed body extraction for arrays and objects.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('adapter')]
final class ServerRequestAdapterTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnBodyParamsWithMethodParamRemoved(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'POST',
                '/test',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                [
                    'key1' => 'value1',
                    'key2' => 'value2',
                    '_method' => 'PUT',
                ],
            ),
        );

        $bodyParams = $request->getBodyParams();

        self::assertIsArray(
            $bodyParams,
            'Body parameters should be returned as an array when method parameter is present.',
        );
        self::assertArrayNotHasKey(
            '_method',
            $bodyParams,
            "Method parameter '_method' should be removed from body parameters.",
        );
        self::assertArrayHasKey(
            'key1',
            $bodyParams,
            "Body parameters should contain the key 'key1' after method parameter removal.",
        );
        self::assertSame(
            'value1',
            $bodyParams['key1'] ?? null,
            "Body parameter 'key1' should have the expected value after method parameter removal.",
        );
        self::assertArrayHasKey(
            'key2',
            $bodyParams,
            "Body parameters should contain the key 'key2' after method parameter removal.",
        );
        self::assertSame(
            'value2',
            $bodyParams['key2'] ?? null,
            "Body parameter 'key2' should have the expected value after method parameter removal.",
        );
    }

    public function testReturnEmptyQueryParamsWhenAdapterIsSet(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/products'),
        );

        self::assertEmpty(
            $request->getQueryParams(),
            'Query parameters should be empty when PSR-7 request has no query string.',
        );
    }

    public function testReturnEmptyQueryStringWhenAdapterIsSetWithNoQuery(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test'),
        );

        self::assertEmpty(
            $request->getQueryString(),
            'Query string should be empty when no query parameters are present.',
        );
    }

    public function testReturnHttpMethodFromAdapterWhenAdapterIsSet(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('POST', '/test'),
        );

        self::assertSame(
            'POST',
            $request->getMethod(),
            'HTTP method should be returned from adapter when adapter is set.',
        );
    }

    public function testReturnHttpMethodWithBodyOverrideAndLowerCaseMethodsWhenAdapterIsSet(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'post',
                '/test',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                [
                    '_method' => 'put',
                    'data' => 'value',
                ],
            ),
        );

        self::assertSame(
            'PUT',
            $request->getMethod(),
            'HTTP method should be overridden by body parameter when adapter is set.',
        );
    }

    public function testReturnHttpMethodWithBodyOverrideWhenAdapterIsSet(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'POST',
                '/test',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                [
                    '_method' => 'PUT',
                    'data' => 'value',
                ],
            ),
        );

        self::assertSame(
            'PUT',
            $request->getMethod(),
            'HTTP method should be overridden by body parameter when adapter is set.',
        );
    }

    public function testReturnHttpMethodWithCustomMethodParamWhenAdapterIsSet(): void
    {
        $request = new Request();

        $request->methodParam = 'custom_method';

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'POST',
                '/test',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                [
                    'custom_method' => 'PATCH',
                    'data' => 'value',
                ],
            ),
        );

        self::assertSame(
            'PATCH',
            $request->getMethod(),
            'HTTP method should be overridden by custom method parameter when adapter is set.',
        );
    }

    public function testReturnHttpMethodWithHeaderOverrideWhenAdapterIsSet(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('POST', '/test', ['X-Http-Method-Override' => 'DELETE']),
        );

        self::assertSame(
            'DELETE',
            $request->getMethod(),
            'HTTP method should be overridden by header when adapter is set.',
        );
    }

    public function testReturnHttpMethodWithoutOverrideWhenAdapterIsSet(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test'),
        );

        self::assertSame(
            'GET',
            $request->getMethod(),
            'HTTP method should return original method when no override is present and adapter is set.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnParentGetParsedBodyWhenAdapterIsNull(): void
    {
        $request = new Request();

        self::assertEmpty(
            $request->getParsedBody(),
            "Parsed body should return empty array when PSR-7 request has no parsed body and adapter is 'null'.",
        );
    }

    public function testReturnParentHttpMethodWhenAdapterIsNull(): void
    {
        $request = new Request();

        self::assertNotEmpty($request->getMethod(), "HTTP method should not be empty when adapter is 'null'.");
    }

    public function testReturnParentQueryParamsWhenAdapterIsNull(): void
    {
        $request = new Request();

        self::assertEmpty(
            $request->getQueryParams(),
            'Query parameters should be empty when PSR-7 request has no query string.',
        );
    }

    public function testReturnParentQueryStringWhenAdapterIsNull(): void
    {
        $request = new Request();

        self::assertEmpty(
            $request->getQueryString(),
            "Query string should be empty when PSR-7 request has no query string and adapter is 'null'.",
        );
    }

    public function testReturnParentRawBodyWhenAdapterIsNull(): void
    {
        $request = new Request();

        self::assertEmpty(
            $request->getRawBody(),
            "Raw body should return empty string when PSR-7 request has no body content and adapter is 'null'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnParsedBodyArrayWhenAdapterIsSet(): void
    {
        $parsedBodyData = [
            'name' => 'John',
            'email' => 'john@example.com',
            'age' => 30,
        ];

        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'POST',
                '/api/users',
                ['Content-Type' => 'application/json'],
                $parsedBodyData,
            ),
        );

        $result = $request->getParsedBody();

        self::assertIsArray(
            $result,
            'Parsed body should return an array when PSR-7 request contains array data.',
        );
        self::assertSame(
            $parsedBodyData,
            $result,
            'Parsed body should match the original data from PSR-7 request.',
        );
        self::assertArrayHasKey(
            'name',
            $result,
            "Parsed body should contain the 'name' field.",
        );
        self::assertSame(
            'John',
            $result['name'],
            "'name' field should match the expected value.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnParsedBodyNullWhenAdapterIsSetWithNullBody(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/api/users'),
        );

        self::assertNull(
            $request->getParsedBody(),
            "Parsed body should return 'null' when PSR-7 request has no parsed body.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnParsedBodyObjectWhenAdapterIsSet(): void
    {
        $parsedBodyObject = (object) [
            'title' => 'Test Article',
            'content' => 'Article content',
        ];

        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'PUT',
                '/api/articles/1',
                ['Content-Type' => 'application/json'],
                $parsedBodyObject,
            ),
        );

        $result = $request->getParsedBody();

        self::assertIsObject(
            $result,
            'Parsed body should return an object when PSR-7 request contains object data.',
        );
        self::assertSame(
            $parsedBodyObject,
            $result,
            'Parsed body object should match the original object from PSR-7 request.',
        );
        self::assertSame(
            'Test Article',
            $result->title,
            "Object 'title' property should match the expected value.",
        );
        self::assertSame(
            'Article content',
            $result->content,
            "Object 'content' property should match the expected value.",
        );
    }

    /**
     * @throws InvalidConfigException
     */
    public function testReturnPsr7RequestInstanceWhenAdapterIsSet(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test'),
        );

        self::assertInstanceOf(
            ServerRequestInterface::class,
            $request->getPsr7Request(),
            "'getPsr7Request()' should return a '" . ServerRequestInterface::class . "' instance when the PSR-7 " .
            'adapter is set.',
        );
    }

    public function testReturnQueryParamsWhenAdapterIsSet(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/products?category=electronics&price=500&sort=desc'),
        );

        $queryParams = $request->getQueryParams();

        self::assertArrayHasKey(
            'category',
            $queryParams,
            "Query parameters should contain the key 'category' when present in the PSR-7 request URI.",
        );
        self::assertSame(
            'electronics',
            $queryParams['category'] ?? null,
            "Query parameter 'category' should have the expected value from the PSR-7 request URI.",
        );
        self::assertArrayHasKey(
            'price',
            $queryParams,
            "Query parameters should contain the key 'price' when present in the PSR-7 request URI.",
        );
        self::assertSame(
            '500',
            $queryParams['price'] ?? null,
            "Query parameter 'price' should have the expected value from the PSR-7 request URI.",
        );
        self::assertArrayHasKey(
            'sort',
            $queryParams,
            "Query parameters should contain the key 'sort' when present in the PSR-7 request URI.",
        );
        self::assertSame(
            'desc',
            $queryParams['sort'] ?? null,
            "Query parameter 'sort' should have the expected value from the PSR-7 request URI.",
        );
    }

    /**
     * @phpstan-param string $expectedString
     */
    #[DataProviderExternal(RequestProvider::class, 'getQueryString')]
    public function testReturnQueryStringWhenAdapterIsSet(string $queryString, string $expectedString): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', "/test?{$queryString}"),
        );

        self::assertSame(
            $expectedString,
            $request->getQueryString(),
            "Query string should match the expected value for: '{$queryString}'.",
        );
    }

    public function testReturnRawBodyFromAdapterWhenAdapterIsSet(): void
    {
        $bodyContent = '{"name":"John","email":"john@example.com","message":"Hello World"}';

        $stream = FactoryHelper::createStream();

        $stream->write($bodyContent);

        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('POST', '/api/contact')->withBody($stream),
        );

        self::assertSame(
            $bodyContent,
            $request->getRawBody(),
            'Raw body should return the exact content from the PSR-7 request body when adapter is set.',
        );
    }

    public function testReturnRawBodyWhenAdapterIsSetWithEmptyBody(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test'),
        );

        self::assertEmpty(
            $request->getRawBody(),
            'Raw body should return empty string when PSR-7 request has no body content.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[DataProviderExternal(RequestProvider::class, 'getUrl')]
    public function testReturnUrlFromAdapterWhenAdapterIsSet(string $url, string $expectedUrl): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', $url),
        );

        self::assertSame(
            $expectedUrl,
            $request->getUrl(),
            "URL should match the expected value for: {$url}.",
        );
    }
}
