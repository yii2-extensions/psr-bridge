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
