<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\provider;

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
            'invalid scheme' => [
                'basix ' . base64_encode('user:pass'),
                <<<JSON
                {"username":null,"password":null}
                JSON,
                "Response 'body' should be a JSON string with 'username' and 'password' as 'null' for invalid " .
                "'HTTP_AUTHORIZATION' header in 'site/auth' route in 'StatelessApplication'.",
            ],
            'malformed' => [
                'Basic foo:bar',
                <<<JSON
                {"username":null,"password":null}
                JSON,
                "Response 'body' should be a JSON string with 'username' and 'password' as 'null' for malformed " .
                "'HTTP_authorization' header in 'site/auth' route in 'StatelessApplication'.",
            ],
            'missing space' => [
                'Basic' . base64_encode('a:b'),
                <<<JSON
                {"username":null,"password":null}
                JSON,
                "Response 'body' should be a JSON string with 'username' and 'password' as 'null' for malformed " .
                "'HTTP_AUTHORIZATION' header in 'site/auth' route in 'StatelessApplication'.",
            ],
            'multibyte' => [
                "basic\xC2\xA0" . base64_encode('user:pass'),
                <<<JSON
                {"username":"user","password":"pass"}
                JSON,
                "Response 'body' should be a JSON string with 'username' and 'password' for 'site/auth' route in " .
                "'StatelessApplication'.",
            ],
            'no colon' => [
                'Basic' . base64_encode('user:pass'),
                <<<JSON
                {"username":null,"password":null}
                JSON,
                "Response 'body' should be a JSON string with 'username' and 'password' as 'null' for malformed " .
                "'HTTP_AUTHORIZATION' header in 'site/auth' route in 'StatelessApplication'.",
            ],
            'user' => [
                'Basic ' . base64_encode('user:pass'),
                <<<JSON
                {"username":"user","password":"pass"}
                JSON,
                "Response 'Content-Type' should be 'application/json; charset=UTF-8' for 'site/auth' route in " .
                "'StatelessApplication'.",
            ],
            'username only' => [
                'Basic ' . base64_encode('usernameonly'),
                <<<JSON
                {"username":"usernameonly","password":null}
                JSON,
                "Response 'body' should be a JSON string with 'username' and 'password' as 'null' for " .
                "'HTTP_AUTHORIZATION' header in 'site/auth' route in 'StatelessApplication'.",
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
