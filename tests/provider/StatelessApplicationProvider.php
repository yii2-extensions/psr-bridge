<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\provider;

use stdClass;
use yii\base\Exception;
use yii2\extensions\psrbridge\http\Response;

use function base64_encode;

use const PHP_INT_SIZE;

final class StatelessApplicationProvider
{
    /**
     * @phpstan-return array<string, array{string, string, string}>
     */
    public static function authCredentials(): array
    {
        return [
            'admin' => [
                'Basic ' . base64_encode('admin:admin'),
                <<<JSON
                {"username":"admin","password":"admin"}
                JSON,
                "Response body should be a JSON string with 'username' and 'password'.",
            ],
            'colon in password' => [
                'Basic ' . base64_encode('user:pa:ss'),
                <<<JSON
                {"username":"user","password":"pa:ss"}
                JSON,
                "Response body should be a JSON string with 'username' and 'password' where the password may contain " .
                'colon(s) in HTTP_AUTHORIZATION.',
            ],
            'empty password' => [
                'Basic ' . base64_encode('user:'),
                <<<JSON
                {"username":"user","password":null}
                JSON,
                "Response body should be a JSON string with 'username' and 'password' as 'null' when password is empty.",
            ],
            'empty username' => [
                'Basic ' . base64_encode(':pass'),
                <<<JSON
                {"username":null,"password":"pass"}
                JSON,
                "Response body should be a JSON string with 'username' as 'null' and 'password' when username is " .
                'empty.',
            ],
            'invalid scheme' => [
                'basix ' . base64_encode('user:pass'),
                <<<JSON
                {"username":null,"password":null}
                JSON,
                "Response body should be a JSON string with 'username' and 'password' as 'null' for invalid " .
                'HTTP_AUTHORIZATION header.',
            ],
            'lowercase scheme' => [
                'basic ' . base64_encode('user:pass'),
                <<<JSON
                {"username":"user","password":"pass"}
                JSON,
                "Response body should be a JSON string with 'username' and 'password'.",
            ],
            'malformed' => [
                'Basic foo:bar',
                <<<JSON
                {"username":null,"password":null}
                JSON,
                "Response body should be a JSON string with 'username' and 'password' as 'null' for malformed " .
                'HTTP_AUTHORIZATION header.',
            ],
            'missing space' => [
                'Basic' . base64_encode('a:b'),
                <<<JSON
                {"username":null,"password":null}
                JSON,
                "Response body should be a JSON string with 'username' and 'password' as 'null' for malformed " .
                'HTTP_AUTHORIZATION header.',
            ],
            'no colon' => [
                'Basic ' . base64_encode('userpass'),
                <<<JSON
                {"username":"userpass","password":null}
                JSON,
                "Response body should be a JSON string with 'username' set and 'password' as 'null' when " .
                'credentials contain no colon in HTTP_AUTHORIZATION.',
            ],
            'non-breaking space' => [
                "basic\xC2\xA0" . base64_encode('user:pass'),
                <<<JSON
                {"username":"user","password":"pass"}
                JSON,
                "Response body should be a JSON string with 'username' and 'password'.",
            ],
            'user' => [
                'Basic ' . base64_encode('user:pass'),
                <<<JSON
                {"username":"user","password":"pass"}
                JSON,
                "Response body should be a JSON string with 'username' and 'password'.",
            ],
        ];
    }

    /**
     * @phpstan-return array<string, array{bool, bool, array<string, object|string>, string, string}>
     */
    public static function cookies(): array
    {
        $cookieWithObject = new stdClass();

        $cookieWithObject->property = 'object_value';

        return [
            'validation disabled' => [
                false,
                false,
                ['valid_cookie' => 'valid_data'],
                <<<JSON
                {"valid_cookie":{"name":"valid_cookie","value":"valid_data","domain":"","expire":null,"path":"/","secure":false,"httpOnly":true,"sameSite":"Lax"}}
                JSON,
                "Response body should contain the 'valid_cookie' cookie with its properties.",
            ],
            'validation disabled with multiple cookies' => [
                false,
                false,
                ['first' => 'value1', 'second' => 'value2', 'third' => 'value3'],
                <<<JSON
                {"first":{"name":"first","value":"value1","domain":"","expire":null,"path":"/","secure":false,"httpOnly":true,"sameSite":"Lax"},"second":{"name":"second","value":"value2","domain":"","expire":null,"path":"/","secure":false,"httpOnly":true,"sameSite":"Lax"},"third":{"name":"third","value":"value3","domain":"","expire":null,"path":"/","secure":false,"httpOnly":true,"sameSite":"Lax"}}
                JSON,
                'Response body should contain all cookies when validation is disabled regardless of content.',
            ],
            'validation enabled with empty cookie' => [
                true,
                false,
                ['empty_cookie' => ''],
                <<<JSON
                []
                JSON,
                'Response body should be an empty JSON array when cookie value is empty and validation is enabled.',
            ],
            'validation enabled with invalid cookie' => [
                true,
                false,
                ['invalid_cookie' => 'invalid_data'],
                <<<JSON
                []
                JSON,
                'Response body should be an empty JSON array when cookie value is invalid and validation is enabled.',
            ],
            'validation enabled with valid object cookie' => [
                true,
                true,
                [
                    'object_cookie' => $cookieWithObject,
                    'validated_session' => 'safe_value',
                ],
                <<<JSON
                {"object_cookie":{"name":"object_cookie","value":{"__PHP_Incomplete_Class_Name":"stdClass","property":"object_value"},"domain":"","expire":null,"path":"/","secure":false,"httpOnly":true,"sameSite":"Lax"},"validated_session":{"name":"validated_session","value":"safe_value","domain":"","expire":null,"path":"/","secure":false,"httpOnly":true,"sameSite":"Lax"}}
                JSON,
                'Response body should contain valid sanitize object cookie with its properties when validation is enabled.',
            ],
            'validation enabled with single valid signed cookie' => [
                true,
                true,
                ['single_cookie' => 'single_value'],
                <<<JSON
                {"single_cookie":{"name":"single_cookie","value":"single_value","domain":"","expire":null,"path":"/","secure":false,"httpOnly":true,"sameSite":"Lax"}}
                JSON,
                'Response body should contain single valid signed cookie with all properties.',
            ],
            'validation enabled with signed valid multiple cookies' => [
                true,
                true,
                [
                    'language' => 'en_US_012',
                    'session_id' => 'session_value_123',
                    'theme' => 'dark_theme_789',
                    'user_pref' => 'preference_value_456',
                ],
                <<<JSON
                {"language":{"name":"language","value":"en_US_012","domain":"","expire":null,"path":"/","secure":false,"httpOnly":true,"sameSite":"Lax"},"session_id":{"name":"session_id","value":"session_value_123","domain":"","expire":null,"path":"/","secure":false,"httpOnly":true,"sameSite":"Lax"},"theme":{"name":"theme","value":"dark_theme_789","domain":"","expire":null,"path":"/","secure":false,"httpOnly":true,"sameSite":"Lax"},"user_pref":{"name":"user_pref","value":"preference_value_456","domain":"","expire":null,"path":"/","secure":false,"httpOnly":true,"sameSite":"Lax"}}
                JSON,
                'Response body should contain signed cookies with their properties when validation is enabled.',
            ],
        ];
    }

    /**
     * @phpstan-return array<string, array{bool, string, string, int, string, string}>
     */
    public static function errorViewLogic(): array
    {
        return [
            'debug false with Exception' => [
                false,
                'site/trigger-exception',
                'site/error',
                500,
                <<<HTML
                <div id="custom-error-action">
                Custom error page from errorAction.
                <span class="exception-type">
                yii\base\Exception
                </span>
                <span class="exception-message">
                Exception error message.
                </span>
                </div>
                HTML,
                "Response body should contain 'Custom error page from errorAction' when Exception is triggered and " .
                "YII_DEBUG mode is disabled with 'errorAction' configured.",
            ],
            'debug false with UserException' => [
                false,
                'site/trigger-user-exception',
                'site/error',
                500,
                <<<HTML
                <div id="custom-error-action">
                Custom error page from errorAction.
                <span class="exception-type">
                yii\base\UserException
                </span>
                <span class="exception-message">
                User-friendly error message.
                </span>
                </div>
                HTML,
                "Response body should contain 'Custom error page from errorAction' when UserException is triggered " .
                "and YII_DEBUG mode is disabled with 'errorAction' configured.",
            ],
            'debug true with UserException' => [
                true,
                'site/trigger-user-exception',
                'site/error',
                500,
                <<<HTML
                <div id="custom-error-action">
                Custom error page from errorAction.
                <span class="exception-type">
                yii\base\UserException
                </span>
                <span class="exception-message">
                User-friendly error message.
                </span>
                </div>
                HTML,
                "Response body should contain 'Custom error page from errorAction' when UserException is triggered " .
                "and YII_DEBUG mode is enabled with 'errorAction' configured.",
            ],
        ];
    }

    /**
     * @phpstan-return array<string, array{string, string, int, string, string[]}>
     */
    public static function exceptionRenderingFormats(): array
    {
        return [
            'HTML format with exception' => [
                Response::FORMAT_HTML,
                'text/html; charset=UTF-8',
                500,
                'site/trigger-exception',
                [
                    Exception::class,
                    'Exception error message.',
                    'Stack trace:',
                ],
            ],
            'JSON format with exception' => [
                Response::FORMAT_JSON,
                'application/json; charset=UTF-8',
                500,
                'site/trigger-exception',
                ['"message"'],
            ],
            'RAW format with exception' => [
                Response::FORMAT_RAW,
                '',
                500,
                'site/trigger-exception',
                [
                    Exception::class,
                    'Exception error message.',
                ],
            ],
        ];
    }

    /**
     * @phpstan-return array<string, array{string, int, bool, string}>
     */
    public static function garbageCollection(): array
    {
        return [
            'moderate load' => [
                '256M',
                15,
                true,
                "GC should be triggered with moderate object creation ('15' iterations) and '256M' limit.",
            ],
            'heavy load' => [
                '512M',
                25,
                true,
                "GC should be triggered with heavy object creation ('25' iterations) and '512M' limit.",
            ],
            'light load' => [
                '1G',
                5,
                false,
                "GC might not be needed with light object creation ('5' iterations) and '1G' limit.",
            ],
        ];
    }

    /**
     * @phpstan-return array<string, array{int, string}>
     */
    public static function memoryLimitPositive(): array
    {
        $data = [
            'small value 1KB' => [
                1024,
                "Memory limit should be set to exactly '1024' bytes ('1KB') when positive value is provided.",
            ],
            'medium value 128MB' => [
                134_217_728,
                "Memory limit should be set to exactly '134_217_728' bytes ('128MB') when positive value is provided.",
            ],
            'large value 256MB' => [
                268_435_456,
                "Memory limit should be set to exactly '268_435_456' bytes ('256MB') when positive value is provided.",
            ],
            'very large value near 32-bit INT_MAX' => [
                2_147_483_647,
                'Memory limit should handle large positive values correctly without overflow when set to ' .
                "'2_147_483_647' bytes.",
            ],
        ];

        if (PHP_INT_SIZE >= 8) {
            $data['ultra large value 4GiB (64-bit only)'] = [
                4_294_967_296,
                "Memory limit should be set to exactly '4_294_967_296' bytes ('4GiB') when a positive value is " .
                "provided on '64-bit' builds.",
            ];
        }

        return $data;
    }

    /**
     * @phpstan-return array<string, array{string, bool, string}>
     */
    public static function memoryThreshold(): array
    {
        return [
            'low usage - should not clean' => [
                '1G',
                false,
                "'clean()' should return 'false' when memory usage is below '90%' threshold with '1G' limit.",
            ],
            'moderate usage - should not clean' => [
                '512M',
                false,
                "'clean()' should return 'false' when memory usage is below '90%' threshold with '512M' limit.",
            ],
            'threshold calculation - 100M' => [
                '100M',
                false,
                "'clean()' should return 'false' with '100M' limit and verify correct '90%' threshold calculation.",
            ],
            'high memory setup - 2G' => [
                '2G',
                true,
                "'clean()' should return 'true' when memory usage equals the calculated '90%' threshold " .
                '(using adjusted limit).',
            ],
        ];
    }

    /**
     * @phpstan-return array<string, array{string, int, string}>
     */
    public static function parseMemoryLimit(): array
    {
        return [
            'empty string' => [
                '',
                0,
                "'parseMemoryLimit('')' should return '0' when no parsing is possible.",
            ],
            'gigabytes lowercase' => [
                '1g',
                1_073_741_824,
                "'parseMemoryLimit('1g')' should return '1_073_741_824 bytes'.",
            ],
            'gigabytes uppercase' => [
                '2G',
                2_147_483_648,
                "'parseMemoryLimit('2G')' should return '2_147_483_648 bytes'.",
            ],
            'invalid string' => [
                'abc',
                0,
                "'parseMemoryLimit('abc')' should return '0' when string starts with non-numeric characters.",
            ],
            'kilobytes lowercase' => [
                '32k',
                32_768,
                "'parseMemoryLimit('32k')' should return '32_768 bytes'.",
            ],
            'kilobytes uppercase' => [
                '64K',
                65_536,
                "'parseMemoryLimit('64K')' should return '65_536 bytes'.",
            ],
            'megabytes lowercase' => [
                '64m',
                67_108_864,
                "'parseMemoryLimit('64m')' should return '67_108_864 bytes'.",
            ],
            'megabytes uppercase' => [
                '128M',
                134_217_728,
                "'parseMemoryLimit('128M')' should return '134_217_728 bytes'.",
            ],
            'mixed case unknown suffix' => [
                '50Z',
                50,
                "'parseMemoryLimit('50Z')' should return '50 bytes' for unknown suffix.",
            ],
            'number with space suffix should multiply by 1' => [
                '512 ',
                512,
                "'parseMemoryLimit('512 ')' should handle space suffix correctly ('multiplier = 1').",
            ],
            'number with trailing characters' => [
                '256MB',
                268_435_456,
                "'parseMemoryLimit('256MB')' should parse only first suffix 'M' and return '268_435_456 bytes'.",
            ],
            'plain number' => [
                '1024',
                1024,
                "'parseMemoryLimit('1024')' should return '1024 bytes' for plain numeric input.",
            ],
            'space suffix edge case' => [
                '1 ',
                1,
                "'parseMemoryLimit('1 ')' should handle space suffix correctly.",
            ],
            'space suffix with different number' => [
                '256 ',
                256,
                "'parseMemoryLimit('256 ')' should multiply by '1' with space suffix.",
            ],
            'special characters' => [
                '@#$%',
                0,
                "'parseMemoryLimit('@#\$%')' should return '0' when only special characters are present.",
            ],
            'unknown suffix lowercase' => [
                '200x',
                200,
                "'parseMemoryLimit('200x')' should return '200 bytes'.",
            ],
            'unknown suffix' => [
                '100T',
                100,
                "'parseMemoryLimit('100T')' should return '100 bytes' using fallback multiplier.",
            ],
            'unlimited' => [
                '-1',
                PHP_INT_MAX,
                "'parseMemoryLimit('-1')' should return PHP_INT_MAX for unlimited memory.",
            ],
            'zero should work correctly' => [
                '0',
                0,
                "'parseMemoryLimit('0')' should return '0 bytes'.",
            ],
        ];
    }

    /**
     * @phpstan-return array<array{int|string, string|null, string}>
     */
    public static function remoteIPAddresses(): array
    {
        return [
            'empty string' => [
                '',
                null,
                "'getRemoteIP()' should return 'null' for empty IP address string.",
            ],
            'invalid IP address format' => [
                '999.999.999.999',
                null,
                "'getRemoteIP()' should return 'null' for invalid IPv4 address '999.999.999.999'.",
            ],
            'malformed IPv6 address' => [
                '2001:0db8:85a3::8a2e::7334',
                null,
                "'getRemoteIP()' should return 'null' for malformed IPv6 address '2001:0db8:85a3::8a2e::7334'.",
            ],
            'non-IP integer' => [
                123456,
                null,
                "'getRemoteIP()' should return 'null' for non-IP integer '123456'.",
            ],
            'non-IP string' => [
                'localhost',
                null,
                "'getRemoteIP()' should return 'null' for non-IP string 'localhost'.",
            ],
            'valid IPv4 address' => [
                '192.168.1.1',
                '192.168.1.1',
                "'getRemoteIP()' should return '192.168.1.1' for valid IPv4 address.",
            ],
            'valid IPv4 loopback address' => [
                '127.0.0.1',
                '127.0.0.1',
                "'getRemoteIP()' should return '127.0.0.1' for valid IPv4 loopback address.",
            ],
            'valid IPv6 address' => [
                '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
                '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
                "'getRemoteIP()' should return '2001:0db8:85a3:0000:0000:8a2e:0370:7334' for valid IPv6 address.",
            ],
            'valid IPv6 compressed address' => [
                '2001:db8::8a2e:370:7334',
                '2001:db8::8a2e:370:7334',
                "'getRemoteIP()' should return '2001:db8::8a2e:370:7334' for valid compressed IPv6 address.",
            ],
            'valid IPv6 loopback address' => [
                '::1',
                '::1',
                "'getRemoteIP()' should return '::1' for valid IPv6 loopback address.",
            ],
            'valid public IPv4 address' => [
                '8.8.8.8',
                '8.8.8.8',
                "'getRemoteIP()' should return '8.8.8.8' for valid public IPv4 address.",
            ],
        ];
    }

    /**
     * @phpstan-return array<string, array{array<string, string>, int|null, string}>
     */
    public static function serverPortHeaders(): array
    {
        return [
            'empty' => [
                ['X-Forwarded-Port' => ''],
                null,
                "'getServerPort()' should return 'null' for empty port header.",
            ],
            'maximum' => [
                ['X-Forwarded-Port' => '65535'],
                65535,
                "'getServerPort()' should return '65535' for maximum valid port number.",
            ],
            'minimum' => [
                ['X-Forwarded-Port' => '1'],
                1,
                "'getServerPort()' should return '1' for minimum valid port number.",
            ],
            'multiple ports comma separated' => [
                ['X-Forwarded-Port' => '8080,9090,3000'],
                8080,
                "'getServerPort()' should return first port '8080' from comma-separated list.",
            ],
            'multiple ports with whitespace' => [
                ['X-Forwarded-Port' => '  443  , 8080, 9090'],
                443,
                "'getServerPort()' should return first port '443' after trimming whitespace from comma-separated list.",
            ],
            'negative' => [
                ['X-Forwarded-Port' => '-1'],
                null,
                "'getServerPort()' should return 'null' for negative port number '-1'.",
            ],
            'non-numeric port value' => [
                ['X-Forwarded-Port' => 'abc'],
                null,
                "'getServerPort()' should return 'null' for non-numeric port value 'abc'.",
            ],
            'port above valid range' => [
                ['X-Forwarded-Port' => '65536'],
                null,
                "'getServerPort()' should return 'null' for port '65536' above valid range.",
            ],
            'port with leading zeros' => [
                ['X-Forwarded-Port' => '08080'],
                8080,
                "'getServerPort()' should return '8080' for port with leading zeros '08080'.",
            ],
            'port zero' => [
                ['X-Forwarded-Port' => '0'],
                null,
                "'getServerPort()' should return 'null' for invalid port '0'.",
            ],
            'unsupported header should be ignored' => [
                ['X-Custom-Port' => '9000'],
                null,
                "'getServerPort()' should return 'null' when unsupported header is provided.",
            ],
            'valid port from X-Forwarded-Port header with whitespace' => [
                ['X-Forwarded-Port' => '  443  '],
                443,
                "'getServerPort()' should return '443' after trimming whitespace.",
            ],
            'valid port from X-Forwarded-Port header' => [
                ['X-Forwarded-Port' => '8080'],
                8080,
                "'getServerPort()' should return '8080'.",
            ],
            'whitespace only' => [
                ['X-Forwarded-Port' => '   '],
                null,
                "'getServerPort()' should return 'null' for whitespace-only header value.",
            ],
        ];
    }
}
