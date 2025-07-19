<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests;

use Yii;
use yii\helpers\ArrayHelper;
use yii2\extensions\psrbridge\http\Request;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * @phpstan-var array<mixed, mixed>
     */
    private array $originalServer = [];

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        $logger = Yii::getLogger();
        $logger->flush();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalServer = $_SERVER;
        $_SERVER = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_POST = [];
        $_COOKIE = [];

        parent::tearDown();
    }

    /**
     * @phpstan-param array<string, mixed> $config
     */
    protected function mockApplication($config = []): void
    {
        new \yii\console\Application(
            ArrayHelper::merge(
                [
                    'id' => 'testapp',
                    'basePath' => __DIR__,
                    'vendorPath' => dirname(__DIR__) . '/vendor',
                    'components' => [
                        'request' => [
                            'class' => Request::class,
                        ],
                    ],
                ],
                $config,
            ),
        );
    }

    /**
     * @phpstan-param array<string, mixed> $config
     */
    protected function mockWebApplication($config = []): void
    {
        new \yii\web\Application(
            ArrayHelper::merge(
                [
                    'id' => 'testapp',
                    'basePath' => __DIR__,
                    'vendorPath' => dirname(__DIR__) . '/vendor',
                    'aliases' => [
                        '@bower' => '@vendor/bower-asset',
                        '@npm' => '@vendor/npm-asset',
                    ],
                    'components' => [
                        'request' => [
                            'class' => Request::class,
                            'cookieValidationKey' => 'wefJDF8sfdsfSDefwqdxj9oq',
                            'scriptFile' => __DIR__ . '/index.php',
                            'scriptUrl' => '/index.php',
                            'isConsoleRequest' => false,
                        ],
                    ],
                ],
                $config,
            ),
        );
    }
}
