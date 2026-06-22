<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPForge\Support\ReflectionHelper;
use PHPUnit\Framework\Attributes\Group;
use ReflectionException;
use RuntimeException;
use yii\base\{Event, InvalidConfigException};
use yii2\extensions\psrbridge\http\Response;
use yii2\extensions\psrbridge\tests\support\{ApplicationFactory, HelperFactory, TestCase};

/**
 * Unit tests for {@see \yii2\extensions\psrbridge\http\Application::finalize()} after-send finalization in stateless
 * mode.
 */
#[Group('http')]
final class ApplicationFinalizeTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws ReflectionException if the property does not exist or is inaccessible.
     */
    public function testFinalizeCleansUpWorkerStateWhenAfterSendHandlerThrows(): void
    {
        Event::on(
            Response::class,
            Response::EVENT_AFTER_SEND,
            static function (): never {
                throw new RuntimeException('after-send failure');
            },
        );

        $app = ApplicationFactory::stateless();

        $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        try {
            $app->finalize();

            self::fail('After-send handler exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame(
                'after-send failure',
                $e->getMessage(),
                'Original after-send exception must propagate.',
            );
        }

        self::assertEmpty(
            ReflectionHelper::inaccessibleProperty($app, 'registeredEvents'),
            'Worker events must be cleaned even when after-send throws.',
        );
        self::assertNull(
            ReflectionHelper::inaccessibleProperty($app, 'lastResponse'),
            'Stored reference must be cleared even when after-send throws.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws ReflectionException if the property does not exist or is inaccessible.
     */
    public function testFinalizeFiresAfterSendAndMarksSentOnSuccessfulEmission(): void
    {
        $afterSend = 0;

        Event::on(
            Response::class,
            Response::EVENT_AFTER_SEND,
            static function () use (&$afterSend): void {
                $afterSend++;
            },
        );

        $app = ApplicationFactory::stateless();

        $response = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response,
        );
        self::assertSame(
            0,
            $afterSend,
            'After-send must be deferred past conversion.',
        );
        self::assertFalse(
            $app->response->isSent,
            'Conversion alone must not mark the response sent.',
        );

        $app->finalize();

        self::assertSame(
            1,
            $afterSend,
            "'EVENT_AFTER_SEND' must fire once on success.",
        );
        self::assertTrue(
            $app->response->isSent,
            'Response must be marked sent after finalization.',
        );
        self::assertEmpty(
            ReflectionHelper::inaccessibleProperty($app, 'registeredEvents'),
            'Worker events must be cleaned on finalization.',
        );
        self::assertNull(
            ReflectionHelper::inaccessibleProperty($app, 'lastResponse'),
            'Stored response reference must be cleared.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws ReflectionException if the property does not exist or is inaccessible.
     */
    public function testFinalizeIsIdempotentAcrossRepeatedCalls(): void
    {
        $afterSend = 0;

        Event::on(
            Response::class,
            Response::EVENT_AFTER_SEND,
            static function () use (&$afterSend): void {
                $afterSend++;
            },
        );

        $app = ApplicationFactory::stateless();

        $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $app->finalize();
        $app->finalize();

        self::assertSame(
            1,
            $afterSend,
            "'EVENT_AFTER_SEND' must fire exactly once.",
        );
        self::assertEmpty(
            ReflectionHelper::inaccessibleProperty($app, 'registeredEvents'),
            'Events must remain clean after repeated calls.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws ReflectionException if the property does not exist or is inaccessible.
     */
    public function testFinalizeIsSafeWithoutPriorRequest(): void
    {
        $afterSend = 0;

        Event::on(
            Response::class,
            Response::EVENT_AFTER_SEND,
            static function () use (&$afterSend): void {
                $afterSend++;
            },
        );

        $app = ApplicationFactory::stateless();

        self::assertTrue(
            $app->flushLogger,
            'Default logger flushing must remain enabled.',
        );

        $app->finalize();

        self::assertSame(
            0,
            $afterSend,
            'No stored response means no after-send.',
        );
        self::assertEmpty(
            ReflectionHelper::inaccessibleProperty($app, 'registeredEvents'),
            'Cleanup must remain a safe no-op.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws ReflectionException if the property does not exist or is inaccessible.
     */
    public function testFinalizeRunsAfterSendOnceLazyStreamBodyIsConsumed(): void
    {
        $afterSend = 0;

        Event::on(
            Response::class,
            Response::EVENT_AFTER_SEND,
            static function () use (&$afterSend): void {
                $afterSend++;
            },
        );

        $app = ApplicationFactory::stateless();

        $response = $app->handle(HelperFactory::createRequest('GET', 'site/stream'));

        self::assertSame(
            'This is a test file content.',
            $response->getBody()->getContents(),
            'Lazy stream body must be fully readable before finalization.',
        );
        self::assertSame(
            0,
            $afterSend,
            'After-send must not run before the body is consumed.',
        );

        $app->finalize();

        self::assertSame(
            1,
            $afterSend,
            'After-send must run once the runtime finalizes.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws ReflectionException if the property does not exist or is inaccessible.
     */
    public function testFinalizeSkipsAfterSendOnFailedEmissionButStillCleansUp(): void
    {
        $afterSend = 0;

        Event::on(
            Response::class,
            Response::EVENT_AFTER_SEND,
            static function () use (&$afterSend): void {
                $afterSend++;
            },
        );

        $app = ApplicationFactory::stateless();

        $response = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response,
        );

        $app->finalize(false);

        self::assertSame(
            0,
            $afterSend,
            "Failed emission must not fire 'EVENT_AFTER_SEND'.",
        );
        self::assertFalse(
            $app->response->isSent,
            'Undelivered response must stay unsent.',
        );
        self::assertEmpty(
            ReflectionHelper::inaccessibleProperty($app, 'registeredEvents'),
            'Worker events must be cleaned even on failure.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws ReflectionException if the property does not exist or is inaccessible.
     */
    public function testFinalizeTargetsEmittedResponseWhenActionReturnsOwnInstance(): void
    {
        $app = ApplicationFactory::stateless();

        $response = $app->handle(HelperFactory::createRequest('GET', 'site/fresh-response'));

        /** @phpstan-var Response $emitted */
        $emitted = ReflectionHelper::inaccessibleProperty($app, 'lastResponse');

        $component = $app->response;

        self::assertNotSame(
            $component,
            $emitted,
            'Action-returned Response must differ from the component instance.',
        );
        self::assertJsonStringEqualsJsonString(
            '{"fresh":true}',
            (string) $response->getBody(),
            'Emitted body must come from the action-returned Response.',
        );
        self::assertFalse(
            $emitted->isSent,
            'Emitted response must be unsent before finalization.',
        );
        self::assertFalse(
            $component->isSent,
            'Component must be unsent before finalization.',
        );

        $app->finalize();

        self::assertTrue(
            $emitted->isSent,
            'Finalization must mark the emitted response sent.',
        );
        self::assertFalse(
            $component->isSent,
            'Component must stay unsent: it was never emitted.',
        );
    }
}
