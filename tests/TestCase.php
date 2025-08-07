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
use yii\web\{Application, JsonParser};
use yii2\extensions\psrbridge\http\StatelessApplication;
use yii2\extensions\psrbridge\tests\support\stub\Identity;

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
            $session = Yii::$app->getSession();

            if ($session->getIsActive()) {
                $session->destroy();
                $session->close();
            }
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
    protected function statelessApplication(array $config = []): StatelessApplication
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
                                    'logFile' => '@runtime/log/app.log',
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
                        'user' => [
                            'enableAutoLogin' => false,
                            'identityClass' => Identity::class,
                        ],
                        'urlManager' => [
                            'showScriptName' => false,
                            'enableStrictParsing' => false,
                            'enablePrettyUrl' => true,
                            'rules' => [
                                'site/query/<test:\w+>' => 'site/query',
                                'site/update/<id:\d+>' => 'site/update',
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
    protected function webApplication(array $config = []): void
    {
        new Application(
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
