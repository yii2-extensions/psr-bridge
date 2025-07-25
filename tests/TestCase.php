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
     * @phpstan-var array<mixed, mixed>
     */
    private array $originalServer = [];

    /**
     * Temporary file resources used during tests.
     *
     * @phpstan-var array<resource>
     */
    private array $tmpFiles = [];

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

        $this->closeTmpFile(...$this->tmpFiles);

        parent::tearDown();
    }

    /**
     * Closes the temporary file resource if it is a valid resource.
     *
     * @param resource ...$tmpFile The temporary file resources to close.
     */
    protected function closeTmpFile(...$tmpFile): void
    {
        foreach ($tmpFile as $file) {
            if (is_resource($file)) {
                fclose($file);
            }
        }
    }

    /**
     * @phpstan-return resource
     */
    protected function createTmpFile()
    {
        $tmpFile = tmpfile();

        if ($tmpFile === false) {
            throw new RuntimeException('Failed to create temporary file.');
        }

        $this->tmpFiles[] = $tmpFile;

        return $tmpFile;
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
