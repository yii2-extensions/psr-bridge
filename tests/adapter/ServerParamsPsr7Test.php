<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\adapter;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group};
use yii2\extensions\psrbridge\http\Request;
use yii2\extensions\psrbridge\tests\provider\RequestProvider;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function sprintf;
use function var_export;

#[Group('adapter')]
#[Group('psr7-request')]
final class ServerParamsPsr7Test extends TestCase
{
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

    #[DataProviderExternal(RequestProvider::class, 'remoteHostCases')]
    #[Group('remote-host')]
    public function testReturnRemoteHostFromServerParamsCases(int|string|null $serverValue, string|null $expected): void
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
}
