<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

#[Group('http')]
final class StatelessApplicationTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->closeApplication();

        parent::tearDown();
    }

    public function testReturnCookiesHeadersForSiteCookieRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/cookie',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

        self::assertInstanceOf(
            ResponseInterface::class,
            $response,
            "Response should be an instance of 'ResponseInterface' when handling 'site/cookie' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response status code should be '200' for 'site/cookie' route in 'StatelessApplication'.",
        );

        $cookies = $response->getHeaders()['set-cookie'] ?? [];

        foreach ($cookies as $i => $cookie) {
            // Skip the last cookie header (assumed to be 'PHPSESSION').
            if ((int) $i + 1 === count($cookies)) {
                continue;
            }

            $params = explode('; ', $cookie);

            self::assertTrue(
                in_array(
                    $params[0],
                    [
                        'test=test',
                        'test2=test2',
                    ],
                    true,
                ),
                sprintf(
                    "Cookie header should contain either 'test=test' or 'test2=test2', got '%s' for 'site/cookie' " .
                    'route.',
                    $params[0],
                ),
            );
        }
    }

    public function testReturnJsonResponseWithCookiesForSiteGetCookiesRoute(): void
    {
        $_COOKIE = [
            'test' => 'test',
        ];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/getcookies',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

        self::assertInstanceOf(
            ResponseInterface::class,
            $response,
            "Response should be an instance of 'ResponseInterface' when handling 'site/getcookies' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response status code should be '200' for 'site/getcookies' route in 'StatelessApplication'.",
        );
        self::assertSame(
            <<<JSON
            {"test":{"name":"test","value":"test","domain":"","expire":null,"path":"/","secure":false,"httpOnly":true,"sameSite":"Lax"}}
            JSON,
            $response->getBody()->getContents(),
            "Response body should match expected JSON string for cookie 'test' on 'site/getcookies' route in " .
            "'StatelessApplication'.",
        );
    }

    public function testReturnJsonResponseWithPostParametersForSitePostRoute(): void
    {
        $_POST = [
            'foo' => 'bar',
            'a' => [
                'b' => 'c',
            ],
        ];
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => 'site/post',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

        self::assertInstanceOf(
            ResponseInterface::class,
            $response,
            "Response should be an instance of 'ResponseInterface' when handling 'site/post' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response status code should be '200' for 'site/post' route in 'StatelessApplication'.",
        );
        self::assertSame(
            '{"foo":"bar","a":{"b":"c"}}',
            $response->getBody()->getContents(),
            "Response body should match expected JSON string '{\"foo\":\"bar\",\"a\":{\"b\":\"c\"}}' for 'site/post'" .
            "route in 'StatelessApplication'.",
        );
    }

    public function testReturnJsonResponseWithQueryParametersForSiteGetRoute(): void
    {
        $_GET = [
            'foo' => 'bar',
            'a' => [
                'b' => 'c',
            ],
        ];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/get',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

        self::assertInstanceOf(
            ResponseInterface::class,
            $response,
            "Response should be an instance of 'ResponseInterface' when handling 'site/get' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response status code should be '200' for 'site/get' route in 'StatelessApplication'.",
        );
        self::assertSame(
            '{"foo":"bar","a":{"b":"c"}}',
            $response->getBody()->getContents(),
            "Response body should match expected JSON string '{\"foo\":\"bar\",\"a\":{\"b\":\"c\"}}' for 'site/get' " .
            "route in 'StatelessApplication'.",
        );
    }

    public function testReturnRedirectResponseForSiteRedirectRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/redirect',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

        self::assertInstanceOf(
            ResponseInterface::class,
            $response,
            "Response should be an instance of 'ResponseInterface' when handling 'site/redirect' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            302,
            $response->getStatusCode(),
            "Response status code should be '302' for redirect route 'site/redirect' in 'StatelessApplication'.",
        );
        self::assertSame(
            '/site/index',
            $response->getHeaders()['location'][0] ?? '',
            "Response 'location' header should be '/site/index' for redirect route 'site/redirect' in " .
            "'StatelessApplication'.",
        );
    }

    public function testReturnRedirectResponseForSiteRefreshRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/refresh',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

        self::assertInstanceOf(
            ResponseInterface::class,
            $response,
            "Response should be an instance of 'ResponseInterface' when handling 'site/refresh' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            302,
            $response->getStatusCode(),
            "Response status code should be '302' for redirect route 'site/refresh' in 'StatelessApplication'.",
        );
        self::assertSame(
            'site/refresh#stateless',
            $response->getHeaders()['location'][0] ?? '',
            "Response 'location' header should be 'site/refresh#stateless' for redirect route 'site/refresh' in " .
            "'StatelessApplication'.",
        );
    }

    public function testReturnsJsonResponse(): void
    {
        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

        self::assertInstanceOf(
            ResponseInterface::class,
            $response,
            "Response should be an instance of 'ResponseInterface' when 'handled()' by 'StatelessApplication'.",
        );
        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response status code should be '200' for successful 'StatelessApplication' handling.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'application/json; charset=UTF-8' for JSON output.",
        );

        $body = $response->getBody()->getContents();

        self::assertSame(
            '{"hello":"world"}',
            $body,
            'Response body should match expected JSON string "{\"hello\":\"world\"}".',
        );
    }

    public function testReturnsStatusCode201ForSiteStatuscodeRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/statuscode',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

        self::assertInstanceOf(
            ResponseInterface::class,
            $response,
            "Response should be an instance of 'ResponseInterface' when handling 'site/statuscode' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            201,
            $response->getStatusCode(),
            "Response status code should be '201' for 'site/statuscode' route in 'StatelessApplication'.",
        );
    }
}
