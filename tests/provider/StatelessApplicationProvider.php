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
}
