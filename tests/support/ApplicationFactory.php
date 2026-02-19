<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support;

use HttpSoft\Message\{ResponseFactory, StreamFactory};
use Psr\Http\Message\{ResponseFactoryInterface, StreamFactoryInterface};
use yii\base\InvalidConfigException;
use yii\caching\FileCache;
use yii\helpers\ArrayHelper;
use yii\log\FileTarget;
use yii\web\{IdentityInterface, JsonParser};
use yii2\extensions\psrbridge\http\Application;
use yii2\extensions\psrbridge\tests\support\stub\{ApplicationRest, Identity};

/**
 * Creates Yii application instances used by tests.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class ApplicationFactory
{
    private const BASE_PATH = __DIR__ . '/../../..';

    private const COOKIE_VALIDATION_KEY = 'test-cookie-validation-key';

    /**
     * Creates a REST-focused test {@see Application} instance.
     *
     * Usage example:
     * ```php
     * ApplicationFactory::rest(['id' => 'rest-test-app']);
     * ```
     *
     * @param array $override Application configuration overrides.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return Application Configured REST test application instance.
     *
     * @phpstan-param array<string, mixed> $override
     * @phpstan-return ApplicationRest<IdentityInterface>
     */
    public static function rest(array $override = []): ApplicationRest
    {
        $config = ArrayHelper::merge(
            self::commonBase(),
            $override,
        );

        return new ApplicationRest($config);
    }

    /**
     * Creates a stateless test {@see Application} instance.
     *
     * Usage example:
     * ```php
     * ApplicationFactory::stateless(['id' => 'stateless-test-app']);
     * ```
     *
     * @param array $override Application configuration overrides.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return Application Configured stateless test application instance.
     *
     * @phpstan-param array<string, mixed> $override
     * @phpstan-return Application<IdentityInterface>
     */
    public static function stateless(array $override = []): Application
    {
        $config = ArrayHelper::merge(
            self::commonBase(),
            [
                'bootstrap' => ['log'],
                'components' => [
                    'cache' => [
                        'class' => FileCache::class,
                    ],
                    'log' => [
                        'traceLevel' => YII_DEBUG ? 3 : 0,
                        'targets' => [
                            [
                                'class' => FileTarget::class,
                                'levels' => ['error', 'info', 'warning'],
                            ],
                        ],
                    ],
                    'user' => [
                        'enableAutoLogin' => false,
                        'identityClass'   => Identity::class,
                    ],
                ],
            ],
            $override,
        );

        return new Application($config);
    }

    /**
     * Boots a Yii web application for test scenarios.
     *
     * Usage example:
     * ```php
     * ApplicationFactory::web(['id' => 'web-test-app']);
     * ```
     *
     * @param array $override Application configuration overrides.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @phpstan-param array<string, mixed> $override
     */
    public static function web(array $override = []): void
    {
        /** @phpstan-var array<string, mixed> $config */
        $config = ArrayHelper::merge(
            self::commonBase(),
            [
                'id' => 'web-test-app',
                'aliases' => [
                    '@bower' => '@vendor/bower-asset',
                    '@npm'   => '@vendor/npm-asset',
                ],
                'components' => [
                    'request' => [
                        'cookieValidationKey' => self::COOKIE_VALIDATION_KEY,
                        'isConsoleRequest'    => false,
                    ],
                ],
            ],
            $override,
        );

        new \yii\web\Application($config);
    }

    /**
     * Common base configuration for all application types.
     *
     * @phpstan-return array<string, mixed>
     */
    private static function commonBase(): array
    {
        return [
            'id' => 'test-app',
            'basePath' => self::BASE_PATH,
            'controllerNamespace' => '\yii2\extensions\psrbridge\tests\support\stub',
            'container' => [
                'definitions' => [
                    ResponseFactoryInterface::class => ResponseFactory::class,
                    StreamFactoryInterface::class  => StreamFactory::class,
                ],
            ],
            'components' => [
                'request' => [
                    'scriptFile' => __DIR__ . '/index.php',
                    'scriptUrl'  => '/index.php',
                    'parsers' => [
                        'application/json' => JsonParser::class,
                    ],
                    'enableCookieValidation' => false,
                    'enableCsrfValidation'   => false,
                    'enableCsrfCookie'       => false,
                ],
                'urlManager' => [
                    'enablePrettyUrl'     => true,
                    'showScriptName'      => false,
                    'enableStrictParsing' => false,
                    'rules' => [
                        'site/query/<test:\w+>'  => 'site/query',
                        'site/update/<id:\d+>'   => 'site/update',
                    ],
                ],
            ],
        ];
    }
}
