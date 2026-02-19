<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\Group;
use yii\base\InvalidConfigException;
use yii\helpers\Json;
use yii2\extensions\psrbridge\tests\support\{ApplicationFactory, HelperFactory, TestCase};

use function array_filter;
use function array_key_exists;
use function preg_quote;
use function session_name;
use function str_starts_with;
use function uniqid;

/**
 * Unit tests for {@see \yii2\extensions\psrbridge\http\Application} session handling in stateless mode.
 *
 * Test coverage.
 * - Ensures captcha state is isolated across session IDs and requests.
 * - Ensures flash messages remain isolated between sessions.
 * - Ensures session-backed authentication state does not leak between users.
 * - Verifies data persists when the same session ID is reused.
 * - Verifies requests without a session cookie create a new session cookie.
 * - Verifies worker-mode requests keep session data isolated across session IDs.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('http')]
final class ApplicationSessionTest extends TestCase
{
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
}
