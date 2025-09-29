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
}
