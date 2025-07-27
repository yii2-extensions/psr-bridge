<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests;

use HttpSoft\Message\{ResponseFactory, StreamFactory};
use Psr\Http\Message\{ResponseFactoryInterface, StreamFactoryInterface};
use RuntimeException;
use Yii;
use yii\caching\FileCache;
use yii\helpers\ArrayHelper;
use yii\log\FileTarget;
use yii\web\JsonParser;
use yii2\extensions\psrbridge\http\StatelessApplication;

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

    protected function closeApplication(): void
    {
        if (Yii::$app->has('session')) {
            Yii::$app->getSession()->close();
        }

        // ensure the logger is flushed after closing the application
        $logger = Yii::getLogger();
        $logger->flush();
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
    protected function statelessApplication($config = []): StatelessApplication
    {
        return new StatelessApplication(
            ArrayHelper::merge(
                [
                    'id' => 'stateless-app',
                    'basePath' => __DIR__,
                    'bootstrap' => ['log'],
                    'controllerNamespace' => '\yii2\extensions\psrbridge\tests\support\stub',
                    'components' => [
                        'cache' => [
                            'class' => FileCache::class,
                        ],
                        'log' => [
                            'traceLevel' => YII_DEBUG ? 3 : 0,
                            'targets' => [
                                [
                                    'class' => FileTarget::class,
                                    'levels' => [
                                        'error',
                                        'info',
                                        'warning',
                                    ],
                                    'logFile' => '@runtime/logs/app.log',
                                ],
                            ],
                        ],
                        'request' => [
                            'enableCookieValidation' => false,
                            'enableCsrfCookie' => false,
                            'enableCsrfValidation' => false,
                            'parsers' => [
                                'application/json' => JsonParser::class,
                            ],
                            'scriptFile' => __DIR__ . '/index.php',
                            'scriptUrl' => '/index.php',
                        ],
                        'response' => [
                            'charset' => 'UTF-8',
                        ],
                        'urlManager' => [
                            'showScriptName' => false,
                            'enableStrictParsing' => false,
                            'enablePrettyUrl' => true,
                            'rules' => [
                                [
                                    'pattern'   => '/<controller>/<action>/<test:\w+>',
                                    'route'     => '<controller>/<action>',
                                ],
                            ],
                        ],
                    ],
                    'container' => [
                        'definitions' => [
                            ResponseFactoryInterface::class => ResponseFactory::class,
                            StreamFactoryInterface::class => StreamFactory::class,
                        ],
                    ],
                    'runtimePath' => dirname(__DIR__) . '/runtime',
                    'vendorPath' => dirname(__DIR__) . '/vendor',
                ],
                $config,
            ),
        );
    }

    /**
     * @phpstan-param array<string, mixed> $config
     */
    protected function webApplication($config = []): void
    {
        new \yii\web\Application(
            ArrayHelper::merge(
                [
                    'id' => 'web-app',
                    'basePath' => __DIR__,
                    'vendorPath' => dirname(__DIR__) . '/vendor',
                    'aliases' => [
                        '@bower' => '@vendor/bower-asset',
                        '@npm' => '@vendor/npm-asset',
                    ],
                    'components' => [
                        'request' => [
                            'cookieValidationKey' => 'wefJDF8sfdsfSDefwqdxj9oq',
                            'isConsoleRequest' => false,
                            'scriptFile' => __DIR__ . '/index.php',
                            'scriptUrl' => '/index.php',
                        ],
                    ],
                ],
                $config,
            ),
        );
    }
}
