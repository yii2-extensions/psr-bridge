<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\adapter;

use PHPUnit\Framework\Attributes\Group;
use yii2\extensions\psrbridge\http\Request;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

#[Group('http')]
#[Group('psr7-request')]
#[Group('server-params')]
final class ServerParamsPsr7Test extends TestCase
{
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

        $result1 = $request1->getRemoteHost();
        $result2 = $request2->getRemoteHost();

        self::assertSame(
            $host1,
            $result1,
            "First request instance should return its own 'REMOTE_HOST' value.",
        );
        self::assertSame(
            $host2,
            $result2,
            "Second request instance should return its own 'REMOTE_HOST' value.",
        );
        self::assertNotSame(
            $result1,
            $result2,
            "Different request instances should maintain separate 'REMOTE_HOST' values.",
        );
    }

    public function testResetRemoteHostAfterRequestReset(): void
    {
        $initialHost = 'initial.host.com';
        $newHost = 'new.host.com';

        $request = new Request();

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

    public function testReturnEmptyStringWhenRemoteHostIsEmptyStringInPsr7Request(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: ['REMOTE_HOST' => '']),
        );

        self::assertSame(
            '',
            $request->getRemoteHost(),
            "Remote host should return an empty string when 'REMOTE_HOST' parameter is an empty string in PSR-7 " .
            "'serverParams'.",
        );
    }

    public function testReturnIPv4AddressFromPsr7RequestWhenRemoteHostIsIP(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('PUT', '/data', serverParams: ['REMOTE_HOST' => '192.168.1.100']),
        );

        self::assertSame(
            '192.168.1.100',
            $request->getRemoteHost(),
            "'REMOTE_HOST' should correctly return 'IPv4' address from PSR-7 'serverParams' when 'REMOTE_HOST' " .
            "contains an 'IPv4' address.",
        );
    }

    public function testReturnIPv6AddressFromPsr7RequestWhenRemoteHostIsIPv6(): void
    {
        $expectedHost = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';

        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('DELETE', '/resource/123', serverParams: ['REMOTE_HOST' => $expectedHost]),
        );

        self::assertSame(
            $expectedHost,
            $request->getRemoteHost(),
            "'REMOTE_HOST' should correctly return 'IPv6' address from PSR-7 'serverParams' when 'REMOTE_HOST' " .
            "contains an 'IPv6' address.",
        );
    }

    public function testReturnLocalhostFromPsr7RequestWhenRemoteHostIsLocalhost(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/health-check', serverParams: ['REMOTE_HOST' => 'localhost']),
        );

        self::assertSame(
            'localhost',
            $request->getRemoteHost(),
            "'REMOTE_HOST' should correctly return 'localhost' from PSR-7 'serverParams' when 'REMOTE_HOST' is " .
            "'localhost'.",
        );
    }

    public function testReturnNullWhenRemoteHostIsNotStringInPsr7Request(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: ['REMOTE_HOST' => 123]),
        );

        self::assertNull(
            $request->getRemoteHost(),
            "'REMOTE_HOST' should return 'null' when 'REMOTE_HOST' parameter is not a string in PSR-7 'serverParams'.",
        );
    }

    public function testReturnNullWhenRemoteHostNotPresentInPsr7Request(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: ['SERVER_NAME' => 'example.com']),
        );

        self::assertNull(
            $request->getRemoteHost(),
            "'REMOTE_HOST' should return 'null' when 'REMOTE_HOST' parameter is not present in PSR-7 'serverParams'.",
        );
    }

    public function testReturnRemoteHostFromPsr7RequestWhenRemoteHostIsPresent(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test', serverParams: ['REMOTE_HOST' => 'example.host.com']),
        );

        self::assertSame(
            'example.host.com',
            $request->getRemoteHost(),
            "'REMOTE_HOST' should match the value from PSR-7 'serverParams' when 'REMOTE_HOST' is present.",
        );
    }

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

    public function testReturnValidHostnameFromPsr7RequestWithDomainName(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'POST',
                '/api/users',
                ['Content-Type' => 'application/json'],
                serverParams: [
                    'REMOTE_HOST' => 'api.example-service.com',
                    'SERVER_NAME' => 'localhost',
                ],
            ),
        );

        self::assertSame(
            'api.example-service.com',
            $request->getRemoteHost(),
            "'REMOTE_HOST' should correctly return domain name from PSR-7 'serverParams' when 'REMOTE_HOST' contains " .
            'a valid hostname.',
        );
    }
}
