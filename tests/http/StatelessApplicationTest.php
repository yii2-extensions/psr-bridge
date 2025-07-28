<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group};
use Psr\Http\Message\ResponseInterface;
use Yii;
use yii\base\Security;
use yii\i18n\{Formatter, I18N};
use yii\log\Dispatcher;
use yii\web\{AssetManager, Session, UrlManager, User, View};
use yii2\extensions\psrbridge\http\{ErrorHandler, Request, Response};
use yii2\extensions\psrbridge\tests\provider\StatelessApplicationProvider;
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

    public function testGetMemoryLimitHandlesUnlimitedMemoryCorrectly(): void
    {
        $originalLimit = ini_get('memory_limit');

        try {
            ini_set('memory_limit', '-1');

            $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

            $app = $this->statelessApplication();

            self::assertSame(
                PHP_INT_MAX,
                $app->getMemoryLimit(),
                "Memory limit should be 'PHP_INT_MAX' when set to '-1' (unlimited) in 'StatelessApplication'.",
            );

            $app->handle($request);
            $app->clean();
        } finally {
            ini_set('memory_limit', $originalLimit);
        }
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
            // skip the last cookie header (assumed to be 'PHPSESSION').
            if (str_starts_with($cookie, 'PHPSESSID=') === false) {
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
    }

    public function testReturnCoreComponentsConfigurationAfterHandle(): void
    {
        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();
        $app->handle($request);

        self::assertSame(
            [
                'log' => [
                    'class' => Dispatcher::class,
                ],
                'view' => [
                    'class' => View::class,
                ],
                'formatter' => [
                    'class' => Formatter::class,
                ],
                'i18n' => [
                    'class' => I18N::class,
                ],
                'urlManager' => [
                    'class' => UrlManager::class,
                ],
                'assetManager' => [
                    'class' => AssetManager::class,
                ],
                'security' => [
                    'class' => Security::class,
                ],
                'request' => [
                    'class' => Request::class,
                ],
                'response' => [
                    'class' => Response::class,
                ],
                'session' => [
                    'class' => Session::class,
                ],
                'user' => [
                    'class' => User::class,
                ],
                'errorHandler' => [
                    'class' => ErrorHandler::class,
                ],
            ],
            $app->coreComponents(),
            "'coreComponents()' should return the expected mapping of component IDs to class definitions after " .
            "handling a request in 'StatelessApplication'.",
        );
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

    public function testReturnJsonResponseWithCredentialsForSiteAuthRoute(): void
    {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('admin:admin'),
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/auth',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

        self::assertInstanceOf(
            ResponseInterface::class,
            $response,
            "Response should be an instance of 'ResponseInterface' when handling 'site/auth' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response status code should be '200' for 'site/auth' route in 'StatelessApplication'.",
        );
        self::assertSame(
            <<<JSON
            {"username":"admin","password":"admin"}
            JSON,
            $response->getBody()->getContents(),
            "Response body should match expected JSON string '{\"username\":\"admin\",\"password\":\"admin\"}' " .
            "for 'site/auth' route in 'StatelessApplication'.",
        );
    }

    public function testReturnJsonResponseWithNullCredentialsForMalformedAuthorizationHeader(): void
    {
        $_SERVER = [
            'HTTP_authorization' => 'Basic foo:bar',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/auth',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

        self::assertInstanceOf(
            ResponseInterface::class,
            $response,
            "Response should be an instance of 'ResponseInterface' when handling 'site/auth' route with malformed " .
            "authorization header in 'StatelessApplication'.",
        );
        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response status code should be '200' for 'site/auth' route with malformed authorization header in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            <<<JSON
            {"username":null,"password":null}
            JSON,
            $response->getBody()->getContents(),
            "Response body should match expected JSON string '{\"username\":null,\"password\":null}' for malformed " .
            "authorization header on 'site/auth' route in 'StatelessApplication'.",
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
            <<<JSON
            {"foo":"bar","a":{"b":"c"}}
            JSON,
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
            <<<JSON
            {"foo":"bar","a":{"b":"c"}}
            JSON,
            $response->getBody()->getContents(),
            "Response body should match expected JSON string '{\"foo\":\"bar\",\"a\":{\"b\":\"c\"}}' for 'site/get' " .
            "route in 'StatelessApplication'.",
        );
    }

    public function testReturnPlainTextFileResponseForSiteFileRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/file',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

        self::assertInstanceOf(
            ResponseInterface::class,
            $response,
            "Response should be an instance of 'ResponseInterface' when handling 'site/file' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response status code should be '200' for 'site/file' route in 'StatelessApplication'.",
        );

        $body = $response->getBody()->getContents();

        self::assertSame(
            'text/plain',
            $response->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'text/plain' for 'site/file' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'This is a test file content.',
            $body,
            "Response body should match expected plain text 'This is a test file content.' for 'site/file' route " .
            "in 'StatelessApplication'.",
        );
        self::assertSame(
            'attachment; filename="testfile.txt"',
            $response->getHeaders()['content-disposition'][0] ?? '',
            "Response 'content-disposition' should be 'attachment; filename=\"testfile.txt\"' for 'site/file' route " .
            "in 'StatelessApplication'.",
        );
    }

    public function testReturnPlainTextResponseWithFileContentForSiteStreamRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/stream',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

        self::assertInstanceOf(
            ResponseInterface::class,
            $response,
            "Response should be an instance of 'ResponseInterface' when handling 'site/stream' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response status code should be '200' for 'site/stream' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'text/plain',
            $response->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'text/plain' for 'site/stream' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'This is a test file content.',
            $response->getBody()->getContents(),
            "Response body should match expected plain text 'This is a test file content.' for 'site/stream' route " .
            "in 'StatelessApplication'.",
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
            <<<JSON
            {"hello":"world"}
            JSON,
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

    public function testSetWebAndWebrootAliasesAfterHandleRequest(): void
    {
        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();
        $app->handle($request);

        self::assertSame(
            '',
            Yii::getAlias('@web'),
            "'@web' alias should be set to an empty string after handling a request in 'StatelessApplication'.",
        );
        self::assertSame(
            dirname(__DIR__),
            Yii::getAlias('@webroot'),
            "'@webroot' alias should be set to the parent directory of the test directory after handling a request " .
            "in 'StatelessApplication'.",
        );
    }

    #[DataProviderExternal(StatelessApplicationProvider::class, 'eventDataProvider')]
    public function testTriggerEventDuringHandle(string $eventName): void
    {
        $eventTriggered = false;

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $app->on(
            $eventName,
            static function () use (&$eventTriggered): void {
                $eventTriggered = true;
            },
        );

        $app->handle($request);

        self::assertTrue($eventTriggered, "Should trigger '{$eventName}' event during handle()");
    }
}
