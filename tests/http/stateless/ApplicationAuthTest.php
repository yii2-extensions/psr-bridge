<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group};
use yii\base\InvalidConfigException;
use yii2\extensions\psrbridge\tests\provider\ApplicationProvider;
use yii2\extensions\psrbridge\tests\support\{FactoryHelper, TestCase};

/**
 * Unit tests for {@see \yii2\extensions\psrbridge\http\Application} authentication handling in stateless mode.
 *
 * Test coverage.
 * - Ensures credentials are extracted from the Authorization header.
 * - Verifies partial credentials when only PHP_AUTH_USER is present.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('http')]
final class ApplicationAuthTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[DataProviderExternal(ApplicationProvider::class, 'authCredentials')]
    public function testJsonBodyContainsCredentialsFromAuthorizationHeader(
        string $httpAuthorization,
        string $expectedJson,
        string $expectedAssertMessage,
    ): void {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => $httpAuthorization,
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/auth',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/auth'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/auth'.",
        );
        self::assertJsonStringEqualsJsonString(
            $expectedJson,
            $response->getBody()->getContents(),
            $expectedAssertMessage,
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnPartialCredentialsWhenOnlyUsernameIsPresent(): void
    {
        $_SERVER = [
            'PHP_AUTH_USER' => 'admin',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/auth',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/auth'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/auth'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"username":"admin","password":null}
            JSON,
            $response->getBody()->getContents(),
            "Expected JSON body with 'username' and 'null' password when only PHP_AUTH_USER is present.",
        );
    }
}
