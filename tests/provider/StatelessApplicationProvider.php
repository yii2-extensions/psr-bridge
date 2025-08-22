<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\provider;

use stdClass;
use yii2\extensions\psrbridge\http\StatelessApplication;

use function base64_encode;

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
                "Response 'body' should be a JSON string with 'username' and 'password' for 'site/auth' route in " .
                "'StatelessApplication'.",
            ],
            'colon in password' => [
                'Basic ' . base64_encode('user:pa:ss'),
                <<<JSON
                {"username":"user","password":"pa:ss"}
                JSON,
                "Response 'body' should be a JSON string with 'username' and 'password' where password may contain " .
                "colon(s) in 'HTTP_AUTHORIZATION' for 'site/auth' in 'StatelessApplication'.",
            ],
            'empty password' => [
                'Basic ' . base64_encode('user:'),
                <<<JSON
                {"username":"user","password":null}
                JSON,
                "Response 'body' should be a JSON string with 'username' and 'password' as 'null' when password is empty.",
            ],
            'empty username' => [
                'Basic ' . base64_encode(':pass'),
                <<<JSON
                {"username":null,"password":"pass"}
                JSON,
                "Response 'body' should be a JSON string with 'username' as 'null' and 'password' when username is " .
                'empty.',
            ],
            'invalid scheme' => [
                'basix ' . base64_encode('user:pass'),
                <<<JSON
                {"username":null,"password":null}
                JSON,
                "Response 'body' should be a JSON string with 'username' and 'password' as 'null' for invalid " .
                "'HTTP_AUTHORIZATION' header in 'site/auth' route in 'StatelessApplication'.",
            ],
            'lowercase scheme' => [
                'basic ' . base64_encode('user:pass'),
                <<<JSON
                 {"username":"user","password":"pass"}
                 JSON,
                "Response 'body' should be a JSON string with 'username' and 'password' for 'site/auth' route in " .
                "'StatelessApplication'.",
            ],
            'malformed' => [
                'Basic foo:bar',
                <<<JSON
                {"username":null,"password":null}
                JSON,
                "Response 'body' should be a JSON string with 'username' and 'password' as 'null' for malformed " .
                "'HTTP_AUTHORIZATION' header in 'site/auth' route in 'StatelessApplication'.",
            ],
            'missing space' => [
                'Basic' . base64_encode('a:b'),
                <<<JSON
                {"username":null,"password":null}
                JSON,
                "Response 'body' should be a JSON string with 'username' and 'password' as 'null' for malformed " .
                "'HTTP_AUTHORIZATION' header in 'site/auth' route in 'StatelessApplication'.",
            ],
            'no colon' => [
                'Basic ' . base64_encode('userpass'),
                <<<JSON
                {"username":"userpass","password":null}
                JSON,
                "Response 'body' should be a JSON string with 'username' set and 'password' as 'null' when " .
                "credentials contain no colon in 'HTTP_AUTHORIZATION' for 'site/auth' in 'StatelessApplication'.",
            ],
            'non-breaking space' => [
                "basic\xC2\xA0" . base64_encode('user:pass'),
                <<<JSON
                {"username":"user","password":"pass"}
                JSON,
                "Response 'body' should be a JSON string with 'username' and 'password' for 'site/auth' route in " .
                "'StatelessApplication'.",
            ],
            'user' => [
                'Basic ' . base64_encode('user:pass'),
                <<<JSON
                {"username":"user","password":"pass"}
                JSON,
                "Response 'body' should be a JSON string with 'username' and 'password' for 'site/auth' route in " .
                "'StatelessApplication'.",
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
     * @phpstan-return array<string, array{string}>
     */
    public static function eventDataProvider(): array
    {
        return [
            'after request' => [StatelessApplication::EVENT_AFTER_REQUEST],
            'before request' => [StatelessApplication::EVENT_BEFORE_REQUEST],
        ];
    }
}
