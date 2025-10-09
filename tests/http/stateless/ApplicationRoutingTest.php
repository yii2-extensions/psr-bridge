<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\Group;
use yii\base\InvalidConfigException;
use yii2\extensions\psrbridge\tests\support\{FactoryHelper, TestCase};

/**
 * Test suite for {@see \yii2\extensions\psrbridge\http\StatelessApplication} routing and parameter handling in
 * stateless mode.
 *
 * Verifies correct extraction and processing of route, query, and POST parameters in stateless Yii2 applications.
 *
 * Test coverage.
 * - Checks route parameter mapping and response formatting.
 * - Confirms correct handling of POST parameters and JSON response structure.
 * - Ensures query parameters are parsed and returned as expected.
 * - Validates combined route and query parameter extraction.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('http')]
final class ApplicationRoutingTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testHandlePostParameters(): void
    {
        $app = $this->statelessApplication();

        $response = $app->handle(
            FactoryHelper::createRequest(
                method: 'POST',
                uri: '/site/post',
                headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
                parsedBody: [
                    'foo' => 'bar',
                    'a' => ['b' => 'c'],
                ],
            ),
        );

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/post'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/post'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"foo":"bar","a":{"b":"c"}}
            JSON,
            $response->getBody()->getContents(),
            "Response body should match expected JSON string '{\"foo\":\"bar\",\"a\":{\"b\":\"c\"}}'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testHandleQueryParameters(): void
    {
        $app = $this->statelessApplication();

        $response = $app->handle(
            FactoryHelper::createRequest(
                method: 'GET',
                uri: '/site/get?foo=bar&a[b]=c',
            ),
        );

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/get'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/get'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"foo":"bar","a":{"b":"c"}}
            JSON,
            $response->getBody()->getContents(),
            "Response body should match expected JSON string '{\"foo\":\"bar\",\"a\":{\"b\":\"c\"}}'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testHandleRouteAndQueryParameters(): void
    {
        $app = $this->statelessApplication();

        $response = $app->handle(
            FactoryHelper::createRequest(
                method: 'GET',
                uri: '/site/query/foo?q=1',
            ),
        );

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/query/foo?q=1'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/query/foo?q=1'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"test":"foo","q":"1","queryParams":{"test":"foo","q":"1"}}
            JSON,
            $response->getBody()->getContents(),
            'Response body should contain valid JSON with route and query parameters.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testHandleRouteParameters(): void
    {
        $request = FactoryHelper::createRequest(method: 'GET', uri: 'site/update/123');

        $app = $this->statelessApplication();

        $response = $app->handle($request);

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/update/123'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/update/123'.",
        );
        self::assertSame(
            '{"site/update":"123"}',
            $response->getBody()->getContents(),
            'Response body should contain valid JSON with the route parameter.',
        );
        self::assertSame(
            'site/update/123',
            $request->getUri()->getPath(),
            "Request path should be 'site/update/123'.",
        );
    }
}
