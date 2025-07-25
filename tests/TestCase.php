<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests;

use RuntimeException;
use Yii;
use yii\helpers\ArrayHelper;
use yii2\extensions\psrbridge\http\Request;

use function fclose;
use function tmpfile;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * Temporary file one used in tests.
     *
     * @phpstan-var resource|false|null
     */
    protected $tmpFile1 = null;

    /**
     * Temporary file two in tests.
     *
     * @phpstan-var resource|false|null
     */
    protected $tmpFile2 = null;

    /**
     * Temporary file two in tests.
     *
     * @phpstan-var resource|false|null
     */
    protected $tmpFile3 = null;

    /**
     * @phpstan-var array<mixed, mixed>
     */
    private array $originalServer = [];

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        // Ensure the logger is flushed after all tests
        $logger = Yii::getLogger();
        $logger->flush();

        // Close the session if it was started
        if (Yii::$app->has('session')) {
            Yii::$app->getSession()->close();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalServer = $_SERVER;

        $_SERVER = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        $_FILES = [];
        $_GET = [];
        $_POST = [];
        $_SERVER = $this->originalServer;

        if ($this->tmpFile1 !== null && $this->tmpFile1 !== false) {
            fclose($this->tmpFile1);
        }

        if ($this->tmpFile2 !== null && $this->tmpFile2 !== false) {
            fclose($this->tmpFile2);
        }

        if ($this->tmpFile3 !== null && $this->tmpFile3 !== false) {
            fclose($this->tmpFile3);
        }

        parent::tearDown();
    }

    /**
     * @phpstan-return resource
     */
    protected function getTmpFile1()
    {
        $this->tmpFile1 = tmpfile();

        if ($this->tmpFile1 === false || $this->tmpFile1 === null) {
            throw new RuntimeException('Failed to create temporary file one.');
        }

        return $this->tmpFile1;
    }

    /**
     * @phpstan-return resource
     */
    protected function getTmpFile2()
    {
        $this->tmpFile2 = tmpfile();

        if ($this->tmpFile2 === false || $this->tmpFile2 === null) {
            throw new RuntimeException('Failed to create temporary file two.');
        }

        return $this->tmpFile2;
    }

    /**
     * @phpstan-return resource
     */
    protected function getTmpFile3()
    {
        $this->tmpFile3 = tmpfile();

        if ($this->tmpFile3 === false || $this->tmpFile3 === null) {
            throw new RuntimeException('Failed to create temporary file two.');
        }

        return $this->tmpFile3;
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
