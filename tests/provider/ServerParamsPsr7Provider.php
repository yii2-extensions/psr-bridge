<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\provider;

final class ServerParamsPsr7Provider
{
    /**
     * @phpstan-return array<
     *   string,
     *   array{
     *     array<string, mixed>,
     *     array<string, mixed>,
     *     array<string, array<int, string>|int|string>,
     *     array<string, mixed>,
     *     int|null,
     *     string,
     *   }
     * >
     */
    public static function serverPortCases(): array
    {
        return [
            'Forwarded port when request from trusted proxy' => [
                [
                    'portHeaders' => ['X-Forwarded-Port'],
                    'trustedHosts' => ['10.0.0.0/24'], // trust this subnet
                ],
                ['REMOTE_ADDR' => '10.0.0.1'],
                ['X-Forwarded-Port' => '443'],
                [
                    'SERVER_PORT' => '8080',
                    'REMOTE_ADDR' => '10.0.0.1',
                ],
                443,
                "'getServerPort()' should return forwarded port when request comes from trusted proxy.",
            ],
            'Ignore forwarded port when request from untrusted host' => [
                [
                    'portHeaders' => ['X-Forwarded-Port'],
                    'secureHeaders' => ['X-Forwarded-Port'],
                    'trustedHosts' => ['10.0.0.0/24'], // only trust this subnet
                ],
                ['REMOTE_ADDR' => '192.168.1.100'],
                ['X-Forwarded-Port' => '443'],
                [
                    'REMOTE_ADDR' => '192.168.1.100',
                    'SERVER_PORT' => '8080',
                ],
                8080,
                "'getServerPort()' should ignore forwarded port header from untrusted hosts and use 'SERVER_PORT'.",
            ],
            'Null when PSR-7 request server port is empty array' => [
                [],
                [],
                [],
                ['SERVER_PORT' => []],
                null,
                "'SERVER_PORT' should return 'null' from PSR-7 'serverParams' when adapter is set but 'SERVER_PORT' " .
                'is an empty array.',
            ],
            'Null when PSR-7 request server port is null' => [
                [],
                [],
                [],
                ['SERVER_PORT' => null],
                null,
                "'SERVER_PORT' should return 'null' from PSR-7 'serverParams' when adapter is set but 'SERVER_PORT' " .
                "is 'null'.",
            ],
            'Null when PSR-7 request server port is not present' => [
                [],
                [],
                [],
                ['HTTP_HOST' => 'example.com'],
                null,
                "'SERVER_PORT' should return 'null' from PSR-7 'serverParams' when adapter is set but 'SERVER_PORT' " .
                'is not present.',
            ],
            'Null when PSR-7 request server port is not string' => [
                [],
                [],
                [],
                ['SERVER_PORT' => ['invalid' => 'array']],
                null,
                "'SERVER_PORT' should return 'null' from PSR-7 'serverParams' when adapter is set but 'SERVER_PORT' " .
                'is not a string.',
            ],
            'Server port as integer when PSR-7 server port is numeric string' => [
                [],
                [],
                [],
                ['SERVER_PORT' => '443'],
                443,
                "'getServerPort()' should return integer value when 'SERVER_PORT' is a numeric string.",
            ],
            'Server port from comma separated forwarded header' => [
                [
                    'portHeaders' => ['X-Forwarded-Port'],
                    'secureHeaders' => ['X-Forwarded-Port'],
                    'trustedHosts' => ['127.0.0.1'],
                ],
                ['REMOTE_ADDR' => '127.0.0.1'],
                ['X-Forwarded-Port' => '9443, 7443'],
                [
                    'REMOTE_ADDR' => '127.0.0.1',
                    'SERVER_PORT' => '80',
                ],
                9443,
                "'getServerPort()' should return the first port from a comma-separated 'X-Forwarded-Port' header.",
            ],
            'Server port from first valid forwarded header when multiple configured' => [
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
                ['REMOTE_ADDR' => '127.0.0.1'],
                [
                    'X-Custom-Port' => '',
                    'X-Forwarded-Port' => '9443',
                    'X-Real-Port' => '7443',
                ],
                [
                    'REMOTE_ADDR' => '127.0.0.1',
                    'SERVER_PORT' => '80',
                ],
                9443,
                "'getServerPort()' should return the port from the first valid forwarded header in the configured " .
                'list.',
            ],
            'Server port from forwarded header when adapter is set' => [
                [
                    'portHeaders' => ['X-Forwarded-Port'],
                    'trustedHosts' => ['127.0.0.1'],
                ],
                ['REMOTE_ADDR' => '127.0.0.1'],
                ['X-Forwarded-Port' => '443'],
                [
                    'REMOTE_ADDR' => '127.0.0.1',
                    'SERVER_PORT' => '8080',
                ],
                443,
                "'getServerPort()' should return the port from 'X-Forwarded-Port' header when present, ignoring " .
                "'SERVER_PORT' from PSR-7 'serverParams'.",
            ],
            'Server port from PSR-7 request when adapter is set and server port present' => [
                [
                    'portHeaders' => [
                        'X-Custom-Port',
                        'X-Forwarded-Port',
                    ],
                ],
                [],
                ['X-Custom-Port' => ''],
                ['SERVER_PORT' => '3000'],
                3000,
                "'getServerPort()' should fallback to 'SERVER_PORT' when all forwarded headers are 'null' or missing.",
            ],
        ];
    }
}
