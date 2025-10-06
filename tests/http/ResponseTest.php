<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\{ResponseFactoryInterface, StreamFactoryInterface};
use Yii;
use yii\base\InvalidConfigException;
use yii\di\NotInstantiableException;
use yii\helpers\Json;
use yii\web\{Cookie, Session};
use yii2\extensions\psrbridge\http\Response;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function count;
use function str_contains;
use function time;
use function urlencode;

/**
 * Test suite for {@see Response} class functionality and behavior.
 *
 * Verifies correct conversion of Yii2 Response objects to PSR-7 responses, including session handling, headers,
 * cookies, events, and content.
 *
 * Ensures compatibility with active, inactive, and missing session scenarios.
 *
 * Test coverage.
 * - Checks event triggering and header/cookie propagation.
 * - Confirms correct PSR-7 response generation with active session and session cookie.
 * - Ensures session cookie is formatted and included as expected.
 * - Validates response conversion with inactive and missing session.
 * - Verifies prepare method is called during PSR-7 conversion.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('http')]
final class ResponseTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->closeApplication();

        parent::tearDown();
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws NotInstantiableException if a class or service can't be instantiated.
     */
    public function testConvertResponseWithActiveSession(): void
    {
        $this->webApplication(
            [
                'components' => [
                    'session' => [
                        'class' => Session::class,
                        'name' => 'PHPSESSID',
                    ],
                ],
            ],
        );

        $eventsBefore = [];
        $eventsAfter = [];

        $response = new Response(
            [
                'cookieValidationKey' => self::COOKIE_VALIDATION_KEY,
                'enableCookieValidation' => true,
            ],
        );

        $response->content = 'Test content with session';

        $response->setStatusCode(
            201,
            'Created',
        );
        $response->headers->add(
            'X-Custom-Header',
            'custom-value',
        );
        $response->on(
            Response::EVENT_BEFORE_SEND,
            static function () use (&$eventsBefore): void {
                $eventsBefore[] = 'EVENT_BEFORE_SEND';
            },
        );
        $response->on(
            Response::EVENT_AFTER_PREPARE,
            static function () use (&$eventsBefore): void {
                $eventsBefore[] = 'EVENT_AFTER_PREPARE';
            },
        );
        $response->on(
            Response::EVENT_AFTER_SEND,
            static function () use (&$eventsAfter): void {
                $eventsAfter[] = 'EVENT_AFTER_SEND';
            },
        );

        Yii::$container->set(ResponseFactoryInterface::class, FactoryHelper::createResponseFactory());
        Yii::$container->set(StreamFactoryInterface::class, FactoryHelper::createStreamFactory());

        $session = Yii::$app->getSession();
        $session->open();
        $sessionId = $session->getId();
        $session->setCookieParams(
            [
                'path' => '/custom/path',
                'domain' => 'example.com',
                'secure' => true,
                'httponly' => false,
                'samesite' => 'Strict',
            ],
        );
        $psr7Response = $response->getPsr7Response();

        self::assertSame(
            201,
            $psr7Response->getStatusCode(),
            'PSR-7 response should have the correct status code.',
        );
        self::assertSame(
            'Created',
            $psr7Response->getReasonPhrase(),
            'PSR-7 response should have the correct reason phrase.',
        );
        self::assertSame(
            'Test content with session',
            (string) $psr7Response->getBody(),
            'PSR-7 response should have the correct body content.',
        );
        self::assertSame(
            ['custom-value'],
            $psr7Response->getHeader('X-Custom-Header'),
            'PSR-7 response should have the correct custom header.',
        );

        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertGreaterThanOrEqual(
            1,
            count($setCookieHeaders),
            "PSR-7 response should have at least one 'Set-Cookie' header for session.",
        );

        $sessionCookieFound = false;

        foreach ($setCookieHeaders as $cookieHeader) {
            if (str_contains($cookieHeader, 'PHPSESSID=')) {
                $sessionCookieFound = true;

                self::assertStringStartsWith(
                    'PHPSESSID=',
                    $cookieHeader,
                    "Session cookie should start with 'PHPSESSID='",
                );
                self::assertStringNotContainsString(
                    'PHPSESSID=' . urlencode($sessionId),
                    $cookieHeader,
                    "Session cookie value should be hashed, not plain session 'ID'.",
                );
                self::assertStringContainsString(
                    '; Path=/custom/path',
                    $cookieHeader,
                    'Session cookie should have the custom path.',
                );
                self::assertStringContainsString(
                    '; Domain=example.com',
                    $cookieHeader,
                    'Session cookie should have the custom domain.',
                );
                self::assertStringContainsString(
                    '; Secure',
                    $cookieHeader,
                    "Session cookie should have the 'Secure' flag.",
                );
                self::assertStringNotContainsString(
                    '; HttpOnly',
                    $cookieHeader,
                    "Session cookie should NOT have 'HttpOnly' flag when set to 'false'.",
                );
                self::assertStringContainsString(
                    '; SameSite=Strict',
                    $cookieHeader,
                    "Session cookie should have the custom 'SameSite' attribute.",
                );
            }
        }

        self::assertTrue(
            $sessionCookieFound,
            "Session cookie should be found in 'Set-Cookie' headers.",
        );
        self::assertContains(
            'EVENT_BEFORE_SEND',
            $eventsBefore,
            "'EVENT_BEFORE_SEND' should be triggered.",
        );
        self::assertContains(
            'EVENT_AFTER_PREPARE',
            $eventsBefore,
            "'EVENT_AFTER_PREPARE' should be triggered.",
        );
        self::assertNotContains(
            'EVENT_AFTER_SEND',
            $eventsAfter,
            "'EVENT_AFTER_SEND' should NOT be triggered during conversion.",
        );
        self::assertFalse(
            $response->isSent,
            "Response should NOT be marked as sent after 'getPsr7Response()' - only converted.",
        );
        self::assertFalse(
            $session->getIsActive(),
            "Session should be closed after 'getPsr7Response()'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws NotInstantiableException if a class or service can't be instantiated.
     */
    public function testConvertResponseWithInactiveSession(): void
    {
        $this->webApplication(
            [
                'components' => [
                    'session' => [
                        'class' => Session::class,
                        'name' => 'TESTSESSID',
                    ],
                ],
            ],
        );

        $response = new Response();

        $response->content = 'Test content with inactive session';

        Yii::$container->set(ResponseFactoryInterface::class, FactoryHelper::createResponseFactory());
        Yii::$container->set(StreamFactoryInterface::class, FactoryHelper::createStreamFactory());

        $session = Yii::$app->getSession();

        self::assertFalse(
            $session->getIsActive(),
            "Session should not be 'active' initially.",
        );

        $psr7Response = $response->getPsr7Response();

        self::assertSame(
            'Test content with inactive session',
            (string) $psr7Response->getBody(),
            'PSR-7 response should have the correct body content.',
        );

        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');
        $sessionCookieFound = false;

        foreach ($setCookieHeaders as $cookieHeader) {
            if (str_contains($cookieHeader, 'TESTSESSID=')) {
                $sessionCookieFound = true;
            }
        }

        self::assertFalse(
            $sessionCookieFound,
            "No session cookie should be added when session is 'not active'.",
        );
        self::assertFalse(
            $response->isSent,
            "Response should NOT be marked as sent after 'getPsr7Response()' - only converted.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws NotInstantiableException if a class or service can't be instantiated.
     */
    public function testConvertResponseWithoutSession(): void
    {
        $this->webApplication();

        $eventsBefore = [];
        $eventsAfter = [];

        $response = new Response();

        $response->content = 'Test content without session';

        $response->setStatusCode(200);
        $response->headers->add(
            'X-Test-Header',
            'test-value',
        );
        $response->cookies->add(
            new Cookie(
                [
                    'name' => 'test_cookie',
                    'value' => 'test_value',
                ],
            ),
        );

        $response->on(
            Response::EVENT_BEFORE_SEND,
            static function () use (&$eventsBefore): void {
                $eventsBefore[] = 'EVENT_BEFORE_SEND';
            },
        );
        $response->on(
            Response::EVENT_AFTER_PREPARE,
            static function () use (&$eventsBefore): void {
                $eventsBefore[] = 'EVENT_AFTER_PREPARE';
            },
        );
        $response->on(
            Response::EVENT_AFTER_SEND,
            static function () use (&$eventsAfter): void {
                $eventsAfter[] = 'EVENT_AFTER_SEND';
            },
        );

        Yii::$container->set(ResponseFactoryInterface::class, FactoryHelper::createResponseFactory());
        Yii::$container->set(StreamFactoryInterface::class, FactoryHelper::createStreamFactory());
        Yii::$app->clear('session');

        $psr7Response = $response->getPsr7Response();

        self::assertSame(
            200,
            $psr7Response->getStatusCode(),
            'PSR-7 response should have the correct status code.',
        );
        self::assertSame(
            'Test content without session',
            (string) $psr7Response->getBody(),
            'PSR-7 response should have the correct body content.',
        );
        self::assertSame(
            ['test-value'],
            $psr7Response->getHeader('X-Test-Header'),
            'PSR-7 response should have the correct custom header.',
        );

        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        self::assertCount(
            1,
            $setCookieHeaders,
            "PSR-7 response should have one 'Set-Cookie' header.",
        );
        self::assertStringContainsString(
            'test_cookie=',
            $setCookieHeaders[0] ?? '',
            "'Set-Cookie' header should contain the test cookie.",
        );
        self::assertContains(
            'EVENT_BEFORE_SEND',
            $eventsBefore,
            "'EVENT_BEFORE_SEND' should be triggered.",
        );
        self::assertContains(
            'EVENT_AFTER_PREPARE',
            $eventsBefore,
            "'EVENT_AFTER_PREPARE' should be triggered.",
        );
        self::assertNotContains(
            'EVENT_AFTER_SEND',
            $eventsAfter,
            "'EVENT_AFTER_SEND' should NOT be triggered during conversion.",
        );
        self::assertFalse(
            $response->isSent,
            "Response should NOT be marked as sent after 'getPsr7Response()' - only converted.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws NotInstantiableException if a class or service can't be instantiated.
     */
    public function testFormatSessionCookieWithDefaultParams(): void
    {
        $this->webApplication(
            [
                'components' => [
                    'session' => [
                        'class' => Session::class,
                        'name' => 'DEFAULTSESS',
                    ],
                ],
            ],
        );

        $response = new Response(
            [
                'cookieValidationKey' => self::COOKIE_VALIDATION_KEY,
                'enableCookieValidation' => true,
            ],
        );

        $response->content = 'Test with default session params';

        Yii::$container->set(ResponseFactoryInterface::class, FactoryHelper::createResponseFactory());
        Yii::$container->set(StreamFactoryInterface::class, FactoryHelper::createStreamFactory());

        $session = Yii::$app->getSession();
        $session->setCookieParams([]);
        $session->open();
        $psr7Response = $response->getPsr7Response();
        $setCookieHeaders = $psr7Response->getHeader('Set-Cookie');

        $sessionCookieFound = false;

        foreach ($setCookieHeaders as $cookieHeader) {
            if (str_contains($cookieHeader, 'DEFAULTSESS=')) {
                $sessionCookieFound = true;

                self::assertStringContainsString(
                    'DEFAULTSESS=',
                    $cookieHeader,
                    'Session cookie should contain the session name.',
                );
                self::assertStringContainsString(
                    '; Path=/',
                    $cookieHeader,
                    "Session cookie should have default path '/'.",
                );
                self::assertStringNotContainsString(
                    '; Domain=',
                    $cookieHeader,
                    "'Domain' should not be present when not specified.",
                );
                self::assertStringNotContainsString(
                    '; Secure',
                    $cookieHeader,
                    "'Secure' flag should not be present by default.",
                );
                self::assertStringContainsString(
                    '; HttpOnly',
                    $cookieHeader,
                    "'HttpOnly' flag should be present by default.",
                );
                self::assertStringNotContainsString(
                    '; SameSite=',
                    $cookieHeader,
                    "'SameSite' should not be present when not specified.",
                );
            }
        }

        self::assertTrue(
            $sessionCookieFound,
            'Session cookie should be found with default parameters.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws NotInstantiableException if a class or service can't be instantiated.
     */
    public function testPrepareMethodIsCalledDuringPsr7Conversion(): void
    {
        $this->webApplication();

        $response = new Response();

        $response->format = Response::FORMAT_JSON;

        $response->data = [
            'status' => 'success',
            'message' => 'Test data',
            'timestamp' => time(),
        ];

        Yii::$container->set(ResponseFactoryInterface::class, FactoryHelper::createResponseFactory());
        Yii::$container->set(StreamFactoryInterface::class, FactoryHelper::createStreamFactory());

        $psr7Response = $response->getPsr7Response();
        $body = (string) $psr7Response->getBody();
        $decodedData = Json::decode($body);

        self::assertIsArray(
            $decodedData,
            'Response body should be valid JSON after preparation.',
        );
        self::assertSame(
            'success',
            $decodedData['status'] ?? null,
            'JSON response should contain the correct status.',
        );
        self::assertSame(
            'Test data',
            $decodedData['message'] ?? null,
            'JSON response should contain the correct message.',
        );

        $contentTypeHeaders = $psr7Response->getHeader('Content-Type');

        self::assertNotEmpty(
            $contentTypeHeaders,
            "'Content-Type' header should be set after 'prepare()' is called.",
        );
        self::assertStringContainsString(
            'application/json',
            $contentTypeHeaders[0] ?? '',
            "'Content-Type' should be 'application/json' for JSON format responses.",
        );
        self::assertFalse(
            $response->isSent,
            "Response should NOT be marked as sent after 'getPsr7Response()' - only converted.",
        );
    }
}
