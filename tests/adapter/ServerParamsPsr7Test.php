<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\adapter;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group};
use yii\base\InvalidConfigException;
use yii2\extensions\psrbridge\http\Request;
use yii2\extensions\psrbridge\tests\provider\RequestProvider;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function sprintf;
use function var_export;

#[Group('adapter')]
final class ServerParamsPsr7Test extends TestCase
{
    #[Group('server-port')]
    public function testIgnoreForwardedPortWhenRequestFromUntrustedHost(): void
    {
        $_SERVER = ['REMOTE_ADDR' => '192.168.1.100']; // untrusted host

        $request = new Request(
            [
                'portHeaders' => ['X-Forwarded-Port'],
                'secureHeaders' => ['X-Forwarded-Port'],
                'trustedHosts' => ['10.0.0.0/24'], // only trust this subnet
            ],
        );

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'GET',
                '/test',
                ['X-Forwarded-Port' => '443'], // should be filtered out
                serverParams: [
                    'REMOTE_ADDR' => '192.168.1.100',
                    'SERVER_PORT' => '8080',
                ],
            ),
        );

        // when headers are filtered due to untrusted host, fallback to 'SERVER_PORT'
        self::assertSame(
            8080,
            $request->getServerPort(),
            "'getServerPort()' should ignore forwarded port header from untrusted hosts and use 'SERVER_PORT'.",
        );
    }
    #[Group('remote-host')]
    public function testIndependentRequestsWithDifferentRemoteHosts(): void
    {
        $host1 = 'client1.example.com';
        $host2 = 'client2.example.org';

        $request1 = new Request();

        $request1->setPsr7Request(
            FactoryHelper::createRequest('GET', '/api/v1/users', serverParams: ['REMOTE_HOST' => $host1]),
        );

        $request2 = new Request();

        $request2->setPsr7Request(
            FactoryHelper::createRequest('POST', '/api/v1/posts', serverParams: ['REMOTE_HOST' => $host2]),
        );

        self::assertSame(
            $host1,
            $request1->getRemoteHost(),
            "First request instance should return its own 'REMOTE_HOST' value.",
        );
        self::assertSame(
            $host2,
            $request2->getRemoteHost(),
            "Second request instance should return its own 'REMOTE_HOST' value.",
        );
    }

    #[Group('remote-host')]
    public function testResetRemoteHostAfterRequestReset(): void
    {
        $initialHost = 'initial.host.com';
        $newHost = 'new.host.com';

        $request = new Request();

        self::assertNull(
            $request->getRemoteHost(),
            "After 'reset' method and before setting a new PSR-7 request, 'getRemoteHost()' should be 'null'.",
        );

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/first', serverParams: ['REMOTE_HOST' => $initialHost]),
        );

        $result1 = $request->getRemoteHost();

        self::assertSame(
            $initialHost,
            $result1,
            "'REMOTE_HOST' should return the initial host value from the first PSR-7 request.",
        );

        $request->reset();

        $request->setPsr7Request(
            FactoryHelper::createRequest('POST', '/second', serverParams: ['REMOTE_HOST' => $newHost]),
        );

        $result2 = $request->getRemoteHost();

        self::assertSame(
            $newHost,
            $result2,
            "'REMOTE_HOST' should return the new host value after 'reset' method and setting a new PSR-7 request.",
        );
        self::assertNotSame(
            $result1,
            $result2,
            "'REMOTE_HOST' values should be different after 'reset' method with new PSR-7 request data.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnEmptyScriptUrlWhenAdapterIsSetInTraditionalModeWithoutScriptName(): void
    {
        $request = new Request(['workerMode' => false]);

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test'),
        );

        self::assertEmpty(
            $request->getScriptUrl(),
            "Script URL should be empty when adapter is set in traditional mode without 'SCRIPT_NAME'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnEmptyScriptUrlWhenAdapterIsSetInWorkerMode(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test'),
        );

        self::assertEmpty(
            $request->getScriptUrl(),
            "Script URL should be empty when adapter is set in 'worker' mode (default).",
        );
    }

    #[Group('server-params')]
    public function testReturnEmptyServerParamsWhenAdapterIsSet(): void
    {
        $_SERVER = [
            'REMOTE_ADDR' => '192.168.1.100',
            'REQUEST_TIME' => '1234567890',
            'SERVER_NAME' => 'old.example.com',
        ];

        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', 'https://old.example.com/api'),
        );

        self::assertEmpty(
            $request->getServerParams(),
            'Server parameters should be an empty array when using a PSR-7 request, ignoring global $_SERVER.',
        );
    }

    #[Group('server-port')]
    public function testReturnForwardedPortWhenRequestFromTrustedProxy(): void
    {
        $_SERVER = ['REMOTE_ADDR' => '10.0.0.1']; // trusted proxy

        $request = new Request(
            [
                'portHeaders' => ['X-Forwarded-Port'],
                'trustedHosts' => ['10.0.0.0/24'], // trust this subnet
            ],
        );

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'GET',
                '/test',
                ['X-Forwarded-Port' => '443'],
                serverParams: [
                    'SERVER_PORT' => '8080',
                    'REMOTE_ADDR' => '10.0.0.1',
                ],
            ),
        );

        self::assertSame(
            443,
            $request->getServerPort(),
            "'getServerPort()' should return forwarded port when request comes from trusted proxy.",
        );
    }

    #[Group('server-port')]
    public function testReturnNullWhenPsr7RequestServerPortIsEmptyArray(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: ['SERVER_PORT' => []]),
        );

        self::assertNull(
            $request->getServerPort(),
            "'SERVER_PORT' should return 'null' from PSR-7 'serverParams' when adapter is set but 'SERVER_PORT' is " .
            'an empty array.',
        );
    }

    #[Group('server-port')]
    public function testReturnNullWhenPsr7RequestServerPortIsNotPresent(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: ['HTTP_HOST' => 'example.com']),
        );

        self::assertNull(
            $request->getServerPort(),
            "'SERVER_PORT' should return 'null' from PSR-7 'serverParams' when adapter is set but 'SERVER_PORT' is " .
            'not present.',
        );
    }

    #[Group('server-port')]
    public function testReturnNullWhenPsr7RequestServerPortIsNotString(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: ['SERVER_PORT' => ['invalid' => 'array']]),
        );

        self::assertNull(
            $request->getServerPort(),
            "'SERVER_PORT' should return 'null' from PSR-7 'serverParams' when adapter is set but 'SERVER_PORT' is " .
            'not a string.',
        );
    }

    #[Group('server-port')]
    public function testReturnNullWhenPsr7RequestServerPortIsNull(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: ['SERVER_PORT' => null]),
        );

        self::assertNull(
            $request->getServerPort(),
            "'SERVER_PORT' should return 'null' from PSR-7 'serverParams' when adapter is set but 'SERVER_PORT' is " .
            "'null'.",
        );
    }

    #[DataProviderExternal(RequestProvider::class, 'remoteHostCases')]
    #[Group('remote-host')]
    public function testReturnRemoteHostFromServerParamsCases(mixed $serverValue, string|null $expected): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: ['REMOTE_HOST' => $serverValue]),
        );

        $actual = $request->getRemoteHost();

        self::assertSame(
            $expected,
            $actual,
            sprintf(
                "'getRemoteHost()' should return '%s' when 'REMOTE_HOST' is '%s' in PSR-7 'serverParams'. Got: '%s'",
                var_export($expected, true),
                var_export($serverValue, true),
                var_export($actual, true),
            ),
        );
    }

    #[Group('remote-ip')]
    public function testReturnRemoteIPFromPsr7ServerParamsOverridesGlobalServer(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';

        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'GET',
                'https://old.example.com/api',
                serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
            ),
        );

        self::assertSame(
            '10.0.0.1',
            $request->getRemoteIP(),
            "'getRemoteIP()' should return the 'REMOTE_ADDR' value from PSR-7 'serverParams', not from global " .
            '$_SERVER.',
        );
    }

    #[DataProviderExternal(RequestProvider::class, 'remoteIPCases')]
    #[Group('remote-ip')]
    public function testReturnRemoteIPFromServerParamsCases(mixed $serverValue, string|null $expected): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: ['REMOTE_ADDR' => $serverValue]),
        );

        $actual = $request->getRemoteIP();

        self::assertSame(
            $expected,
            $actual,
            sprintf(
                "'getRemoteIP()' should return '%s' when 'REMOTE_ADDR' is '%s' in PSR-7 'serverParams'. Got: '%s'",
                var_export($expected, true),
                var_export($serverValue, true),
                var_export($actual, true),
            ),
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnScriptNameWhenAdapterIsSetInTraditionalMode(): void
    {
        $expectedScriptName = '/app/public/index.php';

        $request = new Request(['workerMode' => false]);

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: ['SCRIPT_NAME' => $expectedScriptName]),
        );

        self::assertSame(
            $expectedScriptName,
            $request->getScriptUrl(),
            "Script URL should return 'SCRIPT_NAME' when adapter is set in traditional mode.",
        );
    }

    #[DataProviderExternal(RequestProvider::class, 'serverNameCases')]
    #[Group('server-name')]
    public function testReturnServerNameFromServerParamsCases(mixed $serverValue, string|null $expected): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: ['SERVER_NAME' => $serverValue]),
        );

        $actual = $request->getServerName();

        self::assertSame(
            $expected,
            $actual,
            sprintf(
                "'getServerName()' should return '%s' when 'SERVER_NAME' is '%s' in PSR-7 'serverParams'. Got: '%s'",
                var_export($expected, true),
                var_export($serverValue, true),
                var_export($actual, true),
            ),
        );
    }

    /**
     * @phpstan-param array<string, mixed> $serverParams
     */
    #[DataProviderExternal(RequestProvider::class, 'serverParamCases')]
    #[Group('server-param')]
    public function testReturnServerParamFromPsr7RequestCases(
        string $paramName,
        array $serverParams,
        mixed $expected,
    ): void {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: $serverParams),
        );

        $actual = $request->getServerParam($paramName);

        self::assertSame(
            $expected,
            $actual,
            sprintf(
                "'getServerParam('%s')' should return '%s' when PSR-7 'serverParams' contains '%s'. Got: '%s'",
                $paramName,
                var_export($expected, true),
                var_export($serverParams, true),
                var_export($actual, true),
            ),
        );
    }

    #[Group('server-params')]
    public function testReturnServerParamsFromPsr7RequestOverridesGlobalServer(): void
    {
        $_SERVER = [
            'REMOTE_ADDR' => '192.168.1.100',
            'REQUEST_TIME' => '1234567890',
            'SERVER_NAME' => 'old.example.com',
        ];

        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'GET',
                'https://old.example.com/api',
                serverParams: [
                    'HTTP_X_FORWARDED_FOR' => '203.0.113.1',
                    'REMOTE_ADDR' => '10.0.0.50',
                    'REQUEST_TIME' => null,
                    'SERVER_NAME' => 'new.example.com',
                ],
            ),
        );

        $serverParams = $request->getServerParams();

        self::assertCount(
            4,
            $serverParams,
            "Only parameters present in PSR-7 'serverParams' should be returned.",
        );
        self::assertSame(
            '203.0.113.1',
            $serverParams['HTTP_X_FORWARDED_FOR'] ?? null,
            "'HTTP_X_FORWARDED_FOR' should be taken from PSR-7 'serverParams'.",
        );
        self::assertSame(
            '10.0.0.50',
            $serverParams['REMOTE_ADDR'] ?? null,
            "Server parameter 'REMOTE_ADDR' should be taken from PSR-7 'serverParams', not from global \$_SERVER.",
        );
        self::assertNull(
            $serverParams['REQUEST_TIME'] ?? null,
            "Server parameter 'REQUEST_TIME' should be 'null' when explicitly set to 'null' in PSR-7 'serverParams', " .
            'even if present in global $_SERVER.',
        );
        self::assertSame(
            'new.example.com',
            $serverParams['SERVER_NAME'] ?? null,
            "'SERVER_NAME' should be taken from PSR-7 'serverParams'.",
        );
    }

    #[Group('server-params')]
    public function testReturnServerParamsFromPsr7RequestWhenAdapterIsSet(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'GET',
                'https://old.example.com/api',
                serverParams: [
                    'HTTP_X_FORWARDED_FOR' => '203.0.113.1',
                    'REQUEST_TIME' => '1234567890',
                ],
            ),
        );

        $serverParams = $request->getServerParams();

        self::assertCount(
            2,
            $serverParams,
            "Only parameters present in PSR-7 'serverParams' should be returned.",
        );
        self::assertSame(
            '203.0.113.1',
            $serverParams['HTTP_X_FORWARDED_FOR'] ?? null,
            "'HTTP_X_FORWARDED_FOR' should match the value from PSR-7 'serverParams'.",
        );
        self::assertSame(
            '1234567890',
            $serverParams['REQUEST_TIME'] ?? null,
            "'REQUEST_TIME' should match the value from PSR-7 'serverParams'.",
        );
        self::assertNull(
            $serverParams['REMOTE_ADDR'] ?? null,
            "'REMOTE_ADDR' should not be set when not present in PSR-7 'serverParams'.",
        );
    }

    /**
     * @phpstan-param array<string, mixed> $serverParams
     */
    #[DataProviderExternal(RequestProvider::class, 'serverParamDefaultValueCases')]
    #[Group('server-param')]
    public function testReturnServerParamWithDefaultFromPsr7RequestCases(
        string $paramName,
        array $serverParams,
        mixed $default,
        mixed $expected,
    ): void {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: $serverParams),
        );

        $actual = $request->getServerParam($paramName, $default);

        self::assertSame(
            $expected,
            $actual,
            sprintf(
                "'getServerParam('%s', '%s')' should return '%s' when PSR-7 'serverParams' contains '%s'. Got: '%s'",
                $paramName,
                var_export($default, true),
                var_export($expected, true),
                var_export($serverParams, true),
                var_export($actual, true),
            ),
        );
    }

    #[Group('server-port')]
    public function testReturnServerPortAsIntegerWhenPsr7ServerPortIsNumericString(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: ['SERVER_PORT' => '443']),
        );

        self::assertSame(
            443,
            $request->getServerPort(),
            "'getServerPort()' should return integer value when 'SERVER_PORT' is a numeric string.",
        );
        self::assertIsInt(
            $request->getServerPort(),
            "'getServerPort()' should return an integer type, not a string.",
        );
    }

    #[Group('server-port')]
    public function testReturnServerPortFromFirstValidForwardedHeaderWhenMultipleConfigured(): void
    {
        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];

        $request = new Request(
            [
                'portHeaders' => [
                    'X-Custom-Port',
                    'X-Forwarded-Port',
                    'X-Real-Port',
                ],
                'secureHeaders' => [
                    'X-Custom-Port',
                    'X-Forwarded-For',
                    'X-Forwarded-Host',
                    'X-Forwarded-Port',
                    'X-Forwarded-Proto',
                    'X-Real-Port',
                ],
                'trustedHosts' => ['127.0.0.1'],
            ],
        );

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'GET',
                '/test',
                [
                    'X-Custom-Port' => '',
                    'X-Forwarded-Port' => '9443',
                    'X-Real-Port' => '7443',
                ],
                serverParams: [
                    'REMOTE_ADDR' => '127.0.0.1',
                    'SERVER_PORT' => '80',
                ],
            ),
        );

        self::assertSame(
            9443,
            $request->getServerPort(),
            "'getServerPort()' should return the port from the first valid forwarded header in the configured list.",
        );
    }

    #[Group('server-port')]
    public function testReturnServerPortFromForwardedHeaderWhenAdapterIsSet(): void
    {
        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];

        $request = new Request(
            [
                'portHeaders' => ['X-Forwarded-Port'],
                'trustedHosts' => ['127.0.0.1'],
            ],
        );

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'GET',
                '/test',
                ['X-Forwarded-Port' => '443'],
                serverParams: [
                    'REMOTE_ADDR' => '127.0.0.1',
                    'SERVER_PORT' => '8080',
                ],
            ),
        );

        self::assertSame(
            443,
            $request->getServerPort(),
            "'getServerPort()' should return the port from 'X-Forwarded-Port' header when present, ignoring " .
            "'SERVER_PORT' from PSR-7 'serverParams'.",
        );
    }

    #[Group('server-port')]
    public function testReturnServerPortFromPsr7RequestWhenAdapterIsSetAndServerPortPresent(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: ['SERVER_PORT' => '8080']),
        );

        self::assertSame(
            8080,
            $request->getServerPort(),
            "'SERVER_PORT' should return '8080' from PSR-7 'serverParams' when adapter is set and 'SERVER_PORT' is " .
            'present as a string.',
        );
    }

    #[Group('server-port')]
    public function testReturnServerPortWhenAllForwardedHeadersAreNullOrMissing(): void
    {
        $request = new Request();

        $request->portHeaders = [
            'X-Custom-Port',
            'X-Forwarded-Port',
        ];

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'GET',
                '/test',
                ['X-Custom-Port' => ''], // first header is empty string
                serverParams: ['SERVER_PORT' => '3000'],
            ),
        );

        self::assertSame(
            3000,
            $request->getServerPort(),
            "'getServerPort()' should fallback to 'SERVER_PORT' when all forwarded headers are 'null' or missing.",
        );
    }

    #[Group('server-name')]
    public function testServerNameAfterRequestReset(): void
    {
        $initialServerName = 'initial.server.com';
        $newServerName = 'new.server.com';

        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: ['SERVER_NAME' => $initialServerName]),
        );

        $result1 = $request->getServerName();

        self::assertSame(
            $initialServerName,
            $result1,
            "'SERVER_NAME' should return '{$initialServerName}' from initial PSR-7 request.",
        );

        $request->reset();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: ['SERVER_NAME' => $newServerName]),
        );

        $result2 = $request->getServerName();

        self::assertSame(
            $newServerName,
            $result2,
            "'SERVER_NAME' should return '{$newServerName}' from new PSR-7 request after 'reset' method.",
        );
        self::assertNotSame(
            $result1,
            $result2,
            "'SERVER_NAME' should change after request 'reset' method and new PSR-7 request assignment.",
        );
    }

    #[Group('server-name')]
    public function testServerNameIndependentRequestsWithDifferentServerNames(): void
    {
        $serverName1 = 'server1.example.com';
        $serverName2 = 'server2.example.org';

        $request1 = new Request();

        $request1->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test1', serverParams: ['SERVER_NAME' => $serverName1]),
        );

        $request2 = new Request();

        $request2->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test2', serverParams: ['SERVER_NAME' => $serverName2]),
        );

        $result1 = $request1->getServerName();
        $result2 = $request2->getServerName();

        self::assertSame(
            $serverName1,
            $result1,
            "First request should return '{$serverName1}' from its PSR-7 'serverParams'.",
        );
        self::assertSame(
            $serverName2,
            $result2,
            "Second request should return '{$serverName2}' from its PSR-7 'serverParams'.",
        );
        self::assertNotSame(
            $result1,
            $result2,
            'Independent request instances should return different server names when configured with different values.',
        );
    }

    #[Group('server-port')]
    public function testServerPortAfterRequestReset(): void
    {
        $initialPort = 8080;
        $newPort = 9090;

        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: ['SERVER_PORT' => $initialPort]),
        );

        $result1 = $request->getServerPort();

        self::assertSame(
            $initialPort,
            $result1,
            "'SERVER_PORT' should return '{$initialPort}' from initial PSR-7 request.",
        );

        $request->reset();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: ['SERVER_PORT' => $newPort]),
        );

        $result2 = $request->getServerPort();

        self::assertSame(
            $newPort,
            $result2,
            "'SERVER_PORT' should return '{$newPort}' from new PSR-7 request after 'reset' method.",
        );
        self::assertNotSame(
            $result1,
            $result2,
            "'SERVER_PORT' should change after request 'reset' method and new PSR-7 request assignment.",
        );
    }

    #[Group('server-port')]
    public function testServerPortIndependentRequestsWithDifferentPorts(): void
    {
        $port1 = 8080;
        $port2 = 443;

        $request1 = new Request();

        $request1->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test1', serverParams: ['SERVER_PORT' => $port1]),
        );

        $request2 = new Request();

        $request2->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test2', serverParams: ['SERVER_PORT' => $port2]),
        );

        $result1 = $request1->getServerPort();
        $result2 = $request2->getServerPort();

        self::assertSame(
            $port1,
            $result1,
            "First request should return '{$port1}' from its PSR-7 'serverParams'.",
        );
        self::assertSame(
            $port2,
            $result2,
            "Second request should return '{$port2}' from its PSR-7 'serverParams'.",
        );
        self::assertNotSame(
            $result1,
            $result2,
            "Independent request instances should return different 'SERVER_PORT' when configured with different " .
            'values.',
        );
    }
}
