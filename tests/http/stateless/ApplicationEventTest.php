<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\{Group, TestWith};
use ReflectionException;
use yii\base\{Event, InvalidConfigException};
use yii2\extensions\psrbridge\http\StatelessApplication;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

#[Group('http')]
final class ApplicationEventTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws ReflectionException if the property does not exist or is inaccessible.
     */
    public function testEventRegistrationAndCleanupBetweenRequests(): void
    {
        $app = $this->statelessApplication();

        $eventsCaptured = [];

        $app->on(
            StatelessApplication::EVENT_BEFORE_REQUEST,
            static function (Event $event) use (&$eventsCaptured): void {
                $version = $event->sender instanceof StatelessApplication ? $event->sender->version : 'unknown';
                $eventsCaptured[] = "before_request_{$version}";
            },
        );

        $app->on(
            StatelessApplication::EVENT_AFTER_REQUEST,
            static function (Event $event) use (&$eventsCaptured): void {
                $version = $event->sender instanceof StatelessApplication ? $event->sender->version : 'unknown';
                $eventsCaptured[] = "after_request_{$version}";
            },
        );

        $response = $app->handle(FactoryHelper::createRequest('GET', '/site/index'));

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/index'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/index'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"hello":"world"}
            JSON,
            $response->getBody()->getContents(),
            "Expected JSON Response body '{\"hello\":\"world\"}'.",
        );
        self::assertCount(
            2,
            $eventsCaptured,
            'Should capture both BEFORE_REQUEST and AFTER_REQUEST events.',
        );
        self::assertContains(
            'before_request_0.1.0',
            $eventsCaptured,
            "Should contain BEFORE_REQUEST event for version '0.1.0'",
        );
        self::assertContains(
            'after_request_0.1.0',
            $eventsCaptured,
            "Should contain AFTER_REQUEST event for version '0.1.0'",
        );
        $this->assertEmptyRegisteredEvents(
            $app,
            'Events should be cleaned up after first request',
        );

        $response = $app->handle(FactoryHelper::createRequest('GET', '/site/statuscode'));

        self::assertSame(
            201,
            $response->getStatusCode(),
            "Expected HTTP '201' for route 'site/statuscode'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'text/html; charset=UTF-8' for route 'site/statuscode'.",
        );
        self::assertEmpty(
            $response->getBody()->getContents(),
            'Expected Response body should be empty.',
        );
        $this->assertEmptyRegisteredEvents(
            $app,
            'Events should be cleaned up after second request',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[TestWith([StatelessApplication::EVENT_AFTER_REQUEST])]
    #[TestWith([StatelessApplication::EVENT_BEFORE_REQUEST])]
    public function testTriggerEventDuringHandle(string $eventName): void
    {
        $invocations = 0;
        $sender = null;
        $sequence = [];

        $app = $this->statelessApplication();

        $app->on(
            $eventName,
            static function (Event $event) use (&$invocations, &$sequence, &$sender): void {
                $invocations++;
                $sender = $event->sender ?? null;
                $sequence[] = $event->name;
            },
        );

        $response = $app->handle(FactoryHelper::createRequest('GET', 'site/index'));

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/index'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/index'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"hello":"world"}
            JSON,
            $response->getBody()->getContents(),
            "Expected JSON Response body '{\"hello\":\"world\"}'.",
        );
        self::assertSame(
            1,
            $invocations,
            "Should trigger '{$eventName}' exactly once during 'handle()' method.",
        );
        self::assertSame(
            $app,
            $sender,
            'Event sender should be the application instance.',
        );
    }

    /**
     * @throws ReflectionException if the property does not exist or is inaccessible.
     */
    private function assertEmptyRegisteredEvents(StatelessApplication $app, string $message): void
    {
        self::assertEmpty(
            self::inaccessibleProperty($app, 'registeredEvents'),
            "{$message} 'registeredEvents' array property should be empty.",
        );
    }
}
