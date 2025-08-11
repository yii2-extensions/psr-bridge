<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\provider;

final class ServerParamsPsr7Provider
{
    /**
     * @phpstan-return array<array-key, array{mixed, string|null}>
     */
    public static function remoteHostCases(): array
    {
        return [
            'boolean-false' => [
                false,
                null,
            ],
            'boolean-true' => [
                true,
                null,
            ],
            'domain' => [
                'api.example-service.com',
                'api.example-service.com',
            ],
            'empty-array' => [
                [],
                null,
            ],
            'empty-string' => [
                '',
                '',
            ],
            'float' => [
                123.45,
                null,
            ],
            'integer' => [
                12345,
                null,
            ],
            'integer-zero' => [
                0,
                null,
            ],
            'IPv4' => [
                '192.168.1.100',
                '192.168.1.100',
            ],
            'IPv6' => [
                '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
                '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
            ],
            'localhost' => [
                'localhost',
                'localhost',
            ],
            'null' => [
                null,
                null,
            ],
            'numeric string' => [
                '123',
                '123',
            ],
            'object' => [
                (object) ['foo' => 'bar'],
                null,
            ],
            'string-zero' => [
                '0',
                '0',
            ],
        ];
    }

    /**
     * @phpstan-return array<array-key, array{mixed, string|null}>
     */
    public static function remoteIPCases(): array
    {
        return [
            'boolean-false' => [
                false,
                null,
            ],
            'boolean-true' => [
                true,
                null,
            ],
            'empty-array' => [
                [],
                null,
            ],
            'empty-string' => [
                '',
                null,
            ],
            'float' => [
                123.45,
                null,
            ],
            'integer' => [
                12345,
                null,
            ],
            'integer-zero' => [
                0,
                null,
            ],
            'invalid-ip' => [
                '999.999.999.999',
                null,
            ],
            'IPv4' => [
                '192.168.1.100',
                '192.168.1.100',
            ],
            'ipv4-with-port' => [
                '10.0.0.1:8080',
                null,
            ],
            'IPv4-local' => [
                '127.0.0.1',
                '127.0.0.1',
            ],
            'IPv6' => [
                '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
                '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
            ],
            'ipv6-bracketed' => [
                '[::1]',
                null,
            ],
            'IPv6-compressed' => [
                '::1',
                '::1',
            ],
            'ipv6-with-port' => [
                '[::1]:443',
                null,
            ],
            'localhost' => [
                'localhost',
                null,
            ],
            'null' => [
                null,
                null,
            ],
            'numeric string' => [
                '123',
                null,
            ],
            'object' => [
                (object) ['foo' => 'bar'],
                null,
            ],
            'spaces-around' => [
                ' 127.0.0.1 ',
                null,
            ],
            'string-zero' => [
                '0',
                null,
            ],
            'zero-address' => [
                '0.0.0.0',
                '0.0.0.0',
            ],
        ];
    }

    /**
     * @phpstan-return array<array-key, array{mixed, string|null}>
     */
    public static function serverNameCases(): array
    {
        return [
            'boolean-false' => [
                false,
                null,
            ],
            'boolean-true' => [
                true,
                null,
            ],
            'domain' => [
                'example.server.com',
                'example.server.com',
            ],
            'empty-array' => [
                [],
                null,
            ],
            'empty-string' => [
                '',
                '',
            ],
            'float' => [
                123.45,
                null,
            ],
            'integer' => [
                12345,
                null,
            ],
            'integer-zero' => [
                0,
                null,
            ],
            'IPv4' => [
                '192.168.1.100',
                '192.168.1.100',
            ],
            'IPv6' => [
                '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
                '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
            ],
            'localhost' => [
                'localhost',
                'localhost',
            ],
            'null' => [
                null,
                null,
            ],
            'numeric string' => [
                '123',
                '123',
            ],
            'object' => [
                (object) ['foo' => 'bar'],
                null,
            ],
            'string-zero' => [
                '0',
                '0',
            ],
        ];
    }

    /**
     * @phpstan-return array<array-key, array{string, array<string, mixed>, mixed}>
     */
    public static function serverParamCases(): array
    {
        $object = (object) ['foo' => 'bar'];

        return [
            'absent' => [
                'MISSING_PARAM',
                [],
                null,
            ],
            'array' => [
                'ARRAY_PARAM',
                ['ARRAY_PARAM' => ['key' => 'value']],
                ['key' => 'value'],
            ],
            'boolean-false' => [
                'BOOL_PARAM',
                ['BOOL_PARAM' => false],
                false,
            ],
            'boolean-true' => [
                'BOOL_PARAM',
                ['BOOL_PARAM' => true],
                true,
            ],
            'empty-array' => [
                'EMPTY_ARRAY_PARAM',
                ['EMPTY_ARRAY_PARAM' => []],
                [],
            ],
            'empty-string' => [
                'EMPTY_PARAM',
                ['EMPTY_PARAM' => ''],
                '',
            ],
            'float' => [
                'FLOAT_PARAM',
                ['FLOAT_PARAM' => 123.45],
                123.45,
            ],
            'integer' => [
                'REQUEST_TIME',
                ['REQUEST_TIME' => 1_234_567_890],
                1_234_567_890,
            ],
            'integer-zero' => [
                'ZERO_INT',
                ['ZERO_INT' => 0],
                0,
            ],
            'null' => [
                'NULL_PARAM',
                ['NULL_PARAM' => null],
                null,
            ],
            'object' => [
                'OBJECT_PARAM',
                ['OBJECT_PARAM' => $object],
                $object,
            ],
            'string' => [
                'TEST_PARAM',
                ['TEST_PARAM' => 'test_value'],
                'test_value',
            ],
            'string-zero' => [
                'ZERO_STR',
                ['ZERO_STR' => '0'],
                '0',
            ],
        ];
    }

    /**
     * @phpstan-return array<string, array{string, array<string, mixed>, mixed, mixed}>
     */
    public static function serverParamDefaultValueCases(): array
    {
        return [
            'array-when-param-missing' => [
                'MISSING_PARAM',
                [],
                ['default' => 'array'],
                ['default' => 'array'],
            ],
            'boolean-false-value-ignores-default' => [
                'BOOL_PARAM',
                ['BOOL_PARAM' => false],
                true,
                false,
            ],
            'boolean-when-param-missing' => [
                'MISSING_PARAM',
                [],
                true,
                true,
            ],
            'empty-string-ignores-default' => [
                'EMPTY_PARAM',
                ['EMPTY_PARAM' => ''],
                'default_value',
                '',
            ],
            'ignore-default-when-param-exists' => [
                'EXISTING_PARAM',
                ['EXISTING_PARAM' => 'actual_value'],
                'default_value',
                'actual_value',
            ],
            'integer-when-param-missing' => [
                'MISSING_PARAM',
                [],
                42,
                42,
            ],
            'null-param-value-ignores-default' => [
                'NULL_PARAM',
                ['NULL_PARAM' => null],
                'default_value',
                null,
            ],
            'null-when-param-missing' => [
                'MISSING_PARAM',
                [],
                null,
                null,
            ],
            'string-when-param-missing' => [
                'MISSING_PARAM',
                [],
                'default_value',
                'default_value',
            ],
        ];
    }

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
            'Server port as integer when PSR-7 server port.' => [
                [],
                [],
                [],
                ['SERVER_PORT' => '443'],
                443,
                "'getServerPort()' should return integer value when 'SERVER_PORT' is a numeric string.",
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
