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

        self::assertSame(
            '10.0.0.50',
            $serverParams['REMOTE_ADDR'] ?? null,
            "Server parameter 'REMOTE_ADDR' should be taken from PSR-7 'serverParams', not from global \$_SERVER.",
        );
        self::assertNull(
            $serverParams['REQUEST_TIME'] ?? null,
            "Server parameter 'REQUEST_TIME' should be 'null' when not set in PSR-7 'serverParams', even if present " .
            'in global $_SERVER.',
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
