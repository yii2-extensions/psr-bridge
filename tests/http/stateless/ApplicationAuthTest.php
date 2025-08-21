<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\Group;
use yii\base\InvalidConfigException;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function base64_encode;

#[Group('http')]
final class ApplicationAuthTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnCredentialsWhenValidBasicAuthorizationHeaderIsPresent(): void
    {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('user:pass'),
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
            {"username":"user","password":"pass"}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should be a JSON string with 'username' and 'password' for 'site/auth' route in " .
            "'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnCredentialsWithMultibyteCharacters(): void
    {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => "basic\xC2\xA0" . base64_encode('user:pass'),
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
            {"username":"user","password":"pass"}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should be a JSON string with 'username' and 'password' for 'site/auth' route in " .
            "'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnJsonResponseWithCredentialsForSiteAuthRoute(): void
    {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('admin:admin'),
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
            {"username":"admin","password":"admin"}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should be a JSON string with 'username' and 'password' for 'site/auth' route in " .
            "'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnJsonResponseWithNullCredentialsForMalformedAuthorizationHeader(): void
    {
        $_SERVER = [
            'HTTP_authorization' => 'Basic foo:bar',
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
            {"username":null,"password":null}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should be a JSON string with 'username' and 'password' as 'null' for malformed " .
            "'HTTP_authorization' header in 'site/auth' route in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnNullCredentialsWhenAuthorizationHeaderHasInvalidBasicPrefix(): void
    {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => 'basix ' . base64_encode('user:pass'),
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
            {"username":null,"password":null}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should be a JSON string with 'username' and 'password' as 'null' for invalid " .
            "'HTTP_AUTHORIZATION' header in 'site/auth' route in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnNullCredentialsWhenBasicAuthorizationHeaderHasInvalidBase64DueToMissingSpace(): void
    {
        $criticalBase64 = base64_encode('a:b'); // "YTpi"

        $_SERVER = [
            'HTTP_AUTHORIZATION' => "Basic{$criticalBase64}", // "BasicYTpi"
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
            {"username":null,"password":null}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should be a JSON string with 'username' and 'password' as 'null' for malformed " .
            "'HTTP_AUTHORIZATION' header in 'site/auth' route in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnNullCredentialsWhenBasicAuthorizationHeaderLacksSpace(): void
    {
        $base64Token = base64_encode('user:pass'); // 'dXNlcjpwYXNz'

        $_SERVER = [
            'HTTP_AUTHORIZATION' => "Basic{$base64Token}", // 'BasicdXNlcjpwYXNz'
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
            {"username":null,"password":null}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should be a JSON string with 'username' and 'password' as 'null' for malformed " .
            "'HTTP_AUTHORIZATION' header in 'site/auth' route in 'StatelessApplication'.",
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
        self::assertSame(
            <<<JSON
            {"username":"admin","password":null}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should be a JSON string with 'username' and 'password' as 'null' for 'PHP_AUTH_USER' " .
            "header in 'site/auth' route in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnUsernameOnlyWhenNoColonSeparatorInCredentials(): void
    {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('usernameonly'),
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
        self::assertSame(
            <<<JSON
            {"username":"usernameonly","password":null}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should be a JSON string with 'username' and 'password' as 'null' for " .
            "'HTTP_AUTHORIZATION' header in 'site/auth' route in 'StatelessApplication'.",
        );
    }
}
