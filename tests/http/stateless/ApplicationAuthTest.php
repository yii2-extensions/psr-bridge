<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group};
use yii\base\InvalidConfigException;
use yii2\extensions\psrbridge\tests\provider\StatelessApplicationProvider;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

#[Group('http')]
final class ApplicationAuthTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[DataProviderExternal(StatelessApplicationProvider::class, 'authCredentials')]
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
            "Response 'status code' should be '200' for 'site/auth' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/auth' route in " .
            "'StatelessApplication'.",
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
            "Response 'status code' should be '200' for 'site/auth' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/auth' route in " .
            "'StatelessApplication'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"username":"admin","password":null}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should be a JSON string with 'username' and 'password' as 'null' for 'PHP_AUTH_USER' " .
            "header in 'site/auth' route in 'StatelessApplication'.",
        );
    }
}
