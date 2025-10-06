<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\{Group, TestWith};
use ReflectionException;
use yii\base\{Event, InvalidConfigException};
use yii2\extensions\psrbridge\http\StatelessApplication;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\support\stub\EventComponent;
use yii2\extensions\psrbridge\tests\TestCase;

use function count;

/**
 * Test suite for {@see StatelessApplication} event handling in stateless mode.
 *
 * Verifies correct event registration, handler cleanup, and event firing order in stateless Yii2 applications.
 *
 * Test coverage.
 * - Confirms LIFO cleanup order for registered events and memory management.
 * - Ensures global and internal event handlers are properly managed.
 * - Tests event triggering during request handling and outside request context.
 * - Validates event registration and cleanup between requests.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('http')]
final class ApplicationEventTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws ReflectionException if the property does not exist or is inaccessible.
     */
    public function testEventCleanupOrderMattersForProperMemoryManagement(): void
    {
        $app = $this->statelessApplication();

        // capture the global 'off()' call sequence to validate reverse cleanup order (LIFO)
        $offSequence = [];

        $mockComponent1 = new class ($offSequence) {
            /**
             * @phpstan-var mixed[]
             */
            public array $offCalls = [];

            /**
             * @phpstan-var array<int,string>
             */
            public array $seq = [];

            /**
             * @param array<int,string> $seq
             */
            public function __construct(array &$seq)
            {
                $this->seq = &$seq;
            }

            /**
             * @phpstan-ignore-next-line
             */
            public function on(string $name, callable $handler): void {}

            public function off(string $name): void
            {
                $this->offCalls[] = $name;
                $this->seq[] = $name;
            }
        };

        $mockComponent2 = new class ($offSequence) {
            /**
             * @phpstan-var mixed[]
             */
            public array $offCalls = [];

            /**
             * @phpstan-var array<int,string>
             */
            public array $seq = [];

            /**
             * @param array<int,string> $seq
             */
            public function __construct(array &$seq)
            {
                $this->seq = &$seq;
            }

            /**
             * @phpstan-ignore-next-line
             */
            public function on(string $name, callable $handler): void {}

            public function off(string $name): void
            {
                $this->offCalls[] = $name;
                $this->seq[] = $name;
            }
        };

        /** @phpstan-var Event[] $registeredEvents */
        $registeredEvents = self::inaccessibleProperty($app, 'registeredEvents');

        $event1 = new Event(['name' => 'test.event1', 'sender' => $mockComponent1]);
        $event2 = new Event(['name' => 'test.event2', 'sender' => $mockComponent2]);

        $registeredEvents[] = $event1;
        $registeredEvents[] = $event2;

        self::setInaccessibleProperty($app, 'registeredEvents', $registeredEvents);

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
            1,
            $mockComponent1->offCalls,
            'Expected only one event to be unregistered.',
        );
        self::assertCount(
            1,
            $mockComponent2->offCalls,
            'Expected only one event to be unregistered.',
        );
        self::assertContains(
            'test.event1',
            $mockComponent1->offCalls,
            "Expected event 'test.event1' to be unregistered.",
        );
        self::assertContains(
            'test.event2',
            $mockComponent2->offCalls,
            "Expected event 'test.event2' to be unregistered.",
        );
        self::assertSame(
            ['test.event2', 'test.event1'],
            $offSequence,
            'Events should be unregistered in reverse registration order (LIFO).',
        );
        $this->assertEmptyRegisteredEvents(
            $app,
            'Events should be cleaned after request.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws ReflectionException if the property does not exist or is inaccessible.
     */
    public function testEventRegistrationAndCleanupBetweenRequests(): void
    {
        $app = $this->statelessApplication();

        $eventsCaptured = [];
        $trackedCounts = [];

        $app->on(
            StatelessApplication::EVENT_BEFORE_REQUEST,
            static function (Event $event) use (&$eventsCaptured): void {
                $version = $event->sender instanceof StatelessApplication ? $event->sender->version : 'unknown';
                $eventsCaptured[] = "before_request_{$version}";
            },
        );
        $app->on(
            StatelessApplication::EVENT_AFTER_REQUEST,
            function (Event $event) use (&$eventsCaptured, &$trackedCounts): void {
                $version = $event->sender instanceof StatelessApplication ? $event->sender->version : 'unknown';
                $eventsCaptured[] = "after_request_{$version}";

                /** @var StatelessApplication $sender */
                $sender = $event->sender;

                /** @var mixed[] $registeredEvents */
                $registeredEvents = self::inaccessibleProperty($sender, 'registeredEvents');

                $trackedCounts[] = count($registeredEvents);
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
        self::assertGreaterThanOrEqual(
            2,
            $trackedCounts[0] ?? 0,
            'At least BEFORE/AFTER events should be tracked during the first request.',
        );
        self::assertCount(
            2,
            $eventsCaptured,
            'Expected both events to be triggered.',
        );
        self::assertContains(
            'before_request_0.1.0',
            $eventsCaptured,
            "Expected new before event 'before_request_{$app->version}' to be triggered.",
        );
        self::assertContains(
            'after_request_0.1.0',
            $eventsCaptured,
            "Expected new after event 'after_request_{$app->version}' to be triggered.",
        );
        $this->assertEmptyRegisteredEvents(
            $app,
            'Events should be cleaned after request.',
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
        self::assertCount(
            2,
            $eventsCaptured,
            'No additional BEFORE/AFTER events should be captured on the second request after cleanup.',
        );
        self::assertCount(
            1,
            $trackedCounts,
            'AFTER_REQUEST probe must not run again on the second request (handlers were cleaned).',
        );
        $this->assertEmptyRegisteredEvents(
            $app,
            'Events should be cleaned after request.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws ReflectionException if the property does not exist or is inaccessible.
     */
    public function testGlobalEventCleanupWithoutSystemInterference(): void
    {
        $app = $this->statelessApplication();

        $eventsTriggered = [];

        $app->on(
            StatelessApplication::EVENT_BEFORE_REQUEST,
            static function () use (&$eventsTriggered): void {
                $eventsTriggered[] = 'app_before';
            },
        );
        $app->on(
            StatelessApplication::EVENT_AFTER_REQUEST,
            static function () use (&$eventsTriggered): void {
                $eventsTriggered[] = 'app_after';
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
            $eventsTriggered,
            'Expected both events to be triggered.',
        );
        self::assertContains(
            'app_before',
            $eventsTriggered,
            "Expected new before event 'app_before' to be triggered.",
        );
        self::assertContains(
            'app_after',
            $eventsTriggered,
            "Expected new after event 'app_after' to be triggered.",
        );
        $this->assertEmptyRegisteredEvents(
            $app,
            'Events should be cleaned after request.',
        );

        $eventsTriggered = [];

        $app->on(
            StatelessApplication::EVENT_BEFORE_REQUEST,
            static function () use (&$eventsTriggered): void {
                $eventsTriggered[] = 'app_before_2';
            },
        );

        $response = $app->handle(FactoryHelper::createRequest('GET', 'site/statuscode'));

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
        self::assertCount(
            1,
            $eventsTriggered,
            'Expected only one event to be triggered.',
        );
        self::assertContains(
            'app_before_2',
            $eventsTriggered,
            "Expected new before event 'app_before_2' to be triggered.",
        );
        $this->assertEmptyRegisteredEvents(
            $app,
            'Events should be cleaned after request.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testGlobalEventHandlersAreCleanedAfterRequest(): void
    {
        $globalEventCaptured = [];

        Event::on(
            '*',
            '*',
            static function (Event $event) use (&$globalEventCaptured): void {
                if ($event->name === 'test.global.event') {
                    $globalEventCaptured[] = $event->name;
                }
            },
        );

        $app = $this->statelessApplication();

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

        Event::trigger('', 'test.global.event');

        self::assertEmpty(
            $globalEventCaptured,
            'Global event handlers should be cleaned up after request processing.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws ReflectionException if the property does not exist or is inaccessible.
     */
    public function testInternalComponentEventListenerFiresOutsideRequest(): void
    {
        $internalEventCaptured = false;

        $app = $this->statelessApplication();

        $this->assertEmptyRegisteredEvents(
            $app,
            'Should start with empty registered events.',
        );

        $eventComponent = new EventComponent();

        $app->set('eventComponent', $eventComponent);

        $eventComponent->on(
            EventComponent::EVENT_TEST_INTERNAL,
            static function () use (&$internalEventCaptured): void {
                $internalEventCaptured = true;
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

        $component = $app->get('eventComponent');

        self::assertInstanceOf(
            EventComponent::class,
            $component,
            'Event component should be an instance of EventComponent.',
        );

        $component->triggerTestEvent();

        self::assertTrue(
            $internalEventCaptured,
            'Internal event should be captured.',
        );
        $this->assertEmptyRegisteredEvents(
            $app,
            'Events should be cleaned after request.',
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
            'Expected only one event to be triggered.',
        );
        self::assertContains(
            $eventName,
            $sequence,
            "Expected new event '{$eventName}' to be triggered.",
        );
        self::assertSame(
            $app,
            $sender,
            'Expected event sender to be the application instance.',
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
