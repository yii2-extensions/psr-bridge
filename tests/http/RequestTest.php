<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group, TestWith};
use stdClass;
use Yii;
use yii\base\InvalidConfigException;
use yii\web\JsonParser;
use yii2\extensions\psrbridge\http\Request;
use yii2\extensions\psrbridge\tests\provider\RequestProvider;
use yii2\extensions\psrbridge\tests\TestCase;

#[Group('http')]
final class RequestTest extends TestCase
{
    /**
     * @phpstan-param string[] $trustedHosts
     */
    #[DataProviderExternal(RequestProvider::class, 'alreadyResolvedIp')]
    public function testAlreadyResolvedIp(
        string $remoteAddress,
        string $xForwardedFor,
        string $xForwardedProto,
        array $trustedHosts,
        string $expectedRemoteAddress,
        string $expectedUserIp,
        bool $expectedIsSecureConnection,
    ): void {
        $_SERVER['REMOTE_ADDR'] = $remoteAddress;
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $xForwardedFor;
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = $xForwardedProto;

        $request = new Request(
            [
                'trustedHosts' => $trustedHosts,
                'ipHeaders' => [],
            ],
        );

        self::assertSame($expectedRemoteAddress, $request->remoteIP, 'Remote IP fail!.');
        self::assertSame($expectedUserIp, $request->userIP, 'User IP fail!.');
        self::assertSame($expectedIsSecureConnection, $request->isSecureConnection, 'Secure connection fail!.');
    }

    public function testCsrfHeaderValidation(): void
    {
        $this->mockWebApplication();

        $request = new Request();

        $request->validateCsrfHeaderOnly = true;
        $request->enableCsrfValidation = true;

        // only accept valid header on unsafe requests
        foreach (['GET', 'HEAD', 'POST'] as $method) {
            $_SERVER['REQUEST_METHOD'] = $method;

            $request->headers->remove(Request::CSRF_HEADER);

            self::assertFalse(
                $request->validateCsrfToken(),
                "'CSRF' token validation should fail when the 'CSRF' header is missing for unsafe 'HTTP' methods.",
            );

            $request->headers->add(Request::CSRF_HEADER, '');

            self::assertTrue(
                $request->validateCsrfToken(),
                "'CSRF' token validation should pass when the 'CSRF' header is present for unsafe 'HTTP' methods.",
            );
        }

        // accept no value on other requests
        foreach (['DELETE', 'PATCH', 'PUT', 'OPTIONS'] as $method) {
            $_SERVER['REQUEST_METHOD'] = $method;

            self::assertTrue(
                $request->validateCsrfToken(),
                "'CSRF' token validation should pass for safe 'HTTP' methods regardless of 'CSRF' header.",
            );
        }
    }

    /**
     * @see https://github.com/yiisoft/yii2/issues/14542
     */
    public function testCsrfTokenContainsASCIIOnly(): void
    {
        $this->mockWebApplication();

        $request = new Request();

        $request->enableCsrfCookie = false;

        $token = $request->getCsrfToken();

        self::assertNotNull(
            $token,
            "'CSRF' token should not be null after generation.",
        );
        self::assertMatchesRegularExpression(
            '~[-_=a-z0-9]~i',
            $token,
            "'CSRF' token should only contain ASCII characters ('a-z', '0-9', '-', '_', '=').",
        );
    }

    /**
     * Test CSRF token validation by POST param.
     */
    public function testCsrfTokenHeader(): void
    {
        $this->mockWebApplication();

        $request = new Request();

        $request->enableCsrfCookie = false;

        $token = $request->getCsrfToken();

        // accept no value on GET request
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $_POST[$request->methodParam] = $method;

            self::assertTrue(
                $request->validateCsrfToken(),
                "'CSRF' token validation should pass for safe 'HTTP' methods ('GET', 'HEAD', 'OPTIONS') even " .
                'if no token is provided.',
            );
        }

        // only accept valid token on POST
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            $_POST[$request->methodParam] = $method;

            $request->setBodyParams([]);
            $request->headers->remove(Request::CSRF_HEADER);

            self::assertFalse(
                $request->validateCsrfToken(),
                "'CSRF' token validation should fail for unsafe 'HTTP' methods ('POST', 'PUT', 'DELETE') if no " .
                'token is provided.',
            );
            self::assertNotNull(
                $token,
                "'CSRF' token should not be 'null' after generation.",
            );

            $request->headers->add(Request::CSRF_HEADER, $token);

            self::assertTrue(
                $request->validateCsrfToken(),
                "'CSRF' token validation should pass for unsafe 'HTTP' methods ('POST', 'PUT', 'DELETE') if a " .
                'valid token is provided in the header.',
            );
        }
    }

    /**
     * Test CSRF token validation by POST param.
     */
    public function testCsrfTokenPost(): void
    {
        $this->mockWebApplication();

        $request = new Request();

        $request->enableCsrfCookie = false;

        $token = $request->getCsrfToken();

        // accept no value on GET request
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $_POST[$request->methodParam] = $method;

            self::assertTrue(
                $request->validateCsrfToken(),
                "'CSRF' token validation should pass for safe 'HTTP' methods ('GET', 'HEAD', 'OPTIONS') even if no " .
                "token is provided in 'POST' params.",
            );
        }

        // only accept valid token on POST
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            $_POST[$request->methodParam] = $method;

            $request->setBodyParams([]);

            self::assertFalse(
                $request->validateCsrfToken(),
                "'CSRF' token validation should fail for unsafe 'HTTP' methods ('POST', 'PUT', 'DELETE') if no " .
                "token is provided in 'POST' params.",
            );

            $request->setBodyParams([$request->csrfParam => $token]);

            self::assertTrue(
                $request->validateCsrfToken(),
                "'CSRF' token validation should pass for unsafe 'HTTP' methods ('POST', 'PUT', 'DELETE') if a " .
                "valid token is provided in 'POST' params.",
            );
        }
    }

    public function testCsrfTokenValidation(): void
    {
        $this->mockWebApplication();

        $request = new Request();

        $request->enableCsrfCookie = false;

        $token = $request->getCsrfToken();

        // accept any value if CSRF validation is disabled
        $request->enableCsrfValidation = false;

        self::assertTrue(
            $request->validateCsrfToken($token),
            "'CSRF' token validation should pass for any value if 'CSRF' validation is disabled.",
        );
        self::assertTrue(
            $request->validateCsrfToken($token . 'a'),
            "'CSRF' token validation should pass for any value if 'CSRF' validation is disabled.",
        );
        self::assertTrue(
            $request->validateCsrfToken(null),
            "'CSRF' token validation should pass for 'null' value if 'CSRF' validation is disabled.",
        );

        // enable validation
        $request->enableCsrfValidation = true;

        // accept any value on GET request
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $_POST[$request->methodParam] = $method;

            self::assertTrue(
                $request->validateCsrfToken($token),
                "'CSRF' token validation should pass for valid token on safe 'HTTP' methods ('GET', 'HEAD', 'OPTIONS').",
            );
            self::assertTrue(
                $request->validateCsrfToken($token . 'a'),
                "'CSRF' token validation should pass for any value on safe 'HTTP' methods ('GET', 'HEAD', 'OPTIONS').",
            );
            self::assertTrue(
                $request->validateCsrfToken(null),
                "'CSRF' token validation should pass for 'null' value on safe 'HTTP' methods ('GET', 'HEAD', 'OPTIONS').",
            );
        }

        // only accept valid token on POST
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            $_POST[$request->methodParam] = $method;

            self::assertTrue(
                $request->validateCsrfToken($token),
                "'CSRF' token validation should pass for valid token on unsafe 'HTTP' methods ('POST', 'PUT', 'DELETE').",
            );
            self::assertFalse(
                $request->validateCsrfToken($token . 'a'),
                "'CSRF' token validation should fail for invalid token on unsafe 'HTTP' methods ('POST', 'PUT', 'DELETE').",
            );
            self::assertFalse(
                $request->validateCsrfToken(null),
                "'CSRF' token validation should fail for 'null' value on unsafe 'HTTP' methods ('POST', 'PUT', 'DELETE').",
            );
        }
    }

    public function testCustomHeaderCsrfHeaderValidation(): void
    {
        $this->mockWebApplication();

        $request = new Request();
        $request->csrfHeader = 'X-JGURDA';
        $request->validateCsrfHeaderOnly = true;
        $request->enableCsrfValidation = true;

        // only accept valid header on unsafe requests
        foreach (['GET', 'HEAD', 'POST'] as $method) {
            $_SERVER['REQUEST_METHOD'] = $method;

            $request->headers->remove('X-JGURDA');

            self::assertFalse(
                $request->validateCsrfToken(),
                "'CSRF' token validation should fail when the custom 'CSRF' header is missing for unsafe 'HTTP' methods.",
            );

            $request->headers->add('X-JGURDA', '');

            self::assertTrue(
                $request->validateCsrfToken(),
                "'CSRF' token validation should pass when the custom 'CSRF' header is present for unsafe 'HTTP' methods.",
            );
        }
    }

    public function testCustomSafeMethodsCsrfTokenValidation(): void
    {
        $this->mockWebApplication();

        $request = new Request();

        $request->csrfTokenSafeMethods = ['OPTIONS'];
        $request->enableCsrfCookie = false;
        $request->enableCsrfValidation = true;

        $token = $request->getCsrfToken();

        // accept any value on custom safe request
        foreach (['OPTIONS'] as $method) {
            $_SERVER['REQUEST_METHOD'] = $method;

            self::assertTrue(
                $request->validateCsrfToken($token),
                "'CSRF' token validation should pass for valid token on custom safe 'HTTP' methods ('OPTIONS').",
            );
            self::assertTrue(
                $request->validateCsrfToken($token . 'a'),
                "'CSRF' token validation should pass for any value on custom safe 'HTTP' methods ('OPTIONS').",
            );
            self::assertTrue(
                $request->validateCsrfToken(null),
                "'CSRF' token validation should pass for 'null' value on custom safe 'HTTP' methods ('OPTIONS').",
            );
            self::assertTrue(
                $request->validateCsrfToken(),
                "'CSRF' token validation should pass when no token is provided on custom safe 'HTTP' methods ('OPTIONS').",
            );
        }

        // only accept valid token on other requests
        foreach (['GET', 'HEAD', 'POST'] as $method) {
            $_SERVER['REQUEST_METHOD'] = $method;

            self::assertTrue(
                $request->validateCsrfToken($token),
                "'CSRF' token validation should pass for valid token on 'HTTP' methods ('GET', 'HEAD', 'POST').",
            );
            self::assertFalse(
                $request->validateCsrfToken($token . 'a'),
                "'CSRF' token validation should fail for invalid token on 'HTTP' methods ('GET', 'HEAD', 'POST').",
            );
            self::assertFalse(
                $request->validateCsrfToken(null),
                "'CSRF' token validation should fail for 'null' value on 'HTTP' methods ('GET', 'HEAD', 'POST').",
            );
            self::assertFalse(
                $request->validateCsrfToken(),
                "'CSRF' token validation should fail when no token is provided on 'HTTP' methods ('GET', 'HEAD', 'POST').",
            );
        }
    }

    public function testCustomUnsafeMethodsCsrfHeaderValidation(): void
    {
        $this->mockWebApplication();

        $request = new Request();

        $request->csrfHeaderUnsafeMethods = ['POST'];
        $request->validateCsrfHeaderOnly = true;
        $request->enableCsrfValidation = true;

        // only accept valid custom header on unsafe requests
        foreach (['POST'] as $method) {
            $_SERVER['REQUEST_METHOD'] = $method;

            $request->headers->remove(Request::CSRF_HEADER);

            self::assertFalse(
                $request->validateCsrfToken(),
                "'CSRF' token validation should fail when the custom header is missing for unsafe 'HTTP' methods " .
                "('POST').",
            );

            $request->headers->add(Request::CSRF_HEADER, '');

            self::assertTrue(
                $request->validateCsrfToken(),
                "'CSRF' token validation should pass when the custom header is present for unsafe 'HTTP' methods " .
                "('POST').",
            );
        }

        // accept no value on other requests
        foreach (['GET', 'HEAD'] as $method) {
            $_SERVER['REQUEST_METHOD'] = $method;

            $request->headers->remove(Request::CSRF_HEADER);

            self::assertTrue(
                $request->validateCsrfToken(),
                "'CSRF' token validation should pass for safe 'HTTP' methods ('GET', 'HEAD') regardless of custom " .
                'header presence.',
            );
        }
    }

    public function testForwardedNotTrusted(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.168.10.10';
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['HTTP_FORWARDED'] = 'for=8.8.8.8;host=spoofed.host;proto=https';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'yiiframework.com';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';

        $request = new Request(
            [
                'trustedHosts' => [
                    '192.168.10.0/24',
                    '192.168.20.0/24',
                ],
                'secureHeaders' => [
                    'X-Forwarded-For',
                    'X-Forwarded-Host',
                    'X-Forwarded-Proto',
                ],
            ],
        );

        self::assertSame('10.0.0.1', $request->userIP, 'User IP fail!.');
        self::assertSame('http://yiiframework.com', $request->hostInfo, 'Host info fail!.');
    }

    public function testGetBodyParam(): void
    {
        $request = new Request();

        $request->setBodyParams(
            [
                'someParam' => 'some value',
                'param.dot' => 'value.dot',
            ],
        );

        self::assertSame(
            'value.dot',
            $request->getBodyParam('param.dot'),
            "'getBodyParam()' should return the correct value for a parameter with a dot in its name.",
        );
        self::assertSame(
            null,
            $request->getBodyParam('unexisting'),
            "'getBodyParam()' should return 'null' for a non-existing parameter.",
        );
        self::assertSame(
            'default',
            $request->getBodyParam('unexisting', 'default'),
            "'getBodyParam()' should return the default value when the parameter does not exist.",
        );

        // @see https://github.com/yiisoft/yii2/issues/14135
        $bodyParams = new stdClass();

        $bodyParams->someParam = 'some value';
        $bodyParams->{'param.dot'} = 'value.dot';

        $request->setBodyParams($bodyParams);

        self::assertSame(
            'some value',
            $request->getBodyParam('someParam'),
            "'getBodyParam()' should return the correct value for an existing parameter in 'stdClass'.",
        );
        self::assertSame(
            'value.dot',
            $request->getBodyParam('param.dot'),
            "'getBodyParam()' should return the correct value for a parameter with a dot in its name in 'stdClass'.",
        );
        self::assertSame(
            null,
            $request->getBodyParam('unexisting'),
            "'getBodyParam()' should return 'null' for a non-existing parameter in 'stdClass'.",
        );
        self::assertSame(
            'default',
            $request->getBodyParam('unexisting', 'default'),
            "'getBodyParam()' should return the default value when the parameter does not exist in 'stdClass'.",
        );
    }

    /**
     * @phpstan-param array<string, mixed> $expected
     */
    #[DataProviderExternal(RequestProvider::class, 'getBodyParams')]
    public function testGetBodyParams(string $contentType, string $rawBody, array $expected): void
    {
        $_SERVER['CONTENT_TYPE'] = $contentType;

        $request = new Request();

        $request->parsers = [
            'application/json' => JsonParser::class,
            'application/javascript' => JsonParser::class,
        ];

        $request->setRawBody($rawBody);

        self::assertSame(
            $expected,
            $request->getBodyParams(),
            "'getBodyParams()' should return the expected array for the provided content type and raw body.",
        );
    }

    public function testGetCsrfTokenFromHeaderUsesParentWhenAdapterIsNull(): void
    {
        $this->mockWebApplication();

        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'parent-csrf-token-456';

        $request = new Request();

        $request->csrfHeader = 'X-CSRF-Token';

        $request->reset();
        $result = $request->getCsrfTokenFromHeader();

        self::assertNotNull($result, "Should return result from parent when adapter is 'null'.");

        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    /**
     * @phpstan-param array<int, array{array<string, string>|array<string, mixed>}> $server
     * @phpstan-param array<array{string|null, string|null}> $expected
     */
    #[DataProviderExternal(RequestProvider::class, 'getHostInfo')]
    public function testGetHostInfo(array $server, array $expected): void
    {
        $original = $_SERVER;
        $_SERVER = $server;

        $request = new Request(
            [
                'trustedHosts' => [
                    '192.168.0.0/24',
                ],
                'secureHeaders' => [
                    'X-Forwarded-For',
                    'X-Forwarded-Host',
                    'X-Forwarded-Proto',
                    'forwarded',
                ],
            ],
        );

        self::assertEquals(
            $expected[0] ?? null,
            $request->getHostInfo(),
            "'getHostInfo()' should return the expected value for the given 'secureHeaders' and 'trustedHosts' " .
            'configuration.',
        );
        self::assertEquals(
            $expected[1] ?? null,
            $request->getHostName(),
            "'getHostName()' should return the expected value for the given 'secureHeaders' and 'trustedHosts' " .
            'configuration.',
        );

        $request = new Request(
            [
                'trustedHosts' => [
                    '192.168.0.0/24' => [
                        'X-Forwarded-Host',
                        'forwarded',
                    ],
                ],
                'secureHeaders' => [
                    'X-Forwarded-For',
                    'X-Forwarded-Host',
                    'X-Forwarded-Proto',
                    'forwarded',
                ],
            ],
        );

        self::assertEquals(
            $expected[0] ?? null,
            $request->getHostInfo(),
            "'getHostInfo()' should return the expected value when 'trustedHosts' is an associative array.",
        );
        self::assertEquals(
            $expected[1] ?? null,
            $request->getHostName(),
            "'getHostName()' should return the expected value when 'trustedHosts' is an associative array.",
        );

        $_SERVER = $original;
    }

    /**
     * @phpstan-param array<array{array<string, string>}> $server
     */
    #[DataProviderExternal(RequestProvider::class, 'getIsAjax')]
    public function testGetIsAjax(array $server, bool $expected): void
    {
        $original = $_SERVER;
        $_SERVER = $server;

        $request = new Request();

        self::assertEquals(
            $expected,
            $request->getIsAjax(),
            '\'getIsAjax()\' should return the expected value based on the simulated \'$_SERVER\' input.',
        );

        $_SERVER = $original;
    }

    /**
     * @phpstan-param array<array{array<string, string>}> $server
     */
    #[DataProviderExternal(RequestProvider::class, 'getIsPjax')]
    public function testGetIsPjax(array $server, bool $expected): void
    {
        $original = $_SERVER;
        $_SERVER = $server;

        $request = new Request();

        self::assertEquals(
            $expected,
            $request->getIsPjax(),
            '\'getIsPjax()\' should return the expected value based on the simulated \'$_SERVER\' input.',
        );

        $_SERVER = $original;
    }

    /**
     * @phpstan-param array<array{array<string, string>}> $server
     */
    #[DataProviderExternal(RequestProvider::class, 'isSecureServer')]
    public function testGetIsSecureConnection(array $server, bool $expected): void
    {
        $original = $_SERVER;
        $_SERVER = $server;

        $request = new Request(
            [
                'trustedHosts' => [
                    '192.168.0.0/24',
                ],
                'secureHeaders' => [
                    'Front-End-Https',
                    'X-Rewrite-Url',
                    'X-Forwarded-For',
                    'X-Forwarded-Host',
                    'X-Forwarded-Proto',
                    'forwarded',
                ],
            ],
        );

        self::assertEquals(
            $expected,
            $request->getIsSecureConnection(),
            "'getIsSecureConnection()' should return the expected value for the given 'secureHeaders' and " .
            "'trustedHosts'.",
        );

        $request = new Request(
            [
                'trustedHosts' => [
                    '192.168.0.0/24' => [
                        'Front-End-Https',
                        'X-Forwarded-Proto',
                        'forwarded',
                    ],
                ],
                'secureHeaders' => [
                    'Front-End-Https',
                    'X-Rewrite-Url',
                    'X-Forwarded-For',
                    'X-Forwarded-Host',
                    'X-Forwarded-Proto',
                    'forwarded',
                ],
            ],
        );

        self::assertEquals(
            $expected,
            $request->getIsSecureConnection(),
            "'getIsSecureConnection()' should return the expected value for the associative 'trustedHosts' and " .
            "'secureHeaders'.",
        );

        $_SERVER = $original;
    }

    /**
     * @phpstan-param array<array{array<string, string>}> $server
     */
    #[DataProviderExternal(RequestProvider::class, 'isSecureServerWithoutTrustedHost')]
    public function testGetIsSecureConnectionWithoutTrustedHost(array $server, bool $expected): void
    {
        $original = $_SERVER;
        $_SERVER = $server;

        $request = new Request(
            [
                'trustedHosts' => [
                    '192.168.0.0/24' => [
                        'Front-End-Https',
                        'X-Forwarded-Proto',
                    ],
                ],
                'secureHeaders' => [
                    'Front-End-Https',
                    'X-Rewrite-Url',
                    'X-Forwarded-For',
                    'X-Forwarded-Host',
                    'X-Forwarded-Proto',
                    'forwarded',
                ],
            ],
        );

        self::assertEquals(
            $expected,
            $request->getIsSecureConnection(),
            "'getIsSecureConnection()' should return the expected value for the associative 'trustedHosts' and " .
            "'secureHeaders'.",
        );

        $_SERVER = $original;
    }

    /**
     * @phpstan-param array<array{array<string, string>}> $server
     * @phpstan-param string $expected
     */
    #[DataProviderExternal(RequestProvider::class, 'getMethod')]
    public function testGetMethod(array $server, string $expected): void
    {
        $original = $_SERVER;
        $_SERVER = $server;

        $request = new Request();

        self::assertEquals(
            $expected,
            $request->getMethod(),
            '\'getMethod()\' should return the expected value based on the simulated \'$_SERVER\' input.',
        );

        $_SERVER = $original;
    }

    public function testGetOrigin(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://www.w3.org';

        $request = new Request();

        self::assertEquals(
            'https://www.w3.org',
            $request->getOrigin(),
            "'getOrigin()' should return the correct origin when 'HTTP_ORIGIN' is set.",
        );

        unset($_SERVER['HTTP_ORIGIN']);

        $request = new Request();

        self::assertNull(
            $request->getOrigin(),
            "'getOrigin()' should return 'null' when 'HTTP_ORIGIN' is not set.",
        );
    }

    public function testGetQueryStringWhenEmpty(): void
    {
        $_SERVER['QUERY_STRING'] = '';

        $request = new Request();

        self::assertEmpty(
            $request->getQueryString(),
            'Query string should be empty when \'$_SERVER[\'QUERY_STRING\']\' is empty.',
        );

        unset($_SERVER['QUERY_STRING']);
    }

    public function testGetQueryStringWhenNotSet(): void
    {
        unset($_SERVER['QUERY_STRING']);

        $request = new Request();

        self::assertEmpty(
            $request->getQueryString(),
            'Query string should be empty when \'$_SERVER[\'QUERY_STRING\']\' is not set.',
        );
    }

    /**
     * @phpstan-param string $expectedString
     */
    #[DataProviderExternal(RequestProvider::class, 'getQueryString')]
    public function testGetQueryStringWithVariousParams(string $queryString, string $expectedString): void
    {
        $_SERVER['QUERY_STRING'] = $queryString;

        $request = new Request();

        self::assertSame(
            $expectedString,
            $request->getQueryString(),
            "Query string should match the expected value for: '{$queryString}'.",
        );

        unset($_SERVER['QUERY_STRING']);
    }

    public function testGetScriptFileWithEmptyServer(): void
    {
        $request = new Request();

        $_SERVER = [];

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Unable to determine the entry script file path.');

        $request->getScriptFile();
    }

    public function testGetScriptUrlWithEmptyServer(): void
    {
        $request = new Request();

        $_SERVER = [];

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Unable to determine the entry script file path.');

        $request->getScriptUrl();
    }

    public function testGetServerName(): void
    {
        $request = new Request();

        $_SERVER['SERVER_NAME'] = 'servername';

        self::assertEquals(
            'servername',
            $request->getServerName(),
            '\'getServerName()\' should return the value of \'$_SERVER[\'SERVER_NAME\']\' when it is set.',
        );

        unset($_SERVER['SERVER_NAME']);

        self::assertNull(
            $request->getServerName(),
            '\'getServerName()\' should return \'null\' when \'$_SERVER[\'SERVER_NAME\']\' is not set.',
        );
    }

    public function testGetServerPort(): void
    {
        $request = new Request();

        $_SERVER['SERVER_PORT'] = 33;

        self::assertEquals(
            33,
            $request->getServerPort(),
            '\'getServerPort()\' should return the value of \'$_SERVER[\'SERVER_PORT\']\' when it is set.',
        );

        unset($_SERVER['SERVER_PORT']);

        self::assertNull(
            $request->getServerPort(),
            '\'getServerPort()\' should return \'null\' when $_SERVER[\'SERVER_PORT\'] is not set.',
        );
    }

    public function testGetUploadedFiles(): void
    {
        $request = new Request();

        self::assertEmpty(
            $request->getUploadedFiles(),
            "'getUploadedFiles()' should return an empty array when not set up for PSR7 request handling.",
        );
    }

    public function testGetUrlWhenRequestUriIsSet(): void
    {
        $_SERVER['REQUEST_URI'] = '/search?q=hello+world&category=books&price[min]=10&price[max]=50';

        $request = new Request();

        self::assertSame(
            '/search?q=hello+world&category=books&price[min]=10&price[max]=50',
            $request->getUrl(),
            'URL should match the value of \'REQUEST_URI\' when it is set.',
        );

        unset($_SERVER['REQUEST_URI']);
    }

    public function testGetUrlWithRootPath(): void
    {
        $_SERVER['REQUEST_URI'] = '/';

        $request = new Request();

        self::assertSame(
            '/',
            $request->getUrl(),
            "URL should return 'root' path when 'REQUEST_URI' is set to 'root'.",
        );

        unset($_SERVER['REQUEST_URI']);
    }

    /**
     * @phpstan-param array<array{array<string, string>}> $server
     * @phpstan-param string $expected
     */
    #[DataProviderExternal(RequestProvider::class, 'getUserIP')]
    public function testGetUserIP(array $server, string $expected): void
    {
        $original = $_SERVER;
        $_SERVER = $server;

        $request = new Request(
            [
                'trustedHosts' => [
                    '192.168.0.0/24',
                ],
                'secureHeaders' => [
                    'Front-End-Https',
                    'X-Rewrite-Url',
                    'X-Forwarded-For',
                    'X-Forwarded-Host',
                    'X-Forwarded-Proto',
                    'forwarded',
                ],
            ],
        );

        self::assertEquals(
            $expected,
            $request->getUserIP(),
            "'getUserIP()' should return the expected value for the given 'secureHeaders' and 'trustedHosts'.",
        );

        $request = new Request(
            [
                'trustedHosts' => [
                    '192.168.0.0/24' => [
                        'X-Forwarded-For',
                        'forwarded',
                    ],
                ],
                'secureHeaders' => [
                    'Front-End-Https',
                    'X-Rewrite-Url',
                    'X-Forwarded-For',
                    'X-Forwarded-Host',
                    'X-Forwarded-Proto',
                    'forwarded',
                ],
            ],
        );

        self::assertEquals(
            $expected,
            $request->getUserIP(),
            "'getUserIP()' should return the expected value for the associative 'trustedHosts' and 'secureHeaders'.",
        );

        $_SERVER = $original;
    }

    /**
     * @phpstan-param array<array{array<string, string>}> $server
     */
    #[DataProviderExternal(RequestProvider::class, 'getUserIPWithoutTrustedHost')]
    public function testGetUserIPWithoutTrustedHost(array $server, string $expected): void
    {
        $original = $_SERVER;
        $_SERVER = $server;

        $request = new Request(
            [
                'trustedHosts' => [
                    '192.168.0.0/24' => [
                        'X-Forwarded-For',
                    ],
                ],
                'secureHeaders' => [
                    'Front-End-Https',
                    'X-Rewrite-Url',
                    'X-Forwarded-For',
                    'X-Forwarded-Host',
                    'X-Forwarded-Proto',
                    'forwarded',
                ],
            ],
        );

        self::assertEquals(
            $expected,
            $request->getUserIP(),
            "'getUserIP()' should return the expected value for the associative 'trustedHosts' and 'secureHeaders'.",
        );

        $_SERVER = $original;
    }

    /**
     * @phpstan-param array<array{false|string|null, string|null}> $expected
     */
    #[DataProviderExternal(RequestProvider::class, 'httpAuthorizationHeaders')]
    public function testHttpAuthCredentialsFromHttpAuthorizationHeader(string $secret, array $expected): void
    {
        $original = $_SERVER;

        $request = new Request();

        $_SERVER['HTTP_AUTHORIZATION'] = "Basic {$secret}";

        self::assertSame(
            $expected,
            $request->getAuthCredentials(),
            "'getAuthCredentials()' should return the expected credentials from 'HTTP_AUTHORIZATION'.",
        );
        self::assertSame(
            $expected[0] ?? null,
            $request->getAuthUser(),
            "'getAuthUser()' should return the expected username from 'HTTP_AUTHORIZATION'.",
        );
        self::assertSame(
            $expected[1] ?? null,
            $request->getAuthPassword(),
            "'getAuthPassword()' should return the expected password from 'HTTP_AUTHORIZATION'.",
        );

        $_SERVER = $original;

        $request = new Request();

        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = "Basic {$secret}";

        self::assertSame(
            $expected,
            $request->getAuthCredentials(),
            "'getAuthCredentials()' should return the expected credentials from 'REDIRECT_HTTP_AUTHORIZATION'.",
        );
        self::assertSame(
            $expected[0] ?? null,
            $request->getAuthUser(),
            "'getAuthUser()' should return the expected username from 'REDIRECT_HTTP_AUTHORIZATION'.",
        );
        self::assertSame(
            $expected[1] ?? null,
            $request->getAuthPassword(),
            "'getAuthPassword()' should return the expected password from 'REDIRECT_HTTP_AUTHORIZATION'.",
        );

        $_SERVER = $original;
    }

    public function testHttpAuthCredentialsFromServerSuperglobal(): void
    {
        $original = $_SERVER;
        [$user, $pw] = ['foo', 'bar'];
        $_SERVER['PHP_AUTH_USER'] = $user;
        $_SERVER['PHP_AUTH_PW'] = $pw;

        $request = new Request();

        $request->getHeaders()->set('Authorization', 'Basic ' . base64_encode('less-priority:than-PHP_AUTH_*'));

        self::assertSame(
            [$user, $pw],
            $request->getAuthCredentials(),
            "'getAuthCredentials()' should return credentials from 'PHP_AUTH_USER' and 'PHP_AUTH_PW' when set.",
        );
        self::assertSame(
            $user,
            $request->getAuthUser(),
            "'getAuthUser()' should return the username from 'PHP_AUTH_USER' when set.",
        );
        self::assertSame(
            $pw,
            $request->getAuthPassword(),
            "'getAuthPassword()' should return the password from 'PHP_AUTH_PW' when set.",
        );

        $_SERVER = $original;
    }

    public function testIssue15317(): void
    {
        $originalCookie = $_COOKIE;

        $this->mockWebApplication();

        $_COOKIE[(new Request())->csrfParam] = '';
        $request = new Request();

        $request->enableCsrfCookie = true;
        $request->enableCookieValidation = false;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        Yii::$app->security->unmaskToken('');

        self::assertFalse(
            $request->validateCsrfToken(''),
            "'validateCsrfToken()' should return 'false' when an empty 'CSRF' token is provided.",
        );
        self::assertNotEmpty(
            $request->getCsrfToken(),
            "'getCsrfToken()' should return a non-empty value after an empty 'CSRF' token is validated.",
        );

        $_COOKIE = $originalCookie;
    }

    public function testNoCsrfTokenCsrfHeaderValidation(): void
    {
        $this->mockWebApplication();

        $request = new Request();

        $request->validateCsrfHeaderOnly = true;

        self::assertNull(
            $request->getCsrfToken(),
            "'getCsrfToken()' should return 'null' when no 'CSRF' token is set.",
        );
    }

    public function testParseAcceptHeader(): void
    {
        $request = new Request();

        self::assertEquals(
            [],
            $request->parseAcceptHeader(' '),
            "'parseAcceptHeader()' should return an empty array when the header is blank.",
        );
        self::assertEquals(
            [
                'audio/basic' => ['q' => 1],
                'audio/*' => ['q' => 0.2],
            ],
            $request->parseAcceptHeader('audio/*; q=0.2, audio/basic'),
            "'parseAcceptHeader()' should correctly parse media types and quality values.",
        );
        self::assertEquals(
            [
                'application/json' => ['q' => 1, 'version' => '1.0'],
                'application/xml' => ['q' => 1, 'version' => '2.0', 'x'],
                'text/x-c' => ['q' => 1],
                'text/x-dvi' => ['q' => 0.8],
                'text/plain' => ['q' => 0.5],
            ],
            $request->parseAcceptHeader(
                'text/plain; q=0.5,
                application/json; version=1.0,
                application/xml; version=2.0; x,
                text/x-dvi; q=0.8, text/x-c',
            ),
            "'parseAcceptHeader()' should correctly parse complex 'Accept' headers with parameters and quality values.",
        );
    }

    #[DataProviderExternal(RequestProvider::class, 'parseForwardedHeader')]
    public function testParseForwardedHeaderParts(
        string $remoteAddress,
        string $forwardedHeader,
        string $expectedHostInfo,
        string $expectedUserIp,
    ): void {
        $_SERVER['REMOTE_ADDR'] = $remoteAddress;
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['HTTP_FORWARDED'] = $forwardedHeader;

        $request = new Request(
            [
                'trustedHosts' => [
                    '192.168.10.0/24',
                    '192.168.20.0/24',
                ],
                'secureHeaders' => [
                    'X-Forwarded-For',
                    'X-Forwarded-Host',
                    'X-Forwarded-Proto',
                    'forwarded',
                ],
            ],
        );

        self::assertSame(
            $expectedUserIp,
            $request->userIP,
            "'userIP' should match the expected value parsed from the 'Forwarded' header.",
        );
        self::assertSame(
            $expectedHostInfo,
            $request->hostInfo,
            "'hostInfo' should match the expected value parsed from the 'Forwarded' header.",
        );
    }

    public function testPreferredLanguage(): void
    {
        $this->mockApplication(
            [
                'language' => 'en',
            ],
        );

        $request = new Request();

        $request->acceptableLanguages = [];

        self::assertEquals(
            'en',
            $request->getPreferredLanguage(),
            "Should return 'en' when no 'acceptableLanguages' are set.",
        );

        $request = new Request();

        $request->acceptableLanguages = ['de'];

        self::assertEquals(
            'en',
            $request->getPreferredLanguage(),
            "Should return 'en' when 'acceptableLanguages' does not match the default.",
        );

        $request = new Request();

        $request->acceptableLanguages = ['en-us', 'de', 'ru-RU'];

        self::assertEquals(
            'en',
            $request->getPreferredLanguage(['en']),
            "Should return 'en' when 'en' is in the preferred list.",
        );

        $request = new Request();

        $request->acceptableLanguages = ['en-us', 'de', 'ru-RU'];

        self::assertEquals(
            'de',
            $request->getPreferredLanguage(['ru', 'de']),
            "Should return 'de' when 'de' is in the preferred list.",
        );
        self::assertEquals(
            'de-DE',
            $request->getPreferredLanguage(['ru', 'de-DE']),
            "Should return 'de-DE' when 'de-DE' is in the preferred list.",
        );

        $request = new Request();

        $request->acceptableLanguages = ['en-us', 'de', 'ru-RU'];

        self::assertEquals(
            'de',
            $request->getPreferredLanguage(['de', 'ru']),
            "Should return 'de' when 'de' is the first match in the preferred list.",
        );

        $request = new Request();

        $request->acceptableLanguages = ['en-us', 'de', 'ru-RU'];

        self::assertEquals(
            'ru-ru',
            $request->getPreferredLanguage(['ru-ru']),
            "Should return 'ru-ru' when 'ru-ru' is in the preferred list.",
        );

        $request = new Request();

        $request->acceptableLanguages = ['en-us', 'de'];

        self::assertEquals(
            'ru-ru',
            $request->getPreferredLanguage(['ru-ru', 'pl']),
            "Should return 'ru-ru' when 'ru-ru' is the first in the preferred list.",
        );
        self::assertEquals(
            'ru-RU',
            $request->getPreferredLanguage(['ru-RU', 'pl']),
            "Should return 'ru-RU' when 'ru-RU' is the first in the preferred list.",
        );

        $request = new Request();

        $request->acceptableLanguages = ['en-us', 'de'];

        self::assertEquals(
            'pl',
            $request->getPreferredLanguage(['pl', 'ru-ru']),
            "Should return 'pl' when 'pl' is the first in the preferred list and not present in 'acceptableLanguages'.",
        );
    }

    #[TestWith(['POST', 'GET', 'POST'])]
    #[TestWith(['POST', 'OPTIONS', 'POST'])]
    #[TestWith(['POST', 'HEAD', 'POST'])]
    #[TestWith(['POST', 'DELETE', 'DELETE'])]
    #[TestWith(['POST', 'CUSTOM', 'CUSTOM'])]
    public function testRequestMethodCanNotBeDowngraded(
        string $requestMethod,
        string $requestOverrideMethod,
        string $expectedMethod,
    ): void {
        $request = new Request();

        $_SERVER['REQUEST_METHOD'] = $requestMethod;
        $_POST[$request->methodParam] = $requestOverrideMethod;

        self::assertSame(
            $expectedMethod,
            $request->getMethod(),
            "'getMethod()' should return the expected 'HTTP' method after considering override logic.",
        );
    }

    public function testResolve(): void
    {
        $this->mockWebApplication(
            [
                'components' => [
                    'urlManager' => [
                        'cache' => null,
                        'enablePrettyUrl' => true,
                        'showScriptName' => false,
                        'rules' => [
                            'posts' => 'post/list',
                            'post/<id>' => 'post/view',
                        ],
                    ],
                ],
            ],
        );

        $request = new Request();

        $request->pathInfo = 'posts';
        $_GET['page'] = 1;

        $result = $request->resolve();

        self::assertEquals(
            [
                'post/list',
                ['page' => 1],
            ],
            $result,
            '\'resolve()\' should return the correct route and query parameters when \'page\' is set in \'$_GET\'.',
        );
        self::assertEquals(
            ['page' => 1],
            $_GET,
            '\'$_GET\' should contain only the \'page\' parameter after resolving the \'posts\' route.',
        );

        $request->setQueryParams(['page' => 5]);
        $result = $request->resolve();

        self::assertEquals(
            [
                'post/list',
                ['page' => 5],
            ],
            $result,
            "'resolve()' should return the correct route and query parameters when 'page' is set via 'setQueryParams()'.",
        );
        self::assertEquals(
            ['page' => 1],
            $_GET,
            '\'$_GET\' should remain unchanged after \'setQueryParams()\' is used on the request object.',
        );

        $request->setQueryParams(['custom-page' => 5]);
        $result = $request->resolve();

        self::assertEquals(
            [
                'post/list',
                ['custom-page' => 5],
            ],
            $result,
            "'resolve()' should return the correct route and custom query parameters when 'setQueryParams()' is used.",
        );
        self::assertEquals(
            ['page' => 1],
            $_GET,
            '\'$_GET\' should not be affected by custom query parameters set via \'setQueryParams()\'.',
        );

        unset($_GET['page']);

        $request = new Request();

        $request->pathInfo = 'post/21';

        self::assertEquals(
            [],
            $_GET,
            '\$_GET\' should be empty after unsetting the \'page\' parameter and before resolving a new route.',
        );

        $result = $request->resolve();

        self::assertEquals(
            [
                'post/view',
                ['id' => 21],
            ],
            $result,
            "'resolve()' should return the correct route and parameters when resolving a path with an \'id\'.",
        );
        self::assertEquals(
            ['id' => 21],
            $_GET,
            '\'$_GET\' should contain the \'id\' parameter after resolving the \'post/21\' route.',
        );

        $_GET['id'] = 42;

        $result = $request->resolve();

        self::assertEquals(
            [
                'post/view',
                ['id' => 21],
            ],
            $result,
            '\'resolve()\' should return the same route and parameters even if \'$_GET[\'id\']\' is set to a ' .
            'different value before resolving.',
        );
        self::assertEquals(
            ['id' => 21],
            $_GET,
            '\'$_GET\' should be overwritten with the resolved \'id\' parameter after resolving the route.',
        );

        $_GET['id'] = 63;

        $request->setQueryParams(['token' => 'secret']);
        $result = $request->resolve();

        self::assertEquals(
            [
                'post/view',
                [
                    'id' => 21,
                    'token' => 'secret',
                ],
            ],
            $result,
            "'resolve()' should merge additional query parameters set via 'setQueryParams()' with the resolved route " .
            'parameters.',
        );
        self::assertEquals(
            ['id' => 63],
            $_GET,
            '\'$_GET\' should remain unchanged by \'setQueryParams()\' after resolving the route with extra parameters.',
        );
    }

    public function testSetHostInfo(): void
    {
        $request = new Request();

        unset($_SERVER['SERVER_NAME'], $_SERVER['HTTP_HOST']);

        self::assertNull(
            $request->getHostInfo(),
            "'getHostInfo()' should return 'null' when no host information is available in the server variables.",
        );
        self::assertNull(
            $request->getHostName(),
            "'getHostName()' should return 'null' when no host information is available in the server variables.",
        );

        $request->setHostInfo('http://servername.com:80');

        self::assertSame(
            'http://servername.com:80',
            $request->getHostInfo(),
            "'getHostInfo()' should return the value set by 'setHostInfo()'.",
        );
        self::assertSame(
            'servername.com',
            $request->getHostName(),
            "'getHostName()' should return the host name extracted from the value set by 'setHostInfo()'.",
        );
    }

    public function testThrowExceptionWhenAdapterPSR7IsNotSet(): void
    {
        $request = new Request();

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('PSR-7 request adapter is not set.');

        $request->getPsr7Request();
    }

    public function testThrowExceptionWhenRequestUriIsMissing(): void
    {
        $this->mockWebApplication();

        $request = new Request();

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Unable to determine the request URI.');

        $request->getUrl();
    }

    /**
     * @phpstan-param array<array-key, string>|null $ipHeaders
     * @phpstan-param array<array-key, string> $trustedHosts
     *
     * @throws InvalidConfigException
     */
    #[DataProviderExternal(RequestProvider::class, 'trustedHostAndInjectedXForwardedFor')]
    public function testTrustedHostAndInjectedXForwardedFor(
        string $remoteAddress,
        string $xForwardedFor,
        array|null $ipHeaders,
        array $trustedHosts,
        string $expectedUserIp,
    ): void {
        $_SERVER['REMOTE_ADDR'] = $remoteAddress;
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $xForwardedFor;
        $params = ['trustedHosts' => $trustedHosts];

        if ($ipHeaders !== null) {
            $params['ipHeaders'] = $ipHeaders;
        }

        $request = new Request($params);

        self::assertSame(
            $expectedUserIp,
            $request->getUserIP(),
            "'getUserIP()' should return the expected user 'IP', considering trusted hosts and the 'X-Forwarded-For'" .
            'header with possible injection attempts.',
        );
    }

    /**
     * @phpstan-param array<array-key, string>|null $trustedHosts
     */
    #[DataProviderExternal(RequestProvider::class, 'trustedHostAndXForwardedPort')]
    public function testTrustedHostAndXForwardedPort(
        string $remoteAddress,
        int $requestPort,
        int|null $xForwardedPort,
        array|null $trustedHosts,
        int $expectedPort,
    ): void {
        $_SERVER['REMOTE_ADDR'] = $remoteAddress;
        $_SERVER['SERVER_PORT'] = $requestPort;
        $_SERVER['HTTP_X_FORWARDED_PORT'] = $xForwardedPort;
        $params = ['trustedHosts' => $trustedHosts];

        $request = new Request($params);

        self::assertSame(
            $expectedPort,
            $request->getServerPort(),
            "'getServerPort()' should return the expected 'PORT', considering trusted hosts and the 'X-Forwarded-Port' " .
            'header when present.',
        );
    }
}
