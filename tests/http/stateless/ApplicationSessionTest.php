<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\{Group, TestWith};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use yii\base\InvalidConfigException;
use yii\helpers\Json;
use yii2\extensions\psrbridge\tests\support\{ApplicationFactory, HelperFactory, TestCase};
use yii2\extensions\psrbridge\tests\support\stub\SessionBootstrap;

use function array_filter;
use function array_key_exists;
use function explode;
use function preg_quote;
use function session_name;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function substr;
use function uniqid;

/**
 * Unit tests for {@see \yii2\extensions\psrbridge\http\Application} session handling in stateless mode.
 */
#[Group('http')]
final class ApplicationSessionTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[TestWith(['short', true])]
    #[TestWith(['short', false])]
    #[TestWith(['0123456789abcdefABCDEF-ghijklmno', true])]
    #[TestWith(['0123456789abcdefABCDEF-ghijklmno', false])]
    public function testAppliesWellFormedSessionIdFromRequestCookie(string $sessionId, bool $useSession): void
    {
        $sessionName = $this->sessionName();

        $app = ApplicationFactory::stateless(['useSession' => $useSession]);

        $response = $app->handle($this->createRequest('site/setsession', [$sessionName => $sessionId]));

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/setsession'.",
        );
        self::assertSame(
            $sessionId,
            self::extractSessionId($response, $sessionName),
            'Session `ID` must come from the request cookie.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testBootstrapObservesSessionOpenedFromRequestCookie(): void
    {
        SessionBootstrap::$active = false;
        SessionBootstrap::$id = '';

        $sessionName = session_name();

        if ($sessionName === false) {
            self::fail("Failed to retrieve session name using 'session_name()'.");
        }

        $sessionId = 'probe-session-id';

        $_COOKIE = [$sessionName => $sessionId];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/index',
        ];

        $app = ApplicationFactory::stateless(['bootstrap' => [SessionBootstrap::class]]);

        $app->handle(HelperFactory::createServerRequestCreator()->createFromGlobals());

        self::assertTrue(
            SessionBootstrap::$active,
            'Session must be active during bootstrap.',
        );
        self::assertSame(
            $sessionId,
            SessionBootstrap::$id,
            'Observed session `ID` must match the cookie.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testCaptchaSessionIsolation(): void
    {
        $sessionName = session_name();

        if ($sessionName === false) {
            self::fail("Failed to retrieve session name using 'session_name()'.");
        }

        // first user generates captcha - need to use 'refresh=1' to get JSON response
        $_COOKIE = [$sessionName => 'user-a-session'];
        $_GET = ['refresh' => '1'];
        $_SERVER = [
            'QUERY_STRING' => 'refresh=1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/captcha',
        ];

        $app = ApplicationFactory::stateless();

        $response = $app->handle(HelperFactory::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/captcha'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/captcha'.",
        );
        self::assertSame(
            "{$sessionName}=user-a-session; Path=/; HttpOnly; SameSite",
            $response->getHeaderLine('Set-Cookie'),
            "Response Set-Cookie header should contain session 'ID' 'user-a-session', ensuring correct session "
            . 'assignment.',
        );

        $captchaData1 = Json::decode($response->getBody()->getContents());

        self::assertIsArray(
            $captchaData1,
            'Captcha response should be an array after decoding JSON.',
        );
        self::assertArrayHasKey(
            'hash1',
            $captchaData1,
            "Captcha response should contain 'hash1' key.",
        );
        self::assertArrayHasKey(
            'hash2',
            $captchaData1,
            "Captcha response should contain 'hash2' key.",
        );
        self::assertArrayHasKey(
            'url',
            $captchaData1,
            "Captcha response should contain 'url' key.",
        );

        // second user requests captcha - should get different data
        $_COOKIE = [$sessionName => 'user-b-session'];
        $_GET = ['refresh' => '1'];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/captcha',
            'QUERY_STRING' => 'refresh=1',
        ];

        $response = $app->handle(HelperFactory::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/captcha'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/captcha'.",
        );
        self::assertSame(
            "{$sessionName}=user-b-session; Path=/; HttpOnly; SameSite",
            $response->getHeaderLine('Set-Cookie'),
            "Response Set-Cookie header should contain session 'ID' 'user-b-session', ensuring correct session "
            . 'assignment for second user.',
        );

        $captchaData2 = Json::decode($response->getBody()->getContents());

        self::assertIsArray(
            $captchaData2,
            'Captcha response should be an array after decoding JSON for second user.',
        );
        self::assertArrayHasKey(
            'hash1',
            $captchaData2,
            "Captcha response should contain 'hash1' key for second user.",
        );

        $hash1 = $captchaData1['hash1'] ?? null;
        $hash2 = $captchaData2['hash1'] ?? null;

        self::assertNotNull(
            $hash1,
            "First captcha response 'hash1' should not be 'null'.",
        );
        self::assertNotNull(
            $hash2,
            "Second captcha response 'hash1' should not be 'null'.",
        );
        self::assertNotSame(
            $hash1,
            $hash2,
            "Captcha 'hash1' for first user should not match 'hash1' for second user, ensuring session isolation.",
        );

        // also test that we can get the actual captcha image
        $url = $captchaData2['url'] ?? null;

        self::assertIsString(
            $url,
            "Captcha response 'url' should be a string for second user.",
        );

        $_COOKIE = [$sessionName => 'user-b-session'];
        $_GET = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $url,
        ];

        $response = $app->handle(HelperFactory::createServerRequestCreator()->createFromGlobals());
        $imageContent = $response->getBody()->getContents();

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/captcha'.",
        );
        self::assertSame(
            'image/png',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'image/png' for route 'site/captcha'.",
        );
        self::assertNotEmpty(
            $imageContent,
            "Captcha image content should not be empty for '{$url}'.",
        );
        self::assertSame(
            "{$sessionName}=user-b-session; Path=/; HttpOnly; SameSite",
            $response->getHeaderLine('Set-Cookie'),
            "Captcha image response Set-Cookie should contain 'user-b-session' for '{$url}'.",
        );
        self::assertStringStartsWith(
            "\x89PNG",
            $imageContent,
            "Captcha image content should start with PNG header for '{$url}'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[TestWith(['../../etc/passwd', true])]
    #[TestWith(['../../etc/passwd', false])]
    #[TestWith(['abc$%^', true])]
    #[TestWith(['abc$%^', false])]
    public function testDiscardsMalformedSessionIdFromRequestCookie(string $sessionId, bool $useSession): void
    {
        $this->assertCookieSessionIdIsDiscarded($sessionId, $useSession);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[TestWith([true])]
    #[TestWith([false])]
    public function testDiscardsNonStringSessionIdFromRequestCookie(bool $useSession): void
    {
        $this->assertCookieSessionIdIsDiscarded(['injected'], $useSession);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[TestWith([true])]
    #[TestWith([false])]
    public function testDiscardsSessionIdLongerThanPhpLimitFromRequestCookie(bool $useSession): void
    {
        $this->assertCookieSessionIdIsDiscarded(str_repeat('a', 257), $useSession);
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFlashMessagesIsolationBetweenSessions(): void
    {
        $sessionName = session_name();

        if ($sessionName === false) {
            self::fail("Failed to retrieve session name using 'session_name()'.");
        }

        // first user sets a flash message
        $_COOKIE = [$sessionName => 'flash-user-a'];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/setflash',
        ];

        $app = ApplicationFactory::stateless();

        $response = $app->handle(HelperFactory::createServerRequestCreator()->createFromGlobals());
        $sessionName = $app->session->getName();

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/setflash'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/setflash'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"status":"ok"}
            JSON,
            $response->getBody()->getContents(),
            'Response body should be valid JSON confirming the flash message was set.',
        );
        self::assertSame(
            "{$sessionName}=flash-user-a; Path=/; HttpOnly; SameSite",
            $response->getHeaderLine('Set-Cookie'),
            "Response Set-Cookie header should contain session 'ID' 'flash-user-a', ensuring correct session "
            . 'assignment.',
        );

        // second user checks for flash messages
        $_COOKIE = [$sessionName => 'flash-user-b'];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/getflash',
        ];

        $response = $app->handle(HelperFactory::createServerRequestCreator()->createFromGlobals());

        $flashData = Json::decode($response->getBody()->getContents());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/getflash'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/getflash'.",
        );
        self::assertSame(
            "{$sessionName}=flash-user-b; Path=/; HttpOnly; SameSite",
            $response->getHeaderLine('Set-Cookie'),
            "Response Set-Cookie header should contain session 'ID' 'flash-user-b', ensuring correct session "
            . 'assignment.',
        );
        self::assertIsArray(
            $flashData,
            'Flash message response should be an array after decoding JSON.',
        );
        self::assertEmpty(
            $flashData['flash'] ?? [],
            "Flash message array should be empty for new session 'flash-user-b', confirming session isolation.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testMultipleRequestsWithDifferentSessionsInWorkerMode(): void
    {
        $sessionName = session_name();

        if ($sessionName === false) {
            self::fail("Failed to retrieve session name using 'session_name()'.");
        }

        $app = ApplicationFactory::stateless();

        $sessions = [];

        for ($i = 1; $i <= 3; $i++) {
            $sessionId = "worker-session-{$i}";
            $_COOKIE = [$sessionName => $sessionId];
            $_POST = ['data' => "user-{$i}-data"];
            $_SERVER = [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => 'site/setsessiondata',
            ];

            $response = $app->handle(HelperFactory::createServerRequestCreator()->createFromGlobals());

            self::assertSame(
                200,
                $response->getStatusCode(),
                "Expected HTTP '200' for route 'site/setsessiondata'.",
            );
            self::assertSame(
                'application/json; charset=UTF-8',
                $response->getHeaderLine('Content-Type'),
                "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/setsessiondata'.",
            );
            self::assertSame(
                "{$sessionName}={$sessionId}; Path=/; HttpOnly; SameSite",
                $response->getHeaderLine('Set-Cookie'),
                "Response Set-Cookie header should contain session 'ID' '{$sessionId}' ensuring correct session "
                . 'assignment in worker mode.',
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

            $response = $app->handle(HelperFactory::createServerRequestCreator()->createFromGlobals());

            self::assertSame(
                200,
                $response->getStatusCode(),
                "Expected HTTP '200' for route 'site/getsessiondata'.",
            );
            self::assertSame(
                'application/json; charset=UTF-8',
                $response->getHeaderLine('Content-Type'),
                "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/getsessiondata'.",
            );
            self::assertSame(
                "{$sessionName}={$sessionId}; Path=/; HttpOnly; SameSite",
                $response->getHeaderLine('Set-Cookie'),
                "Response Set-Cookie header should contain session 'ID' '{$sessionId}', ensuring correct session "
                . "assignment for session '{$sessionId}'.",
            );

            $data = Json::decode($response->getBody()->getContents());

            $expectedData = 'user-' . ($index + 1) . '-data';

            self::assertIsArray(
                $data,
                "Response body should be an array after decoding JSON for session '{$sessionId}' in multiple "
                . 'requests with different sessions.',
            );
            self::assertSame(
                $expectedData,
                $data['data'] ?? null,
                "Session '{$sessionId}' should return its own data ('{$expectedData}') and not leak data between "
                . 'sessions in multiple requests with different sessions.',
            );
        }
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testSessionDataPersistenceWithSameSessionId(): void
    {
        $sessionName = session_name();

        if ($sessionName === false) {
            self::fail("Failed to retrieve session name using 'session_name()'.");
        }

        $sessionId = 'test-session-' . uniqid();

        $_COOKIE = [$sessionName => $sessionId];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/setsession',
        ];

        $app = ApplicationFactory::stateless();

        // first request - set session data
        $response = $app->handle(HelperFactory::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/setsession'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/setsession'.",
        );
        self::assertSame(
            "{$sessionName}={$sessionId}; Path=/; HttpOnly; SameSite",
            $response->getHeaderLine('Set-Cookie'),
            "Response Set-Cookie header should contain '{$sessionId}'.",
        );

        $_COOKIE = [$sessionName => $sessionId];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/getsession',
        ];

        // second request - same session ID should retrieve the data
        $response = $app->handle(HelperFactory::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/getsession'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/getsession'.",
        );
        self::assertSame(
            "{$sessionName}={$sessionId}; Path=/; HttpOnly; SameSite",
            $response->getHeaderLine('Set-Cookie'),
            "Response Set-Cookie header should contain '{$sessionId}'.",
        );

        $body = Json::decode($response->getBody()->getContents());

        self::assertIsArray(
            $body,
            'Response body should be an array after decoding JSON response.',
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

        if ($sessionName === false) {
            self::fail("Failed to retrieve session name using 'session_name()'.");
        }

        $_COOKIE = [$sessionName => 'session-user-a'];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/setsession',
        ];

        $app = ApplicationFactory::stateless();

        // first request - set a session value
        $response = $app->handle(HelperFactory::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/setsession'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/setsession'.",
        );
        self::assertSame(
            "{$sessionName}=session-user-a; Path=/; HttpOnly; SameSite",
            $response->getHeaderLine('Set-Cookie'),
            "Response Set-Cookie header should contain 'session-user-a'.",
        );

        $_COOKIE = [$sessionName => 'session-user-b'];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/getsession',
        ];

        // second request - different session
        $response = $app->handle(HelperFactory::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/getsession'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/getsession'.",
        );
        self::assertSame(
            "{$sessionName}=session-user-b; Path=/; HttpOnly; SameSite",
            $response->getHeaderLine('Set-Cookie'),
            "Response Set-Cookie header should contain 'session-user-b'.",
        );

        $body = Json::decode($response->getBody()->getContents());

        self::assertIsArray(
            $body,
            'Response body should be an array after decoding JSON response',
        );
        self::assertNull(
            $body['testValue'] ?? null,
            "Session data from first request should not leak to a second request with a different session 'ID'.",
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

        $app = ApplicationFactory::stateless();

        $response = $app->handle(HelperFactory::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/getsession'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/getsession'.",
        );

        $sessionName = $app->session->getName();
        $cookie = array_filter(
            $response->getHeader('Set-Cookie'),
            static fn(string $cookie): bool => str_starts_with($cookie, "{$sessionName}="),
        );

        $cookieLine = (string) reset($cookie);

        self::assertCount(
            1,
            $cookie,
            "Response Set-Cookie header should contain exactly one '{$sessionName}' cookie when no session cookie "
            . 'is sent.',
        );
        self::assertMatchesRegularExpression(
            '/^' . preg_quote($sessionName, '/')
            . '=[A-Za-z0-9,-]+; Path=\/; HttpOnly; SameSite(?:=(?:Lax|Strict|None))?$/',
            $cookieLine,
            'Response Set-Cookie should match the expected format for a new session when no cookie is sent. '
            . "Value received: '{$cookieLine}'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testUserAuthenticationSessionIsolation(): void
    {
        $sessionName = session_name();

        if ($sessionName === false) {
            self::fail("Failed to retrieve session name using 'session_name()'.");
        }

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

        $app = ApplicationFactory::stateless();

        $response = $app->handle(HelperFactory::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/login'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/login'.",
        );
        self::assertSame(
            "{$sessionName}=user1-session; Path=/; HttpOnly; SameSite",
            $response->getHeaderLine('Set-Cookie'),
            "Response Set-Cookie header should contain 'user1-session'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"status":"ok","username":"admin"}
            JSON,
            $response->getBody()->getContents(),
            "Response body should include 'status' and 'username' after successful login.",
        );

        // second user checks authentication status - should not be logged in
        $_COOKIE = [$sessionName => 'user2-session'];
        $_POST = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/checkauth',
        ];

        $response = $app->handle(HelperFactory::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/checkauth'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/checkauth'.",
        );
        self::assertSame(
            "{$sessionName}=user2-session; Path=/; HttpOnly; SameSite",
            $response->getHeaderLine('Set-Cookie'),
            "Response Set-Cookie header should contain 'user2-session'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"isGuest":true,"identity":null}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should indicate 'guest' status and 'null' identity for a new session.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testUseSessionDisabledAppliesRequestCookieSessionIdWithoutStartingSession(): void
    {
        SessionBootstrap::$active = false;
        SessionBootstrap::$id = '';

        $sessionName = $this->sessionName();

        $app = ApplicationFactory::stateless(
            [
                'bootstrap' => [SessionBootstrap::class],
                'useSession' => false,
            ],
        );

        $response = $app->handle($this->createRequest('site/index', [$sessionName => 'cookie-only-session']));

        $this->assertSiteIndexJsonResponse($response);
        self::assertFalse(
            SessionBootstrap::$active,
            'Session must stay closed during bootstrap.',
        );
        self::assertSame(
            'cookie-only-session',
            SessionBootstrap::$id,
            'Session `ID` must come from the request cookie.',
        );
        self::assertSame(
            '',
            self::extractSessionId($response, $sessionName),
            'No session cookie must be emitted.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testUseSessionDisabledAppliesSessionIdOfPhpMaximumLengthWithoutStartingSession(): void
    {
        SessionBootstrap::$active = true;
        SessionBootstrap::$id = '';

        // 256 characters from PHP's whole alphabet; the `files` handler cannot store such an `ID`, so it stays closed.
        $sessionId = str_repeat('Az09,-', 42) . 'abcd';

        $app = ApplicationFactory::stateless(
            [
                'bootstrap' => [SessionBootstrap::class],
                'useSession' => false,
            ],
        );

        $response = $app->handle($this->createRequest('site/index', [$this->sessionName() => $sessionId]));

        $this->assertSiteIndexJsonResponse($response);
        self::assertFalse(
            SessionBootstrap::$active,
            'Session must stay closed during bootstrap.',
        );
        self::assertSame(
            $sessionId,
            SessionBootstrap::$id,
            'Session `ID` must come from the request cookie.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testUseSessionDisabledClosesSessionLeftActiveByPreviousRequest(): void
    {
        $sessionName = $this->sessionName();

        $app = ApplicationFactory::stateless(['useSession' => false]);

        $first = $app->handle($this->createRequest('site/setsession'));

        $firstId = self::extractSessionId($first, $sessionName);

        // a session opened after the response was converted (for example, by an after-send handler) stays active.
        $app->session->open();

        self::assertTrue(
            $app->session->getIsActive(),
            'Precondition: session must be active between requests.',
        );

        $second = $app->handle($this->createRequest('site/getsession'));

        self::assertSame(
            200,
            $second->getStatusCode(),
            "Expected HTTP '200' for route 'site/getsession'.",
        );
        self::assertNotSame(
            $firstId,
            self::extractSessionId($second, $sessionName),
            'Session `ID` of the previous visitor must not be reused.',
        );
        self::assertSame(
            ['testValue' => null],
            Json::decode($second->getBody()->getContents()),
            'Session data of the previous visitor must not be visible.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[TestWith(['../../etc/passwd'])]
    #[TestWith(['abc$%^'])]
    #[TestWith(["trailing-newline\n"])]
    public function testUseSessionDisabledDiscardsMalformedSessionIdBeforeBootstrap(string $sessionId): void
    {
        SessionBootstrap::$active = true;
        SessionBootstrap::$id = 'not-observed';

        $app = ApplicationFactory::stateless(
            [
                'bootstrap' => [SessionBootstrap::class],
                'useSession' => false,
            ],
        );

        $response = $app->handle($this->createRequest('site/index', [$this->sessionName() => $sessionId]));

        $this->assertSiteIndexJsonResponse($response);
        self::assertFalse(
            SessionBootstrap::$active,
            'Session must stay closed during bootstrap.',
        );
        self::assertSame(
            '',
            SessionBootstrap::$id,
            'Empty session `ID` must be applied instead.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testUseSessionDisabledDoesNotRequireCookieValidationKeyWithoutSessionCookie(): void
    {
        // stateless REST setup documented in the README: request cookie validation keeps Yii's default (`true`).
        $app = ApplicationFactory::stateless(
            [
                'components' => [
                    'request' => [
                        'cookieValidationKey' => '',
                        'enableCookieValidation' => true,
                    ],
                ],
                'syncCookieValidation' => false,
                'useSession' => false,
            ],
        );

        $this->assertSiteIndexJsonResponse($app->handle($this->createRequest('site/index')));
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testUseSessionDisabledDoesNotStartSessionWithoutSessionConsumer(): void
    {
        $sessionName = $this->sessionName();

        $app = ApplicationFactory::stateless(
            [
                'bootstrap' => [SessionBootstrap::class],
                'useSession' => false,
            ],
        );

        // a previous visitor leaves a session `ID` behind in PHP's session module.
        $first = $app->handle($this->createRequest('site/setsession'));

        self::assertNotSame(
            '',
            self::extractSessionId($first, $sessionName),
            'Precondition: previous visitor must receive a session.',
        );

        SessionBootstrap::$active = true;
        SessionBootstrap::$id = 'not-observed';

        $second = $app->handle($this->createRequest('site/index'));

        $this->assertSiteIndexJsonResponse($second);
        self::assertFalse(
            SessionBootstrap::$active,
            'Session must stay closed during bootstrap.',
        );
        self::assertSame(
            '',
            SessionBootstrap::$id,
            'Session `ID` of the previous visitor must be cleared.',
        );
        self::assertFalse(
            $app->session->getIsActive(),
            'Session must stay closed after the request.',
        );
        self::assertSame(
            '',
            self::extractSessionId($second, $sessionName),
            'No session cookie must be emitted.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testUseSessionDisabledIsolatesLazySessionsBetweenRequestsWithoutCookie(): void
    {
        $sessionName = $this->sessionName();

        $app = ApplicationFactory::stateless(['useSession' => false]);

        $first = $app->handle($this->createRequest('site/setsession'));

        $firstId = self::extractSessionId($first, $sessionName);

        self::assertNotSame(
            '',
            $firstId,
            'First visitor must receive a session cookie.',
        );

        $second = $app->handle($this->createRequest('site/getsession'));

        $secondId = self::extractSessionId($second, $sessionName);

        self::assertNotSame(
            '',
            $secondId,
            'Second visitor must receive a session cookie.',
        );
        self::assertNotSame(
            $firstId,
            $secondId,
            'Second visitor must receive a new session `ID`.',
        );
        self::assertSame(
            ['testValue' => null],
            Json::decode($second->getBody()->getContents()),
            'Session data of the first visitor must not be visible.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testUseSessionDisabledResumesLazySessionFromRequestCookie(): void
    {
        $sessionName = $this->sessionName();

        $sessionId = 'lazy-session-' . uniqid();

        $app = ApplicationFactory::stateless(['useSession' => false]);

        $first = $app->handle($this->createRequest('site/setsession', [$sessionName => $sessionId]));

        self::assertSame(
            $sessionId,
            self::extractSessionId($first, $sessionName),
            'Lazy session must use the `ID` from the request cookie.',
        );

        // an unrelated visitor without a cookie in between must not affect the first visitor.
        $other = $app->handle($this->createRequest('site/getsession'));

        self::assertNotSame(
            $sessionId,
            self::extractSessionId($other, $sessionName),
            'Visitor without a cookie must not reuse the `ID`.',
        );

        $returning = $app->handle($this->createRequest('site/getsession', [$sessionName => $sessionId]));

        self::assertSame(
            $sessionId,
            self::extractSessionId($returning, $sessionName),
            'Returning visitor must keep the session `ID`.',
        );
        self::assertSame(
            ['testValue' => 'test-value'],
            Json::decode($returning->getBody()->getContents()),
            'Returning visitor must see the stored session data.',
        );
    }

    /**
     * Asserts that a request carrying the session cookie value receives a new, well-formed session `ID` instead.
     *
     * @param mixed $sessionId Session cookie value sent with the request.
     * @param bool $useSession Whether the bridge opens the session before bootstrap.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    private function assertCookieSessionIdIsDiscarded(mixed $sessionId, bool $useSession): void
    {
        $sessionName = $this->sessionName();

        $app = ApplicationFactory::stateless(['useSession' => $useSession]);

        $response = $app->handle($this->createRequest('site/setsession', [$sessionName => $sessionId]));

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/setsession'.",
        );

        $emittedId = self::extractSessionId($response, $sessionName);

        self::assertNotSame(
            $sessionId,
            $emittedId,
            'Cookie value must not be used as the session `ID`.',
        );
        self::assertMatchesRegularExpression(
            '/^[A-Za-z0-9,-]+$/',
            $emittedId,
            'A new, well-formed session `ID` must be emitted.',
        );
    }

    /**
     * Builds a `GET` request for the route, carrying cookies only in the PSR-7 request (`$_COOKIE` stays empty, as
     * under RoadRunner), so PHP's native session cookie handling cannot supply the session `ID`.
     *
     * @param string $route Route to request.
     * @param array<string, mixed> $cookies Request cookies.
     *
     * @return ServerRequestInterface PSR-7 server request instance.
     */
    private function createRequest(string $route, array $cookies = []): ServerRequestInterface
    {
        $_COOKIE = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $route,
        ];

        return HelperFactory::createServerRequestCreator()->createFromGlobals()->withCookieParams($cookies);
    }

    /**
     * Returns the session `ID` emitted in the `Set-Cookie` header, or an empty string when none is emitted.
     *
     * @param ResponseInterface $response PSR-7 response to inspect.
     * @param string $sessionName Session cookie name.
     *
     * @return string Session `ID` from the session cookie.
     */
    private static function extractSessionId(ResponseInterface $response, string $sessionName): string
    {
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            if (str_starts_with($cookie, "{$sessionName}=")) {
                return explode(';', substr($cookie, strlen($sessionName) + 1))[0];
            }
        }

        return '';
    }

    /**
     * Returns the session name used by PHP's session module.
     */
    private function sessionName(): string
    {
        $sessionName = session_name();

        if ($sessionName === false) {
            self::fail(
                "Failed to retrieve session name using 'session_name()'.",
            );
        }

        return $sessionName;
    }
}
