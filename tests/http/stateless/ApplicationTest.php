<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\{Group, RequiresPhpExtension};
use yii\base\InvalidConfigException;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function ini_get;
use function ini_set;
use function ob_get_level;
use function ob_start;

#[Group('http')]
final class ApplicationTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->closeApplication();

        parent::tearDown();
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[RequiresPhpExtension('runkit7')]
    public function testRenderExceptionSetsDisplayErrorsInDebugMode(): void
    {
        @\runkit_constant_redefine('YII_ENV_TEST', false);

        $initialBufferLevel = ob_get_level();

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/trigger-exception',
        ];

        $originalDisplayErrors = ini_get('display_errors');

        try {
            $app = $this->statelessApplication(
                [
                    'components' => [
                        'errorHandler' => ['errorAction' => null],
                    ],
                ],
            );

            $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

            self::assertSame(
                500,
                $response->getStatusCode(),
                "Expected HTTP '500' for route 'site/trigger-exception'.",
            );
            self::assertSame(
                'text/html; charset=UTF-8',
                $response->getHeaderLine('Content-Type'),
                "Expected Content-Type 'text/html; charset=UTF-8' for route 'site/trigger-exception'.",
            );
            self::assertSame(
                '1',
                ini_get('display_errors'),
                "'display_errors' should be set to '1' when YII_DEBUG mode is enabled and rendering exception view.",
            );
            self::assertStringContainsString(
                'yii\base\Exception: Exception error message.',
                $response->getBody()->getContents(),
                'Response should contain exception details when YII_DEBUG mode is enabled.',
            );
        } finally {
            ini_set('display_errors', $originalDisplayErrors);

            while (ob_get_level() < $initialBufferLevel) {
                ob_start();
            }

            @\runkit_constant_redefine('YII_ENV_TEST', true);
        }
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

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            302,
            $response->getStatusCode(),
            "Response 'status code' should be '302' for redirect route 'site/redirect' in 'StatelessApplication'.",
        );
        self::assertSame(
            '/site/index',
            $response->getHeaderLine('Location'),
            "Response 'Location' header should be '/site/index' for redirect route 'site/redirect' in " .
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

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            302,
            $response->getStatusCode(),
            "Response 'status code' should be '302' for redirect route 'site/refresh' in 'StatelessApplication'.",
        );
        self::assertSame(
            'site/refresh#stateless',
            $response->getHeaderLine('Location'),
            "Response 'Location' header should be 'site/refresh#stateless' for redirect route 'site/refresh' in " .
            "'StatelessApplication'.",
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

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            201,
            $response->getStatusCode(),
            "Response 'status code' should be '201' for 'site/statuscode' route in 'StatelessApplication'.",
        );
    }
}
