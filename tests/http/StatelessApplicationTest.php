<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group};
use Yii;
use yii\base\{InvalidConfigException, Security};
use yii\helpers\Json;
use yii\i18n\{Formatter, I18N};
use yii\log\Dispatcher;
use yii\web\{AssetManager, Session, UrlManager, User, View};
use yii2\extensions\psrbridge\http\{ErrorHandler, Request, Response};
use yii2\extensions\psrbridge\tests\provider\StatelessApplicationProvider;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function array_filter;
use function array_key_exists;
use function explode;
use function ini_get;
use function ini_set;
use function session_name;
use function sprintf;
use function str_starts_with;

use const PHP_INT_MAX;

#[Group('http')]
final class StatelessApplicationTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->closeApplication();

        parent::tearDown();
    }

    /**
     * @throws InvalidConfigException
     */
    public function testCaptchaSessionIsolation(): void
    {
        $sessionName = session_name();

        // first user generates captcha - need to use refresh=1 to get JSON response
        $_COOKIE = [$sessionName => 'user-a-session'];
        $_GET = ['refresh' => '1'];
        $_SERVER = [
            'QUERY_STRING' => 'refresh=1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/captcha',
        ];

        $request1 = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response1 = $app->handle($request1);

        self::assertSame(
            'application/json; charset=UTF-8',
            $response1->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'application/json; charset=UTF-8' for 'site/captcha' route in " .
            "'StatelessApplication'.",
        );

        $captchaData1 = Json::decode($response1->getBody()->getContents());

        self::assertIsArray(
            $captchaData1,
            "Captcha response should be an array after decoding JSON for 'site/captcha' route in " .
            "'StatelessApplication'.",
        );
        self::assertArrayHasKey(
            'hash1',
            $captchaData1,
            "Captcha response should contain 'hash1' key for 'site/captcha' route in 'StatelessApplication'.",
        );
        self::assertArrayHasKey(
            'hash2',
            $captchaData1,
            "Captcha response should contain 'hash2' key for 'site/captcha' route in 'StatelessApplication'.",
        );
        self::assertArrayHasKey(
            'url',
            $captchaData1,
            "Captcha response should contain 'url' key for 'site/captcha' route in 'StatelessApplication'.",
        );

        // second user requests captcha - should get different data
        $_COOKIE = [$sessionName => 'user-b-session'];
        $_GET = ['refresh' => '1'];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/captcha',
            'QUERY_STRING' => 'refresh=1',
        ];

        $request2 = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response2 = $app->handle($request2);

        $captchaData2 = Json::decode($response2->getBody()->getContents());

        self::assertIsArray(
            $captchaData2,
            "Captcha response should be an array after decoding JSON for second user in 'site/captcha' route in " .
            "'StatelessApplication'.",
        );
        self::assertArrayHasKey(
            'hash1',
            $captchaData2,
            "Captcha response should contain 'hash1' key for second user in 'site/captcha' route in " .
            "'StatelessApplication'.",
        );

        $hash1 = $captchaData1['hash1'] ?? null;
        $hash2 = $captchaData2['hash1'] ?? null;

        self::assertNotNull(
            $hash1,
            "First captcha response 'hash1' should not be 'null' for 'site/captcha' route in 'StatelessApplication'.",
        );
        self::assertNotNull(
            $hash2,
            "Second captcha response 'hash2' should not be 'null' for 'site/captcha' route in 'StatelessApplication'.",
        );
        self::assertNotSame(
            $hash1,
            $hash2,
            "Captcha 'hash1' for first user should not match 'hash2' for second user, ensuring session isolation in " .
            "'StatelessApplication'.",
        );

        // also test that we can get the actual captcha image
        $url = $captchaData2['url'] ?? null;

        self::assertNotNull(
            $url,
            "Captcha response 'url' should not be 'null' for second user in 'site/captcha' route in " .
            "'StatelessApplication'.",
        );

        $_COOKIE = [$sessionName => 'user-a-session'];
        $_GET = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $url,
        ];

        $request3 = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response3 = $app->handle($request3);

        self::assertIsString(
            $url,
            "Captcha response 'url' should be a string for second user in 'site/captcha' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            'image/png',
            $response3->getHeaders()['content-type'][0] ?? '',
            "Captcha image response 'content-type' should be 'image/png' for '{$url}' in 'StatelessApplication'.",
        );

        $imageContent = $response3->getBody()->getContents();

        self::assertNotEmpty(
            $imageContent,
            "Captcha image content should not be empty for '{$url}' in 'StatelessApplication'.",
        );
        self::assertSame(
            'image/png',
            $response3->getHeaders()['content-type'][0],
            "Captcha image response 'content-type' should be 'image/png' for '{$url}' in 'StatelessApplication'.",
        );

        self::assertSame(
            "{$sessionName}=user-a-session; Path=/; HttpOnly; SameSite",
            $response3->getHeaders()['Set-Cookie'][0] ?? '',
            "Captcha image response 'Set-Cookie' should contain 'user-a-session' for '{$url}' in " .
            "'StatelessApplication'.",
        );
        self::assertStringStartsWith(
            "\x89PNG",
            $imageContent,
            "Captcha image content should start with PNG header for '{$url}' in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException
     */
    public function testGetMemoryLimitHandlesUnlimitedMemoryCorrectly(): void
    {
        $originalLimit = ini_get('memory_limit');

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

        ini_set('memory_limit', $originalLimit);
    }

    public function testMultipleRequestsWithDifferentSessionsInWorkerMode(): void
    {
        $sessionName = session_name();

        $app = $this->statelessApplication();

        $sessions = [];

        for ($i = 1; $i <= 3; $i++) {
            $sessionId = "worker-session-{$i}";
            $_COOKIE = [$sessionName => $sessionId];
            $_POST = ['data' => "user-{$i}-data"];
            $_SERVER = [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => 'site/setsessiondata',
            ];

            $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

            $app->handle($request);

            $sessions[] = $sessionId;
        }

        foreach ($sessions as $index => $sessionId) {
            $_COOKIE = [$sessionName => $sessionId];
            $_POST = [];
            $_SERVER = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => 'site/getsessiondata',
            ];

            $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

            $response = $app->handle($request);
            $data = Json::decode($response->getBody()->getContents());

            $expectedData = 'user-' . ($index + 1) . '-data';

            self::assertIsArray(
                $data,
                sprintf(
                    "Response body should be an array after decoding JSON for session '%s' in multiple requests with " .
                    'different sessions in worker mode.',
                    $sessionId,
                ),
            );
            self::assertSame(
                $expectedData,
                $data['data'] ?? null,
                sprintf(
                    "Session '%s' should return its own data ('%s') and not leak data between sessions in  multiple " .
                    'requests with different sessions in worker mode.',
                    $sessionId,
                    $expectedData,
                ),
            );
        }
    }

    public function testRecalculateMemoryLimitAfterResetAndIniChange(): void
    {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', '256M');

        $app = $this->statelessApplication();

        $firstCalculation = $app->getMemoryLimit();
        $app->setMemoryLimit(0);

        ini_set('memory_limit', '128M');

        $secondCalculation = $app->getMemoryLimit();

        self::assertSame(
            134_217_728,
            $secondCalculation,
            "'getMemoryLimit()' should return '134_217_728' ('128M') after resetting and updating 'memory_limit' to " .
            "'128M' in 'StatelessApplication'.",
        );
        self::assertNotSame(
            $firstCalculation,
            $secondCalculation,
            "'getMemoryLimit()' should return a different value after recalculation when 'memory_limit' changes in " .
            "'StatelessApplication'.",
        );

        ini_set('memory_limit', $originalLimit);
    }

    /**
     * @throws InvalidConfigException
     */
    public function testReturnCookiesHeadersForSiteCookieRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/cookie',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response = $app->handle($request);

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response status code should be '200' for 'site/cookie' route in 'StatelessApplication'.",
        );

        $cookies = $response->getHeaders()['set-cookie'] ?? [];

        foreach ($cookies as $cookie) {
            // skip the session cookie header
            if (str_starts_with($cookie, $app->session->getName()) === false) {
                $params = explode('; ', $cookie);

                self::assertContains(
                    $params[0],
                    [
                        'test=test',
                        'test2=test2',
                    ],
                    sprintf(
                        "Cookie header should contain either 'test=test' or 'test2=test2', got '%s' for 'site/cookie' " .
                        'route.',
                        $params[0],
                    ),
                );
            }
        }
    }

    /**
     * @throws InvalidConfigException
     */
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

    /**
     * @throws InvalidConfigException
     */
    public function testReturnFalseFromCleanWhenMemoryUsageIsBelowThreshold(): void
    {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', '1G');

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $app->handle($request);

        self::assertFalse(
            $app->clean(),
            "'clean()' should return 'false' when memory usage is below '90%' of the configured 'memory_limit' in " .
            "'StatelessApplication'.",
        );

        ini_set('memory_limit', $originalLimit);
    }

    /**
     * @throws InvalidConfigException
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

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

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

    /**
     * @throws InvalidConfigException
     */
    public function testReturnJsonResponseWithCredentialsForSiteAuthRoute(): void
    {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('admin:admin'),
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/auth',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

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

    /**
     * @throws InvalidConfigException
     */
    public function testReturnJsonResponseWithNullCredentialsForMalformedAuthorizationHeader(): void
    {
        $_SERVER = [
            'HTTP_authorization' => 'Basic foo:bar',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/auth',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

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

    /**
     * @throws InvalidConfigException
     */
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

    /**
     * @throws InvalidConfigException
     */
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

    /**
     * @throws InvalidConfigException
     */
    public function testReturnPhpIntMaxWhenMemoryLimitIsUnlimited(): void
    {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', '-1');

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $app->handle($request);

        self::assertSame(
            PHP_INT_MAX,
            $app->getMemoryLimit(),
            "'getMemoryLimit()' should return 'PHP_INT_MAX' when 'memory_limit' is set to '-1' (unlimited) in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            PHP_INT_MAX,
            $app->getMemoryLimit(),
            "'getMemoryLimit()' should remain 'PHP_INT_MAX' after handling a request with unlimited memory in " .
            "'StatelessApplication'.",
        );

        ini_set('memory_limit', $originalLimit);
    }

    /**
     * @throws InvalidConfigException
     */
    public function testReturnPlainTextFileResponseForSiteFileRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/file',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

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

    /**
     * @throws InvalidConfigException
     */
    public function testReturnPlainTextResponseWithFileContentForSiteStreamRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/stream',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

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

    /**
     * @throws InvalidConfigException
     */
    public function testReturnRedirectResponseForSiteRedirectRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/redirect',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

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

    /**
     * @throws InvalidConfigException
     */
    public function testReturnRedirectResponseForSiteRefreshRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/refresh',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

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

    /**
     * @throws InvalidConfigException
     */
    public function testReturnsJsonResponse(): void
    {
        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

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

    /**
     * @throws InvalidConfigException
     */
    public function testReturnsStatusCode201ForSiteStatusCodeRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/statuscode',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response = $this->statelessApplication()->handle($request);

        self::assertSame(
            201,
            $response->getStatusCode(),
            "Response status code should be '201' for 'site/statuscode' route in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException
     */
    public function testSessionDataPersistenceWithSameSessionId(): void
    {
        $sessionName = session_name();
        $sessionId = 'test-session-' . uniqid();

        $_COOKIE = [$sessionName => $sessionId];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/setsession',
        ];

        // first request - set session data
        $request1 = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response1 = $app->handle($request1);

        self::assertSame(
            200,
            $response1->getStatusCode(),
            "Response status code should be '200' for 'site/setsession' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response1->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'application/json; charset=UTF-8' for 'site/setsession' route in " .
            "'StatelessApplication'.",
        );

        self::assertSame(
            "{$sessionName}={$sessionId}; Path=/; HttpOnly; SameSite",
            $response1->getHeaders()['Set-Cookie'][0] ?? '',
            "Response 'Set-Cookie' header should contain '{$sessionId}' for 'site/setsession' route in " .
            "'StatelessApplication'.",
        );

        $_COOKIE = [$sessionName => $sessionId];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/getsession',
        ];

        // second request - same session ID should retrieve the data
        $request2 = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response2 = $app->handle($request2);

        self::assertSame(
            200,
            $response2->getStatusCode(),
            "Response status code should be '200' for 'site/getsession' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response2->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'application/json; charset=UTF-8' for 'site/getsession' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            "{$sessionName}={$sessionId}; Path=/; HttpOnly; SameSite",
            $response2->getHeaders()['Set-Cookie'][0] ?? '',
            "Response 'Set-Cookie' header should contain '{$sessionId}' for 'site/getsession' route in " .
            "'StatelessApplication'.",
        );

        $body = Json::decode($response2->getBody()->getContents());

        self::assertIsArray(
            $body,
            "Response body should be an array after decoding JSON response from 'site/getsession' route in " .
            "'StatelessApplication'.",
        );

        $testValue = '';

        if (array_key_exists('testValue', $body)) {
            $testValue = $body['testValue'];
        }

        self::assertSame(
            'test-value',
            $testValue,
            'Session data should persist between requests with the same session ID',
        );
    }

    /**
     * @throws InvalidConfigException
     */
    public function testSessionIsolationBetweenRequests(): void
    {
        $sessionName = session_name();

        $_COOKIE = [$sessionName => 'session-user-a'];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/setsession',
        ];

        // first request - set a session value
        $request1 = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response1 = $app->handle($request1);

        self::assertSame(
            200,
            $response1->getStatusCode(),
            "Response status code should be '200' for 'site/setsession' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response1->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'application/json; charset=UTF-8' for 'site/setsession' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            "{$sessionName}=session-user-a; Path=/; HttpOnly; SameSite",
            $response1->getHeaders()['Set-Cookie'][0] ?? '',
            "Response 'Set-Cookie' header should contain 'session-user-a' for 'site/setsession' route in " .
            "'StatelessApplication'.",
        );

        $_COOKIE = [$sessionName => 'session-user-b'];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/getsession',
        ];

        // second request - different session
        $request2 = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response2 = $app->handle($request2);

        self::assertSame(
            200,
            $response2->getStatusCode(),
            "Response status code should be '200' for 'site/getsession' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response2->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'application/json; charset=UTF-8' for 'site/getsession' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            "{$sessionName}=session-user-b; Path=/; HttpOnly; SameSite",
            $response2->getHeaders()['Set-Cookie'][0] ?? '',
            "Response 'Set-Cookie' header should contain 'session-user-b' for 'site/getsession' route in " .
            "'StatelessApplication'.",
        );

        $body = Json::decode($response2->getBody()->getContents());

        self::assertIsArray(
            $body,
            "Response body should be an array after decoding JSON response from 'site/getsession' route in " .
            "'StatelessApplication'.",
        );

        $testValue = '';

        if (array_key_exists('testValue', $body)) {
            $testValue = $body['testValue'];
        }

        self::assertNull(
            $testValue,
            "Session data from first request should not leak to second request with different session 'ID' in " .
            "'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException
     */
    public function testSessionWithoutCookieCreatesNewSession(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/getsession',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response = $app->handle($request);
        $cookies = $response->getHeaders()['Set-Cookie'] ?? [];
        $sessionName = $app->session->getName();
        $cookie = array_filter(
            $cookies,
            static fn(string $cookie): bool => str_starts_with($cookie, "{$sessionName}="),
        );

        self::assertCount(
            1,
            $cookie,
            "Response 'Set-Cookie' header should contain exactly one '{$sessionName}' cookie when no session cookie " .
            "is sent in 'StatelessApplication'.",
        );
        self::assertMatchesRegularExpression(
            '/^' . preg_quote($sessionName, '/') . '=[a-zA-Z0-9]+; Path=\/; HttpOnly; SameSite$/',
            $cookie[0] ?? '',
            "Response 'Set-Cookie' header should match the expected format for a new session 'ID' when no session " .
            "cookie is sent in 'StatelessApplication'. Value received: '" . ($cookie[0] ?? '') . "'.",
        );
    }

    /**
     * @throws InvalidConfigException
     */
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

    /**
     * @throws InvalidConfigException
     */
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

    /**
     * @throws InvalidConfigException
     */
    public function testUserAuthenticationSessionIsolation(): void
    {
        $sessionName = session_name();

        // first user logs in
        $_COOKIE = [$sessionName => 'user1-session'];
        $_POST = [
            'username' => 'admin',
            'password' => 'admin',
        ];
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => 'site/login',
        ];

        $request1 = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response1 = $app->handle($request1);

        self::assertSame(
            200,
            $response1->getStatusCode(),
            "Response status code should be '200' for 'site/login' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response1->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'application/json; charset=UTF-8' for 'site/login' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            "{$sessionName}=user1-session; Path=/; HttpOnly; SameSite",
            $response1->getHeaders()['Set-Cookie'][0] ?? '',
            "Response 'Set-Cookie' header should contain 'user1-session' for 'site/login' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            <<<JSON
            {"status":"ok","username":"admin"}
            JSON,
            $response1->getBody()->getContents(),
            "Response body should contain valid JSON with 'status' and 'username' for successful login in " .
            "'StatelessApplication'.",
        );

        // second user checks authentication status - should not be logged in
        $_COOKIE = [$sessionName => 'user2-session'];
        $_POST = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/checkauth',
        ];

        $request2 = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response2 = $app->handle($request2);

        self::assertSame(
            200,
            $response2->getStatusCode(),
            "Response status code should be '200' for 'site/checkauth' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response2->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'application/json; charset=UTF-8' for 'site/checkauth' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            "{$sessionName}=user2-session; Path=/; HttpOnly; SameSite",
            $response2->getHeaders()['Set-Cookie'][0] ?? '',
            "Response 'Set-Cookie' header should contain 'user2-session' for 'site/checkauth' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            <<<JSON
            {"isGuest":true,"identity":null}
            JSON,
            $response2->getBody()->getContents(),
            "Response body should indicate 'guest' status and 'null' identity for a new session in " .
            "'StatelessApplication'.",
        );
    }
}
