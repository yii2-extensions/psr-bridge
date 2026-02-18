<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support;

use HttpSoft\Message\{ResponseFactory, StreamFactory};
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, StreamFactoryInterface};
use RuntimeException;
use Yii;
use yii\base\Security;
use yii\caching\FileCache;
use yii\helpers\ArrayHelper;
use yii\log\FileTarget;
use yii\web\{IdentityInterface, JsonParser};
use yii2\extensions\psrbridge\http\Application;
use yii2\extensions\psrbridge\tests\support\stub\{ApplicationRest, Identity, MockerFunctions};

use function fclose;
use function is_resource;
use function stream_get_meta_data;
use function tmpfile;

/**
 * Base test case providing common helpers and utilities for the test suite.
 *
 * Provides utilities to create and tear down Yii2 stateless and web application instances, manage temporary files used
 * during tests, sign cookies for cookie-validation scenarios, and reset PHP superglobals to ensure test isolation.
 *
 * Tests that require HTTP request/response factories, stream factories or application scaffolding should extend this
 * class.
 *
 * Key features.
 * - Creates `yii2\extensions\psrbridge\http\Application` and `yii\web\Application` instances with a sane test configuration.
 * - Manages temporary file resources and ensures cleanup during `tearDown()`.
 * - Provides `signCookies()` helper for creating signed cookie values.
 * - Resets `$_SERVER`, `$_GET`, `$_POST`, `$_FILES` and `$_COOKIE` between tests to avoid cross-test contamination.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * A secret key used for cookie validation in tests.
     */
    protected const COOKIE_VALIDATION_KEY = 'test-cookie-validation-key';

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

    /**
     * @phpstan-param array<string, mixed> $config
     * @phpstan-return ApplicationRest<IdentityInterface>
     */
    protected function applicationRest(array $config = []): ApplicationRest
    {
        /** @phpstan-var array<string, mixed> $configApplication */
        $configApplication = ArrayHelper::merge(
            [
                'id' => 'stateless-app',
                'basePath' => dirname(__DIR__, 2),
                'controllerNamespace' => '\yii2\extensions\psrbridge\tests\support\stub',
                'components' => [
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
            ],
            $config,
        );

        return new ApplicationRest($configApplication);
    }

    protected function assertSiteIndexJsonResponse(ResponseInterface $response): void
    {
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
    }

    protected function assertSitePostUploadJsonResponse(ResponseInterface $response): void
    {
        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route '/site/post'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route '/site/post'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"action": "upload"}
            JSON,
            $response->getBody()->getContents(),
            "Expected PSR-7 Response body '{\"action\":\"upload\"}'.",
        );
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
     * @param resource ...$tmpFile Temporary file resources to close.
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
     * Creates a temporary file and registers its resource for cleanup.
     *
     * This method creates a new temporary file using the system's temporary directory and stores the file resource in
     * the internal list for later cleanup during test teardown. Returns the file path to the created temporary file.
     *
     * @throws RuntimeException If the temporary file cannot be created.
     * @return string Path to the created temporary file.
     */
    protected function createTmpFile(): string
    {
        $tmpFile = tmpfile();

        if ($tmpFile === false) {
            throw new RuntimeException('Failed to create temporary file.');
        }

        $this->tmpFiles[] = $tmpFile;

        return stream_get_meta_data($tmpFile)['uri'] ?? '';
    }

    protected function setUp(): void
    {
        parent::setUp();

        MockerFunctions::reset();

        $this->originalServer = $_SERVER;

        $_SERVER = [];
    }

    /**
     * @phpstan-param array<string, string|object> $cookieParams
     *
     * @phpstan-return array<string, string>
     */
    protected function signCookies(array $cookieParams): array
    {
        $security = new Security();
        $signed = [];

        foreach ($cookieParams as $name => $value) {
            $signed[$name] = $security->hashData(serialize([$name, $value]), self::COOKIE_VALIDATION_KEY);
        }

        return $signed;
    }

    /**
     * @phpstan-param array<string, mixed> $config
     * @phpstan-return Application<IdentityInterface>
     */
    protected function statelessApplication(array $config = []): Application
    {
        /** @phpstan-var array<string, mixed> $configApplication */
        $configApplication = ArrayHelper::merge(
            [
                'id' => 'stateless-app',
                'basePath' => dirname(__DIR__, 2),
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
            ],
            $config,
        );

        return new Application($configApplication);
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
     * @phpstan-param array<string, mixed> $config
     */
    protected function webApplication(array $config = []): void
    {
        /** @phpstan-var array<string, mixed> $configApplication */
        $configApplication = ArrayHelper::merge(
            [
                'id' => 'web-app',
                'basePath' => dirname(__DIR__, 2),
                'aliases' => [
                    '@bower' => '@vendor/bower-asset',
                    '@npm' => '@vendor/npm-asset',
                ],
                'components' => [
                    'request' => [
                        'cookieValidationKey' => self::COOKIE_VALIDATION_KEY,
                        'isConsoleRequest' => false,
                        'scriptFile' => __DIR__ . '/index.php',
                        'scriptUrl' => '/index.php',
                    ],
                ],
            ],
            $config,
        );

        new \yii\web\Application($configApplication);
    }
}
