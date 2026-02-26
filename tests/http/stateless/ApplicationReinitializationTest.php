<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\Group;
use Yii;
use yii\base\{Component, InvalidConfigException};
use yii2\extensions\psrbridge\http\Application;
use yii2\extensions\psrbridge\tests\support\{ApplicationFactory, HelperFactory, TestCase};

/**
 * Unit tests for the {@see Application} class reinitialization behavior in stateless mode.
 *
 * Test coverage.
 * - Ensures aliases remain consistent across reinitialization.
 * - Ensures application state reaches `STATE_END` after each handled request.
 * - Ensures custom persistent components keep the same instance across requests.
 * - Verifies built-in persistent components keep the same instance across requests.
 * - Verifies high-volume request handling remains stable during reinitialization.
 * - Verifies request-scoped components are recreated for each request.
 * - Verifies the application recovers after an error on a prior request.
 * - Verifies the Yii::$app reference remains bound to the same application instance.
 *
 * @copyright Copyright (C) 2026 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('http')]
final class ApplicationReinitializationTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testApplicationStateTransitionsCorrectlyDuringReinitialization(): void
    {
        $app = ApplicationFactory::stateless();

        // First request
        $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        self::assertSame(
            Application::STATE_END,
            $app->state,
            'Application state should be STATE_END after first request.',
        );

        // Second request - reinitialization should properly transition states
        $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        self::assertSame(
            Application::STATE_END,
            $app->state,
            'Application state should be STATE_END after subsequent request.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testMultipleRequestsWithReinitialization(): void
    {
        $app = ApplicationFactory::stateless();

        // Simulate multiple requests to verify stability
        for ($i = 0; $i < 10; $i++) {
            $response = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

            $this->assertSiteIndexJsonResponse(
                $response,
            );
            self::assertSame(
                $app,
                Yii::$app,
                "Yii::\$app should remain consistent after request #{$i}.",
            );
        }
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testPersistentComponentsArePreservedAcrossReinitialization(): void
    {
        $app = ApplicationFactory::stateless();

        $response1 = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response1,
        );

        $cache1 = $app->cache;

        // Second request
        $response2 = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response2,
        );

        $cache2 = $app->cache;

        self::assertSame(
            $cache1,
            $cache2,
            'Persistent cache component should maintain the same instance across reinitialization.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReinitializationHandlesErrorsCorrectly(): void
    {
        $app = ApplicationFactory::stateless(
            [
                'components' => [
                    'errorHandler' => ['errorAction' => null],
                ],
            ],
        );

        // First request - success
        $response1 = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response1,
        );

        // Second request - error
        $response2 = $app->handle(HelperFactory::createRequest('GET', 'site/trigger-exception'));

        self::assertSame(
            500,
            $response2->getStatusCode(),
            "Expected HTTP '500' for route 'site/trigger-exception'.",
        );

        // Third request - success again (verify recovery)
        $response3 = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response3,
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReinitializationPreservesAliases(): void
    {
        $app = ApplicationFactory::stateless();

        // First request
        $response1 = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response1,
        );

        $webAlias1 = Yii::getAlias('@web');
        $webrootAlias1 = Yii::getAlias('@webroot');

        // Second request
        $response2 = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response2,
        );

        $webAlias2 = Yii::getAlias('@web');
        $webrootAlias2 = Yii::getAlias('@webroot');

        self::assertSame(
            $webAlias1,
            $webAlias2,
            '@web alias should be preserved across reinitialization.',
        );
        self::assertSame(
            $webrootAlias1,
            $webrootAlias2,
            '@webroot alias should be preserved across reinitialization.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReinitializationWithCustomComponentConfiguration(): void
    {
        $app = ApplicationFactory::stateless(
            [
                'persistentComponents' => ['customPersistent'],
                'components' => [
                    'customPersistent' => [
                        'class' => Component::class,
                    ],
                ],
            ],
        );

        // First request
        $response1 = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response1,
        );

        $customComponent1 = $app->get('customPersistent');

        // Second request
        $response2 = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response2,
        );

        $customComponent2 = $app->get('customPersistent');

        self::assertSame(
            $customComponent1,
            $customComponent2,
            'Custom persistent component should maintain the same instance across reinitialization.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testRequestScopedComponentsAreRecreatedOnEachRequest(): void
    {
        $app = ApplicationFactory::stateless();

        $response1 = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response1,
        );

        $request1 = $app->request;
        $response1Component = $app->response;

        $response2 = $app->handle(HelperFactory::createRequest('GET', 'site/statuscode'));

        self::assertSame(
            201,
            $response2->getStatusCode(),
            "Expected HTTP '201' for route 'site/statuscode'.",
        );

        $request2 = $app->request;
        $response2Component = $app->response;

        self::assertNotSame(
            $request1,
            $request2,
            'Request component should be a new instance on each request.',
        );
        self::assertNotSame(
            $response1Component,
            $response2Component,
            'Response component should be a new instance on each request.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testYiiAppReferenceIsMaintainedAcrossRequests(): void
    {
        $app = ApplicationFactory::stateless();

        $response1 = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response1,
        );
        self::assertSame(
            $app,
            Yii::$app,
            'Yii::$app should reference the same Application instance after first request.',
        );

        $response2 = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response2,
        );
        self::assertSame(
            $app,
            Yii::$app,
            'Yii::$app should reference the same Application instance after subsequent requests.',
        );
    }
}
