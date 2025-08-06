<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use HttpSoft\Message\{ServerRequestFactory, StreamFactory, UploadedFileFactory};
use PHPForge\Support\Assert;
use PHPUnit\Framework\Attributes\{DataProviderExternal, Group, RequiresPhpExtension};
use Psr\Http\Message\{ServerRequestFactoryInterface, StreamFactoryInterface, UploadedFileFactoryInterface};
use stdClass;
use Yii;
use yii\base\{Exception, InvalidConfigException, Security};
use yii\di\NotInstantiableException;
use yii\helpers\Json;
use yii\i18n\{Formatter, I18N};
use yii\log\Dispatcher;
use yii\web\{AssetManager, Session, UrlManager, User, View};
use yii\web\NotFoundHttpException;
use yii2\extensions\psrbridge\exception\Message;
use yii2\extensions\psrbridge\http\{ErrorHandler, Request, Response};
use yii2\extensions\psrbridge\tests\provider\StatelessApplicationProvider;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\support\stub\HTTPFunctions;
use yii2\extensions\psrbridge\tests\TestCase;

use function array_fill;
use function array_filter;
use function array_key_exists;
use function base64_encode;
use function end;
use function explode;
use function gc_disable;
use function gc_enable;
use function gc_status;
use function ini_get;
use function ini_set;
use function memory_get_usage;
use function ob_get_level;
use function ob_start;
use function preg_quote;
use function session_name;
use function sprintf;
use function str_starts_with;
use function uniqid;

use const PHP_INT_MAX;

#[Group('http')]
final class StatelessApplicationTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->closeApplication();

        HTTPFunctions::reset();

        parent::tearDown();
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
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
            200,
            $response1->getStatusCode(),
            "Response 'status code' should be '200' for 'site/captcha' route, confirming successful captcha " .
            "generation in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response1->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'application/json; charset=UTF-8' for 'site/captcha' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            "{$sessionName}=user-a-session; Path=/; HttpOnly; SameSite",
            $response1->getHeaders()['Set-Cookie'][0] ?? '',
            "Response 'Set-Cookie' header should contain session 'ID' 'user-a-session' for 'site/captcha' route, " .
                "ensuring correct session assignment in 'StatelessApplication'.",
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

        self::assertSame(
            200,
            $response2->getStatusCode(),
            "Response 'status code' should be '200' for 'site/captcha' route, confirming successful captcha " .
            "generation for second user in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response2->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'application/json; charset=UTF-8' for 'site/captcha' route for " .
                "second user in 'StatelessApplication', confirming correct content type for JSON captcha response.",
        );
        self::assertSame(
            "{$sessionName}=user-b-session; Path=/; HttpOnly; SameSite",
            $response2->getHeaders()['Set-Cookie'][0] ?? '',
            "Response 'Set-Cookie' header should contain session 'ID' 'user-b-session' for 'site/captcha' route, " .
            "ensuring correct session assignment for second user in 'StatelessApplication'.",
        );

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

        self::assertIsString(
            $url,
            "Captcha response 'url' should be a string for second user in 'site/captcha' route in " .
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
        $imageContent = $response3->getBody()->getContents();

        self::assertSame(
            200,
            $response3->getStatusCode(),
            "Response 'status code' should be '200' for captcha image request for user-a-session in " .
                "'StatelessApplication', confirming successful image retrieval and session isolation.",
        );
        self::assertNotEmpty(
            $imageContent,
            "Captcha image content should not be empty for '{$url}' in 'StatelessApplication'.",
        );
        self::assertSame(
            'image/png',
            $response3->getHeaders()['content-type'][0] ?? '',
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
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testCleanCalculatesCorrectMemoryThresholdWith90Percent(): void
    {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', '100M');

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $app->handle($request);
        $memoryLimit = $app->getMemoryLimit();

        self::assertFalse(
            $app->clean(),
            "'clean()' should return 'false' when memory usage is below the '90%' threshold of the configured memory " .
            "limit ('100M'), confirming that no cleanup is needed in 'StatelessApplication'.",
        );
        self::assertSame(
            104_857_600,
            $memoryLimit,
            "Memory limit should be exactly '104_857_600' bytes ('100M') for threshold calculation test in" .
            "'StatelessApplication'.",
        );
        self::assertSame(
            94_371_840.0,
            $memoryLimit * 0.9,
            "'90%' of '100M' should be exactly '94_371_840' bytes, not a division result like '116_508_444' bytes " .
            "('100M' / '0.9') in 'StatelessApplication'.",
        );

        ini_set('memory_limit', $originalLimit);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testCleanReturnsTrueWhenMemoryUsageExactlyEqualsThreshold(): void
    {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', '2G');

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $app->handle($request);

        $currentUsage = memory_get_usage(true);

        $artificialLimit = (int) ($currentUsage / 0.9);

        $app->setMemoryLimit($artificialLimit);
        $memoryLimit = $app->getMemoryLimit();

        self::assertTrue(
            $app->clean(),
            "'clean()' should return 'true' when memory usage is exactly at or above '90%' threshold ('>=' operator)" .
            ", not only when strictly greater than ('>' operator) in 'StatelessApplication'.",
        );
        self::assertSame(
            $artificialLimit,
            $memoryLimit,
            "Memory limit should be set to the artificial limit '{$artificialLimit}' for threshold calculation test " .
            "in 'StatelessApplication'.",
        );

        ini_set('memory_limit', $originalLimit);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testCleanTriggersGarbageCollectionAndReducesMemoryUsage(): void
    {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', '512M');

        gc_disable();

        $app = $this->statelessApplication();

        for ($i = 0; $i < 25; $i++) {
            $circular = new stdClass();

            $circular->self = $circular;

            $circular->data = array_fill(0, 100, "data-{$i}");

            $_SERVER = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => "site/index?iteration={$i}",
            ];

            $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

            $response = $app->handle($request);

            $obj1 = new stdClass();
            $obj2 = new stdClass();

            $obj1->ref = $obj2;
            $obj2->ref = $obj1;
            $obj1->circular = $circular;

            unset($circular, $obj1, $obj2, $request, $response);
        }

        $gcStatsBefore = gc_status();
        $memoryBefore = memory_get_usage(true);
        $app->clean();
        $memoryAfter = memory_get_usage(true);
        $gcStatsAfter = gc_status();

        gc_enable();

        $cyclesCollected = $gcStatsAfter['collected'] - $gcStatsBefore['collected'];

        self::assertGreaterThan(
            0,
            $cyclesCollected,
            "'clean()' should trigger garbage collection that collects circular references, but no cycles were " .
            "collected. This indicates 'gc_collect_cycles()' was not called in 'StatelessApplication'.",
        );

        $memoryDifference = $memoryAfter - $memoryBefore;

        self::assertLessThanOrEqual(
            1_048_576,
            $memoryDifference,
            "'clean()' should reduce memory usage through garbage collection. Memory increased by {$memoryDifference}" .
            " bytes, suggesting 'gc_collect_cycles()' was not called in 'StatelessApplication'.",
        );

        ini_set('memory_limit', $originalLimit);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testClearOutputCleansLocalBuffers(): void
    {
        $levels = [];

        ob_start();
        $levels[] = ob_get_level();
        ob_start();
        $levels[] = ob_get_level();
        ob_start();
        $levels[] = ob_get_level();

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $app->handle($request);

        self::assertGreaterThanOrEqual(
            3,
            end($levels),
            'Should have at least 3 output buffer levels before clearing output.',
        );

        $app->errorHandler->clearOutput();

        $closed = true;

        foreach ($levels as $level) {
            $closed = $closed && (ob_get_level() < $level);
        }

        self::assertTrue(
            $closed,
            "Should close all local output buffers after calling 'clearOutput()'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws NotInstantiableException if a class or service can't be instantiated.
     */
    public function testContainerResolvesPsrFactoriesWithDefinitions(): void
    {
        $app = $this->statelessApplication([
            'container' => [
                'definitions' => [
                    ServerRequestFactoryInterface::class => ServerRequestFactory::class,
                    StreamFactoryInterface::class => StreamFactory::class,
                    UploadedFileFactoryInterface::class => UploadedFileFactory::class,
                ],
            ],
        ]);

        $container = $app->container();

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertTrue(
            $container->has(ServerRequestFactoryInterface::class),
            "Container should have definition for 'ServerRequestFactoryInterface', ensuring PSR-7 request factory is " .
            'available.',
        );
        self::assertTrue(
            $container->has(StreamFactoryInterface::class),
            "Container should have definition for 'StreamFactoryInterface', ensuring PSR-7 stream factory is " .
            'available.',
        );
        self::assertTrue(
            $container->has(UploadedFileFactoryInterface::class),
            "Container should have definition for 'UploadedFileFactoryInterface', ensuring PSR-7 uploaded file " .
            'factory is available.',
        );
        self::assertInstanceOf(
            ServerRequestFactory::class,
            $container->get(ServerRequestFactoryInterface::class),
            "Container should resolve 'ServerRequestFactoryInterface' to an instance of 'ServerRequestFactory'.",
        );
        self::assertInstanceOf(
            StreamFactory::class,
            $container->get(StreamFactoryInterface::class),
            "Container should resolve 'StreamFactoryInterface' to an instance of 'StreamFactory'.",
        );
        self::assertInstanceOf(
            UploadedFileFactory::class,
            $container->get(UploadedFileFactoryInterface::class),
            "Container should resolve 'UploadedFileFactoryInterface' to an instance of 'UploadedFileFactory'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFlashMessagesIsolationBetweenSessions(): void
    {
        $sessionName = session_name();

        // first user sets a flash message
        $_COOKIE = [$sessionName => 'flash-user-a'];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/setflash',
        ];

        $request1 = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response1 = $app->handle($request1);
        $sessionName = $app->session->getName();

        self::assertSame(
            200,
            $response1->getStatusCode(),
            "Response 'status code' should be '200' for 'site/setflash' route, confirming successful flash message " .
            "set in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response1->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'application/json; charset=UTF-8' for 'site/captcha' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            '{"status":"ok"}',
            $response1->getBody()->getContents(),
            "Response 'body' should be '{\"status\":\"ok\"}' after setting flash message for 'site/setflash' route " .
            "in 'StatelessApplication'.",
        );
        self::assertSame(
            "{$sessionName}=flash-user-a; Path=/; HttpOnly; SameSite",
            $response1->getHeaders()['Set-Cookie'][0] ?? '',
            "Response 'Set-Cookie' header should contain session 'ID' 'flash-user-a' for 'site/setflash' route, " .
            "ensuring correct session assignment in 'StatelessApplication'.",
        );

        // second user checks for flash messages
        $_COOKIE = [$sessionName => 'flash-user-b'];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/getflash',
        ];

        $request2 = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $response2 = $app->handle($request2);

        $flashData = Json::decode($response2->getBody()->getContents());

        self::assertSame(
            200,
            $response2->getStatusCode(),
            "Response 'status code' should be '200' for 'site/getflash' route, confirming successful flash message " .
            "retrieval in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response2->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'application/json; charset=UTF-8' for 'site/getflash' route in " .
                "'StatelessApplication'.",
        );
        self::assertSame(
            "{$sessionName}=flash-user-b; Path=/; HttpOnly; SameSite",
            $response2->getHeaders()['Set-Cookie'][0] ?? '',
            "Response 'Set-Cookie' header should contain session 'ID' 'flash-user-b' for 'site/getflash' route, " .
            "ensuring correct session assignment in 'StatelessApplication'.",
        );
        self::assertIsArray(
            $flashData,
            "Flash message response should be an array after decoding JSON for 'site/getflash' route in " .
            "'StatelessApplication'.",
        );
        self::assertEmpty(
            $flashData['flash'] ?? [],
            "Flash message array should be empty for new session 'flash-user-b', confirming session isolation in " .
            "'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
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

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
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

            $response = $app->handle($request);

            self::assertSame(
                200,
                $response->getStatusCode(),
                "Response 'status code' should be '200' for 'site/setsessiondata' route in 'StatelessApplication', " .
                'confirming successful session data set in worker mode.',
            );
            self::assertSame(
                'application/json; charset=UTF-8',
                $response->getHeaders()['content-type'][0] ?? '',
                "Response 'content-type' should be 'application/json; charset=UTF-8' for 'site/setsessiondata' route " .
                "in 'StatelessApplication', confirming correct content type for JSON session data response in worker mode.",
            );
            self::assertSame(
                "{$sessionName}={$sessionId}; Path=/; HttpOnly; SameSite",
                $response->getHeaders()['Set-Cookie'][0] ?? '',
                "Response 'Set-Cookie' header should contain session 'ID' '{$sessionId}' for 'site/setsessiondata' " .
                "route in 'StatelessApplication', ensuring correct session assignment in worker mode.",
            );

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

            self::assertSame(
                200,
                $response->getStatusCode(),
                sprintf(
                    "Response 'status code' should be '200' for 'site/getsessiondata' route in 'StatelessApplication'" .
                    ", confirming successful session data retrieval for session '%s' in worker mode.",
                    $sessionId,
                ),
            );
            self::assertSame(
                'application/json; charset=UTF-8',
                $response->getHeaders()['content-type'][0] ?? '',
                sprintf(
                    "Response 'content-type' should be 'application/json; charset=UTF-8' for 'site/getsessiondata' " .
                    "route in 'StatelessApplication', confirming correct content type for JSON session data response " .
                    "for session '%s' in worker mode.",
                    $sessionId,
                ),
            );
            self::assertSame(
                "{$sessionName}={$sessionId}; Path=/; HttpOnly; SameSite",
                $response->getHeaders()['Set-Cookie'][0] ?? '',
                sprintf(
                    "Response 'Set-Cookie' header should contain session 'ID' '%s' for 'site/getsessiondata' route " .
                    "in 'StatelessApplication', ensuring correct session assignment for session '%s' in worker mode.",
                    $sessionId,
                    $sessionId,
                ),
            );

            $data = Json::decode($response->getBody()->getContents());

            $expectedData = 'user-' . ($index + 1) . '-data';

            self::assertIsArray(
                $data,
                sprintf(
                    "Response 'body' should be an array after decoding JSON for session '%s' in multiple requests " .
                    'with different sessions in worker mode.',
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

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testRecalculateMemoryLimitAfterResetAndIniChange(): void
    {
        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', '256M');

        $app = $this->statelessApplication();

        $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

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
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[RequiresPhpExtension('runkit7')]
    public function testRenderExceptionSetsDisplayErrorsInDebugMode(): void
    {
        @runkit_constant_redefine('YII_ENV_TEST', false);

        $initialBufferLevel = ob_get_level();

        HTTPFunctions::set_sapi('apache2handler');

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/trigger-exception',
        ];

        $originalDisplayErrors = ini_get('display_errors');

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication([
            'components' => [
                'errorHandler' => [
                    'errorAction' => null,
                ],
            ],
        ]);

        $response = $app->handle($request);

        self::assertSame(
            '1',
            ini_get('display_errors'),
            "'display_errors' should be set to '1' when 'YII_DEBUG' is 'true' and rendering exception view.",
        );
        self::assertSame(
            500,
            $response->getStatusCode(),
            "Response 'status code' should be '500' for exception.",
        );
        self::assertStringContainsString(
            'yii\base\Exception: Exception error message.',
            $response->getBody()->getContents(),
            "Response should contain exception details when 'YII_DEBUG' is 'true'.",
        );

        ini_set('display_errors', $originalDisplayErrors);

        while (ob_get_level() < $initialBufferLevel) {
            ob_start();
        }

        @runkit_constant_redefine('YII_ENV_TEST', true);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[RequiresPhpExtension('runkit7')]
    public function testRenderExceptionWithErrorActionReturningResponseObject(): void
    {
        @runkit_constant_redefine('YII_DEBUG', false);

        HTTPFunctions::set_sapi('apache2handler');

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/trigger-exception',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication(
            [
                'components' => [
                    'errorHandler' => [
                        'errorAction' => 'site/error-with-response',
                    ],
                ],
            ],
        );

        $response = $app->handle($request);

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Response 'status code' should be '500' when 'errorAction' returns Response object.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'text/html; charset=UTF-8' when 'errorAction' returns Response object.",
        );
        Assert::equalsWithoutLE(
            <<<HTML
            <div id="custom-response-error">
            Custom Response object from error action: Exception error message.
            </div>
            HTML,
            $response->getBody()->getContents(),
            "Response 'body' should contain content from Response object returned by 'errorAction'.",
        );

        @runkit_constant_redefine('YII_DEBUG', true);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testRenderExceptionWithRawFormat(): void
    {
        HTTPFunctions::set_sapi('apache2handler');

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/trigger-exception',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication(
            [
                'components' => [
                    'response' => [
                        'format' => Response::FORMAT_RAW,
                    ],
                    'errorHandler' => [
                        'errorAction' => null,
                    ],
                ],
            ],
        );

        $response = $app->handle($request);

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Response 'status code' should be '500' for exception with RAW format.",
        );

        $body = $response->getBody()->getContents();

        self::assertStringContainsString(
            Exception::class,
            $body,
            'RAW format response should contain exception class name.',
        );
        self::assertStringContainsString(
            'Exception error message.',
            $body,
            'RAW format response should contain exception message.',
        );
        self::assertStringNotContainsString(
            '<pre>',
            $body,
            'RAW format response should not contain HTML tags.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
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
            "Response 'status code' should be '200' for 'site/cookie' route in 'StatelessApplication'.",
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
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
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
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
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
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnHtmlErrorResponseWhenErrorHandlerActionIsInvalid(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/nonexistent-action',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication(
            [
                'components' => [
                    'errorHandler' => [
                        'errorAction' => 'invalid/nonexistent-action',
                    ],
                ],
            ],
        );

        $response = $app->handle($request);

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' when 'ErrorHandler' is misconfigured and a nonexistent action is " .
            "requested in 'StatelessApplication'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'text/html; charset=UTF-8' for error response when ErrorHandler " .
            "action is invalid in 'StatelessApplication'.",
        );
        self::assertStringContainsString(
            <<<HTML
            <pre>An Error occurred while handling another error:
            yii\base\InvalidRouteException: Unable to resolve the request &quot;invalid/nonexistent-action&quot;.
            HTML,
            $response->getBody()->getContents(),
            "Response 'body' should contain error message about 'An Error occurred while handling another error' and " .
            "the InvalidRouteException when errorHandler action is invalid in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
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

        $app = $this->statelessApplication();

        $response = $app->handle($request);

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/getcookies' route in 'StatelessApplication'.",
        );
        self::assertSame(
            <<<JSON
            {"test":{"name":"test","value":"test","domain":"","expire":null,"path":"/","secure":false,"httpOnly":true,"sameSite":"Lax"}}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should match expected JSON string for cookie 'test' on 'site/getcookies' route in " .
            "'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnJsonResponseWithCredentialsForSiteAuthRoute(): void
    {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('admin:admin'),
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/auth',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response = $app->handle($request);

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/auth' route in 'StatelessApplication'.",
        );
        self::assertSame(
            <<<JSON
            {"username":"admin","password":"admin"}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should match expected JSON string '{\"username\":\"admin\",\"password\":\"admin\"}' " .
            "for 'site/auth' route in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnJsonResponseWithNullCredentialsForMalformedAuthorizationHeader(): void
    {
        $_SERVER = [
            'HTTP_authorization' => 'Basic foo:bar',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/auth',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response = $app->handle($request);

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/auth' route with malformed authorization header in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            <<<JSON
            {"username":null,"password":null}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should match expected JSON string '{\"username\":null,\"password\":null}' for malformed " .
            "authorization header on 'site/auth' route in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
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

        $app = $this->statelessApplication();

        $response = $app->handle($request);

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/post' route in 'StatelessApplication'.",
        );
        self::assertSame(
            <<<JSON
            {"foo":"bar","a":{"b":"c"}}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should match expected JSON string '{\"foo\":\"bar\",\"a\":{\"b\":\"c\"}}' for " .
            "'site/post' route in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
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

        $app = $this->statelessApplication();

        $response = $app->handle($request);

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/get' route in 'StatelessApplication'.",
        );
        self::assertSame(
            <<<JSON
            {"foo":"bar","a":{"b":"c"}}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should match expected JSON string '{\"foo\":\"bar\",\"a\":{\"b\":\"c\"}}' for " .
            "'site/get' route in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnJsonResponseWithRouteParameterForSiteUpdateRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/update/123',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response = $app->handle($request);

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/update/123' route in 'StatelessApplication', " .
            'indicating a successful update.',
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'content-type' should be 'application/json; charset=UTF-8' for 'site/update/123' route in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            '{"site/update":"123"}',
            $response->getBody()->getContents(),
            "Response 'body' should contain valid JSON with the route parameter for 'site/update/123' in " .
            "'StatelessApplication'.",
        );
        self::assertSame(
            'site/update/123',
            $request->getUri()->getPath(),
            "Request 'path' should be 'site/update/123' for 'site/update/123' route in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
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
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnPlainTextFileResponseForSiteFileRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/file',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response = $app->handle($request);

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/file' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'text/plain',
            $response->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'text/plain' for 'site/file' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'This is a test file content.',
            $response->getBody()->getContents(),
            "Response 'body' should match expected plain text 'This is a test file content.' for 'site/file' route " .
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
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnPlainTextResponseWithFileContentForSiteStreamRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/stream',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response = $app->handle($request);

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/stream' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'text/plain',
            $response->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'text/plain' for 'site/stream' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'This is a test file content.',
            $response->getBody()->getContents(),
            "Response 'body' should match expected plain text 'This is a test file content.' for 'site/stream' route " .
            "in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnRedirectResponseForSiteRedirectRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/redirect',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response = $app->handle($request);

        self::assertSame(
            302,
            $response->getStatusCode(),
            "Response 'status code' should be '302' for redirect route 'site/redirect' in 'StatelessApplication'.",
        );
        self::assertSame(
            '/site/index',
            $response->getHeaders()['location'][0] ?? '',
            "Response 'location' header should be '/site/index' for redirect route 'site/redirect' in " .
            "'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnRedirectResponseForSiteRefreshRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/refresh',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response = $app->handle($request);

        self::assertSame(
            302,
            $response->getStatusCode(),
            "Response 'status code' should be '302' for redirect route 'site/refresh' in 'StatelessApplication'.",
        );
        self::assertSame(
            'site/refresh#stateless',
            $response->getHeaders()['location'][0] ?? '',
            "Response 'location' header should be 'site/refresh#stateless' for redirect route 'site/refresh' in " .
            "'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnsJsonResponse(): void
    {
        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response = $app->handle($request);

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for successful 'StatelessApplication' handling.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'application/json; charset=UTF-8' for JSON output.",
        );
        self::assertSame(
            <<<JSON
            {"hello":"world"}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should match expected JSON string '{\"hello\":\"world\"}'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnsStatusCode201ForSiteStatusCodeRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/statuscode',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response = $app->handle($request);

        self::assertSame(
            201,
            $response->getStatusCode(),
            "Response 'status code' should be '201' for 'site/statuscode' route in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
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
            "Response 'status code' should be '200' for 'site/setsession' route in 'StatelessApplication'.",
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
            "Response 'status code' should be '200' for 'site/getsession' route in 'StatelessApplication'.",
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
            "Response 'body' should be an array after decoding JSON response from 'site/getsession' route in " .
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
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
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
            "Response 'status code' should be '200' for 'site/setsession' route in 'StatelessApplication'.",
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
            "Response 'status code' should be '200' for 'site/getsession' route in 'StatelessApplication'.",
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
            "Response 'body' should be an array after decoding JSON response from 'site/getsession' route in " .
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
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
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
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testSetMemoryLimitWithLargePositiveValueMaintainsValue(): void
    {
        $largeLimit = 2_147_483_647; // Near PHP_INT_MAX

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $app->handle($request);
        $app->setMemoryLimit($largeLimit);

        self::assertSame(
            $largeLimit,
            $app->getMemoryLimit(),
            'Memory limit should handle large positive values correctly without overflow.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testSetMemoryLimitWithPositiveValueDisablesRecalculation(): void
    {
        $customLimit = 134_217_728; // 128MB in bytes

        $originalLimit = ini_get('memory_limit');

        ini_set('memory_limit', '512M');

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $app->handle($request);
        $app->setMemoryLimit($customLimit);

        ini_set('memory_limit', '1G');

        self::assertSame(
            $customLimit,
            $app->getMemoryLimit(),
            'Memory limit should remain unchanged when recalculation is disabled after setting positive value.',
        );

        ini_set('memory_limit', $originalLimit);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testSetMemoryLimitWithPositiveValueOverridesSystemRecalculation(): void
    {
        $originalLimit = ini_get('memory_limit');

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $app->handle($request);
        $app->setMemoryLimit(0);

        ini_set('memory_limit', '256M');

        $systemBasedLimit = $app->getMemoryLimit();

        $customLimit = 104_857_600; // 100MB in bytes (different from system)

        $app->setMemoryLimit($customLimit);

        ini_set('memory_limit', '512M');

        self::assertSame(
            $customLimit,
            $app->getMemoryLimit(),
            'Memory limit should maintain custom value and ignore system changes when set to positive value.',
        );

        self::assertNotSame(
            $systemBasedLimit,
            $app->getMemoryLimit(),
            'Memory limit should override system-based calculation when positive value is set.',
        );

        ini_set('memory_limit', $originalLimit);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testSetMemoryLimitWithPositiveValueSetsLimitDirectly(): void
    {
        $memoryLimit = 268_435_456; // 256MB in bytes

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $app->setMemoryLimit($memoryLimit);
        $app->handle($request);

        self::assertSame(
            $memoryLimit,
            $app->getMemoryLimit(),
            'Memory limit should be set to the exact value when a positive limit is provided.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testSetMemoryLimitWithSmallPositiveValueSetsCorrectly(): void
    {
        $smallLimit = 1024; // 1KB

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $app->handle($request);
        $app->setMemoryLimit($smallLimit);

        self::assertSame(
            $smallLimit,
            $app->getMemoryLimit(),
            'Memory limit should handle small positive values correctly.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
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
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testThrowableOccursDuringRequestHandling(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'nonexistent/invalidaction',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response = $app->handle($request);

        self::assertSame(
            404,
            $response->getStatusCode(),
            "Response 'status code' should be '404' when handling a request to 'non-existent' route in " .
            "'StatelessApplication', confirming proper error handling in catch block.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'text/html; charset=UTF-8' for error response when 'Throwable' occurs " .
            "during request handling in 'StatelessApplication'.",
        );
        self::assertStringContainsString(
            '<pre>Not Found: Page not found.</pre>',
            $response->getBody()->getContents(),
            "Response 'body' should contain error message about 'Not Found: Page not found' when 'Throwable' occurs " .
            "during request handling in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testThrowNotFoundHttpExceptionWhenStrictParsingDisabledAndRouteIsMissing(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/profile/123',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response = $app->handle($request);

        self::assertSame(
            404,
            $response->getStatusCode(),
            "Response 'status code' should be '404' when accessing a non-existent route in 'StatelessApplication', " .
            "indicating a 'Not Found' error.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'text/html; charset=UTF-8' for 'NotFoundHttpException' in " .
            "'StatelessApplication'.",
        );
        self::assertStringContainsString(
            '<pre>Not Found: Page not found.</pre>',
            $response->getBody()->getContents(),
            "Response 'body' should contain the default not found message '<pre>Not Found: Page not found.</pre>' " .
            "when a 'NotFoundHttpException' is thrown in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testThrowNotFoundHttpExceptionWhenStrictParsingEnabledAndRouteIsMissing(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/profile/123',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication(
            [
                'components' => [
                    'urlManager' => [
                        'enableStrictParsing' => true,
                    ],
                ],
            ],
        );

        $app->handle($request);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage(Message::PAGE_NOT_FOUND->getMessage());

        $app->request->resolve();
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
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
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[RequiresPhpExtension('runkit7')]
    public function testUseErrorViewLogicWithDebugFalseAndException(): void
    {
        @runkit_constant_redefine('YII_DEBUG', false);

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/trigger-exception',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication(
            [
                'components' => [
                    'errorHandler' => [
                        'errorAction' => 'site/error',
                    ],
                ],
            ],
        );

        $response = $app->handle($request);

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Response 'status code' should be '500' when a 'Exception' occurs and 'debug' mode is disabled in " .
            "'StatelessApplication', indicating an 'internal server error'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'text/html; charset=UTF-8' for error response when 'Exception' " .
            "occurs and 'debug' mode is disabled in 'StatelessApplication'.",
        );
        Assert::equalsWithoutLE(
            <<<HTML
            <div id="custom-error-action">
            Custom error page from errorAction.
            <span class="exception-type">
            yii\base\Exception
            </span>
            <span class="exception-message">
            Exception error message.
            </span>
            </div>
            HTML,
            $response->getBody()->getContents(),
            "Response 'body' should contain 'Custom error page from errorAction' when 'Exception' is triggered " .
            "and 'debug' mode is disabled with errorAction configured in 'StatelessApplication'.",
        );

        @runkit_constant_redefine('YII_DEBUG', true);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[RequiresPhpExtension('runkit7')]
    public function testUseErrorViewLogicWithDebugFalseAndUserException(): void
    {
        @runkit_constant_redefine('YII_DEBUG', false);

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/trigger-user-exception',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication(
            [
                'components' => [
                    'errorHandler' => [
                        'errorAction' => 'site/error',
                    ],
                ],
            ],
        );

        $response = $app->handle($request);

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Response 'status code' should be '500' when a 'UserException' occurs and 'debug' mode is disabled in " .
            "'StatelessApplication', indicating an 'internal server error'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'text/html; charset=UTF-8' for error response when 'UserException' " .
            "occurs and 'debug' mode is disabled in 'StatelessApplication'.",
        );
        Assert::equalsWithoutLE(
            <<<HTML
            <div id="custom-error-action">
            Custom error page from errorAction.
            <span class="exception-type">
            yii\base\UserException
            </span>
            <span class="exception-message">
            User-friendly error message.
            </span>
            </div>
            HTML,
            $response->getBody()->getContents(),
            "Response 'body' should contain 'Custom error page from errorAction' when 'UserException' is triggered " .
            "and 'debug' mode is disabled with errorAction configured in 'StatelessApplication'.",
        );

        @runkit_constant_redefine('YII_DEBUG', true);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testUseErrorViewLogicWithDebugTrueAndUserException(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/trigger-user-exception',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication(
            [
                'components' => [
                    'errorHandler' => [
                        'errorAction' => 'site/error',
                    ],
                ],
            ],
        );

        $response = $app->handle($request);

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Response 'status code' should be '500' when a 'UserException' occurs and 'debug' mode is enabled in " .
            "'StatelessApplication', indicating an 'internal server error'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'text/html; charset=UTF-8' for error response when 'UserException'" .
            "occurs and 'debug' mode is enabled in 'StatelessApplication'.",
        );
        Assert::equalsWithoutLE(
            <<<HTML
            <div id="custom-error-action">
            Custom error page from errorAction.
            <span class="exception-type">
            yii\base\UserException
            </span>
            <span class="exception-message">
            User-friendly error message.
            </span>
            </div>
            HTML,
            $response->getBody()->getContents(),
            "Response 'body' should contain 'User-friendly error message.' when 'UserException' is triggered and " .
            "'debug' mode is enabled in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testUseErrorViewLogicWithNonHtmlFormat(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/trigger-exception',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication(
            [
                'components' => [
                    'errorHandler' => [
                        'errorAction' => 'site/error',
                    ],
                    'response' => [
                        'format' => Response::FORMAT_JSON,
                    ],
                ],
            ],
        );

        $response = $app->handle($request);
        $responseBody = $response->getBody()->getContents();

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Response 'status code' should be '500' when a 'Exception' occurs with JSON format in " .
            "'StatelessApplication', indicating an 'internal server error'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'application/json; charset=UTF-8' for error response when 'Exception'" .
            "occurs with JSON format in 'StatelessApplication'.",
        );
        self::assertStringNotContainsString(
            'Custom error page from errorAction.',
            $responseBody,
            "Response 'body' should NOT contain 'Custom error page from errorAction' when format is JSON " .
            "because useErrorView should be false regardless of YII_DEBUG or exception type in 'StatelessApplication'.",
        );

        $decodedResponse = Json::decode($responseBody);

        self::assertIsArray(
            $decodedResponse,
            'JSON response should be decodable to array',
        );
        self::assertArrayHasKey(
            'message',
            $decodedResponse,
            'JSON error response should contain message key',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
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
            "Response 'status code' should be '200' for 'site/login' route in 'StatelessApplication'.",
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
            "Response 'body' should contain valid JSON with 'status' and 'username' for successful login in " .
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
            "Response 'status code' should be '200' for 'site/checkauth' route in 'StatelessApplication'.",
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
            "Response 'body' should indicate 'guest' status and 'null' identity for a new session in " .
            "'StatelessApplication'.",
        );
    }
}
