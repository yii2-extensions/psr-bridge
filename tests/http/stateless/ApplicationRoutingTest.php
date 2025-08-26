<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\Group;
use yii\base\InvalidConfigException;
use yii\web\NotFoundHttpException;
use yii2\extensions\psrbridge\exception\Message;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

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

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testThrowNotFoundHttpExceptionWhenRouteNotFound(): void
    {
        $app = $this->statelessApplication(
            [
                'components' => [
                    'urlManager' => [
                        'enableStrictParsing' => true,
                    ],
                ],
            ],
        );

        $response = $app->handle(FactoryHelper::createRequest('GET', '/nonexistent/route'));

        self::assertSame(
            404,
            $response->getStatusCode(),
            "Expected HTTP '404' for route '/nonexistent/route'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'text/html; charset=UTF-8' for route '/nonexistent/route'.",
        );
        self::assertSame(
            '<pre>Not Found: Page not found.</pre>',
            $response->getBody()->getContents(),
            "Response body should match expected HTML string '<pre>Not Found: Page not found.</pre>'.",
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage(Message::PAGE_NOT_FOUND->getMessage());

        $app->request->resolve();
    }
}
