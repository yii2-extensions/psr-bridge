<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\provider;

/**
 * Data provider for {@see \yii2\extensions\psrbridge\tests\http\ServerParamsPsr7Test} and related server params test
 * classes.
 *
 * Supplies comprehensive test data for server parameter normalization and extraction scenarios, including remote host,
 * server name, and generic server parameter handling with and without default values.
 *
 * Key features.
 * - Covers edge cases for remote host and server name normalization (IPv4, IPv6, domain, boolean, integer, etc.).
 * - Provides test data for absent, null, array, object, and scalar server parameters.
 * - Supports validation of default value logic for missing or empty server parameters.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class ServerParamsPsr7Provider
{
    /**
     * Provides test data for remote host normalization scenarios.
     *
     * This provider supplies test cases for validating the extraction and normalization of remote host values from
     * various server parameter types, including IPv4, IPv6, domain names, booleans, integers, arrays, objects, and
     * empty or `null` values.
     *
     * Each test case consists of the input value and the expected normalized remote host string or `null` result.
     *
     * @return array test data with input remote host values and expected normalized results.
     *
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
     * Provides test data for server name normalization scenarios.
     *
     * This provider supplies test cases for validating the extraction and normalization of server name values from
     * various types, including domains, IPv4, IPv6, booleans, integers, arrays, objects, and empty or `null` values.
     *
     * Each test case consists of the input value and the expected normalized server name string or `null` result.
     *
     * @return array test data with input server name values and expected normalized results.
     *
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
     * Provides test data for server parameter extraction and normalization scenarios.
     *
     * This provider supplies test cases for validating the extraction and normalization of server parameter values
     * from various types, including absent, `null`, array, object, and scalar values.
     *
     * Each test case consists of the parameter name, the server parameters array, and the expected extracted value.
     *
     * @return array test data with parameter name, server parameters, and expected extracted value.
     *
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
     * Provides test data for server parameter extraction with default value logic.
     *
     * This provider supplies test cases for validating the extraction of server parameter values when the parameter
     * is missing, present, or has specific values (including `null`, empty string, boolean, integer, and array), and
     * ensures correct handling of default values in each scenario.
     *
     * Each test case includes the parameter name, the server parameters array, the default value, and the expected
     * result after applying default value logic.
     *
     * @return array test data with parameter name, server parameters, default value, and expected result.
     *
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
