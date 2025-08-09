<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\provider;

final class RequestProvider
{
    /**
     * @phpstan-return array{
     *   resolvedXForwardedFor: array<array-key, mixed>,
     *   resolvedXForwardedForWithHttps: array<array-key, mixed>
     * }
     */
    public static function alreadyResolvedIp(): array
    {
        return [
            'resolvedXForwardedFor' => [
                '50.0.0.1',
                '1.1.1.1, 8.8.8.8, 9.9.9.9',
                'http',
                [
                    '0.0.0.0/0',
                ],
                // checks:
                '50.0.0.1',
                '50.0.0.1',
                false,
            ],
            'resolvedXForwardedForWithHttps' => [
                '50.0.0.1',
                '1.1.1.1, 8.8.8.8, 9.9.9.9',
                'https',
                [
                    '0.0.0.0/0',
                ],
                // checks:
                '50.0.0.1',
                '50.0.0.1',
                true,
            ],
        ];
    }

    /**
     * @phpstan-return array{
     *   get: array{string, string, array<string, string>},
     *   json: array{string, string, array<string, int|string>},
     *   jsonp: array{string, string, array<string, int|string>}
     * }
     */
    public static function getBodyParams(): array
    {
        return [
            'get' => [
                'application/x-www-form-urlencoded',
                'foo=bar&baz=1',
                [
                    'foo' => 'bar',
                    'baz' => '1',
                ],
            ],
            'json' => [
                'application/json',
                '{"foo":"bar","baz":1}',
                [
                    'foo' => 'bar',
                    'baz' => 1,
                ],
            ],
            'jsonp' => [
                'application/javascript',
                'parseResponse({"foo":"bar","baz":1});',
                [
                    'foo' => 'bar',
                    'baz' => 1,
                ],
            ],
        ];
    }

    /**
     * @return array<int, array{array<string, mixed>|array<string, string>, array{string|null, string|null}}>
     */
    public static function getHostInfo(): array
    {
        return [
            // empty
            [
                [],
                [
                    null,
                    null,
                ],
            ],
            // normal
            [
                [
                    'HTTP_HOST' => 'example1.com',
                    'SERVER_NAME' => 'example2.com',
                ],
                [
                    'http://example1.com',
                    'example1.com',
                ],
            ],
            // HTTP header missing
            [
                ['SERVER_NAME' => 'example2.com'],
                [
                    'http://example2.com',
                    'example2.com',
                ],
            ],
            // forwarded from untrusted server
            [
                [
                    'HTTP_X_FORWARDED_HOST' => 'example3.com',
                    'HTTP_HOST' => 'example1.com',
                    'SERVER_NAME' => 'example2.com',
                ],
                [
                    'http://example1.com',
                    'example1.com',
                ],
            ],
            // forwarded from trusted proxy
            [
                [
                    'HTTP_X_FORWARDED_HOST' => 'example3.com',
                    'HTTP_HOST' => 'example1.com',
                    'SERVER_NAME' => 'example2.com',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                [
                    'http://example3.com',
                    'example3.com',
                ],
            ],
            // forwarded from trusted proxy
            [
                [
                    'HTTP_X_FORWARDED_HOST' => 'example3.com, example2.com',
                    'HTTP_HOST' => 'example1.com',
                    'SERVER_NAME' => 'example2.com',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                [
                    'http://example3.com',
                    'example3.com',
                ],
            ],
            // RFC 7239 forwarded from untrusted server
            [
                [
                    'HTTP_FORWARDED' => 'host=example3.com',
                    'HTTP_HOST' => 'example1.com',
                    'SERVER_NAME' => 'example2.com',
                ],
                [
                    'http://example1.com',
                    'example1.com',
                ],
            ],
            // RFC 7239 forwarded from trusted proxy
            [
                [
                    'HTTP_FORWARDED' => 'host=example3.com',
                    'HTTP_HOST' => 'example1.com',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                [
                    'http://example3.com',
                    'example3.com',
                ],
            ],
            // RFC 7239 forwarded from trusted proxy
            [
                [
                    'HTTP_FORWARDED' => 'host=example3.com,host=example2.com',
                    'HTTP_HOST' => 'example1.com',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                [
                    'http://example2.com',
                    'example2.com',
                ],
            ],
        ];
    }

    /**
     * @phpstan-return array<array{array<string, string>, bool}>
     */
    public static function getIsAjax(): array
    {
        return [
            [
                [],
                false,
            ],
            [
                [
                    'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
                ],
                true,
            ],
        ];
    }

    /**
     * @phpstan-return array<array{array<string, string>, bool}>
     */
    public static function getIsPjax(): array
    {
        return [
            [
                [],
                false,
            ],
            [
                [
                    'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
                    'HTTP_X_PJAX' => 'any value',
                ],
                true,
            ],
        ];
    }

    /**
     * @phpstan-return array<array{array<string, string>, string}>
     */
    public static function getMethod(): array
    {
        return [
            [
                [
                    'REQUEST_METHOD' => 'DEFAULT',
                    'HTTP_X_HTTP_METHOD_OVERRIDE' => 'OVERRIDE',
                ],
                'OVERRIDE',
            ],
            [
                ['REQUEST_METHOD' => 'DEFAULT'],
                'DEFAULT',
            ],
        ];
    }

    /**
     * @phpstan-return array<string, array{string, string}>
     */
    public static function getQueryString(): array
    {
        return [
            'complexQuery' => [
                'filters%5Btype%5D=article&filters%5Bstatus%5D=published&tags%5B%5D=php&tags%5B%5D=web',
                'filters%5Btype%5D=article&filters%5Bstatus%5D=published&tags%5B%5D=php&tags%5B%5D=web',
            ],
            'emptyQuery' => [
                '',
                '',
            ],
            'encodedParameters' => [
                'search=hello%20world&category=tech%26science',
                'search=hello%20world&category=tech%26science',
            ],
            'multipleParameters' => [
                'page=1&limit=10&sort=name',
                'page=1&limit=10&sort=name',
            ],
            'parameterWithoutValue' => [
                'debug&verbose=1',
                'debug&verbose=1',
            ],
            'singleParameter' => [
                'page=1',
                'page=1',
            ],
        ];
    }

    /**
     * @phpstan-return array<string, array{string, string}>
     */
    public static function getUrl(): array
    {
        return [
            'complexQueryString' => [
                '/search?q=hello+world&category=books&price[min]=10&price[max]=50',
                '/search?q=hello+world&category=books&price%5Bmin%5D=10&price%5Bmax%5D=50',
            ],
            'rootPath' => [
                '/',
                '/',
            ],
            'withoutQueryString' => [
                '/search?q=hello%20world&path=%2Fsome%2Fpath',
                '/search?q=hello%20world&path=%2Fsome%2Fpath',
            ],
        ];
    }

    /**
     * @phpstan-return array<array{array<string, string>, string}>
     */
    public static function getUserIP(): array
    {
        return [
            [
                [
                    'HTTP_X_FORWARDED_PROTO' => 'https',
                    'HTTP_X_FORWARDED_FOR' => '123.123.123.123',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                '123.123.123.123',
            ],
            [
                [
                    'HTTP_X_FORWARDED_PROTO' => 'https',
                    'HTTP_X_FORWARDED_FOR' => '123.123.123.123',
                    'REMOTE_ADDR' => '192.169.1.1',
                ],
                '192.169.1.1',
            ],
            [
                [
                    'HTTP_X_FORWARDED_PROTO' => 'https',
                    'HTTP_X_FORWARDED_FOR' => '123.123.123.123',
                    'REMOTE_HOST' => 'untrusted.com',
                    'REMOTE_ADDR' => '192.169.1.1',
                ],
                '192.169.1.1',
            ],
            [
                [
                    'HTTP_X_FORWARDED_PROTO' => 'https',
                    'HTTP_X_FORWARDED_FOR' => '192.169.1.1',
                    'REMOTE_HOST' => 'untrusted.com',
                    'REMOTE_ADDR' => '192.169.1.1',
                ],
                '192.169.1.1',
            ],
            // RFC 7239 forwarded from trusted proxy
            [
                [
                    'HTTP_FORWARDED' => 'for=123.123.123.123',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                '123.123.123.123',
            ],
            // RFC 7239 forwarded from trusted proxy with optinal port
            [
                [
                    'HTTP_FORWARDED' => 'for=123.123.123.123:2222',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                '123.123.123.123',
            ],
            // RFC 7239 forwarded from trusted proxy, through another proxy
            [
                [
                    'HTTP_FORWARDED' => 'for=123.123.123.123,for=122.122.122.122',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                '122.122.122.122',
            ],
            // RFC 7239 forwarded from trusted proxy, through another proxy, client IP with optional port
            [
                [
                    'HTTP_FORWARDED' => 'for=123.123.123.123:2222,for=122.122.122.122:2222',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                '122.122.122.122',
            ],
            // RFC 7239 forwarded from untrusted proxy
            [
                [
                    'HTTP_FORWARDED' => 'for=123.123.123.123',
                    'REMOTE_ADDR' => '192.169.1.1',
                ],
                '192.169.1.1',
            ],
            // RFC 7239 forwarded from trusted proxy with optional port
            [
                [
                    'HTTP_FORWARDED' => 'for=123.123.123.123:2222',
                    'REMOTE_ADDR' => '192.169.1.1',
                ],
                '192.169.1.1',
            ],
            // RFC 7239 forwarded from trusted proxy with client IPv6
            [
                [
                    'HTTP_FORWARDED' => 'for="2001:0db8:85a3:0000:0000:8a2e:0370:7334"',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
            ],
            // RFC 7239 forwarded from trusted proxy with client IPv6 and optional port
            [
                [
                    'HTTP_FORWARDED' => 'for="[2001:0db8:85a3:0000:0000:8a2e:0370:7334]:2222"',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
            ],
            // RFC 7239 forwarded from trusted proxy, through another proxy with client IPv6
            [
                [
                    'HTTP_FORWARDED' => 'for=122.122.122.122,for="2001:0db8:85a3:0000:0000:8a2e:0370:7334"',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
            ],
            // RFC 7239 forwarded from trusted proxy, through another proxy with client IPv6 and optional port
            [
                [
                    'HTTP_FORWARDED' => 'for=122.122.122.122:2222,for="[2001:0db8:85a3:0000:0000:8a2e:0370:7334]:2222"',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
            ],
            // RFC 7239 forwarded from untrusted proxy with client IPv6
            [
                [
                    'HTTP_FORWARDED' => 'for"=2001:0db8:85a3:0000:0000:8a2e:0370:7334"',
                    'REMOTE_ADDR' => '192.169.1.1',
                ],
                '192.169.1.1',
            ],
            // RFC 7239 forwarded from untrusted proxy, through another proxy with client IPv6 and optional port
            [
                [
                    'HTTP_FORWARDED' => 'for="[2001:0db8:85a3:0000:0000:8a2e:0370:7334]:2222"',
                    'REMOTE_ADDR' => '192.169.1.1',
                ],
                '192.169.1.1',
            ],
        ];
    }

    /**
     * @phpstan-return array<array{array<string, string>, string}>
     */
    public static function getUserIPWithoutTrustedHost(): array
    {
        return [
            // RFC 7239 forwarded is not enabled
            [
                [
                    'HTTP_FORWARDED' => 'for=123.123.123.123',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                '192.168.0.1',
            ],
        ];
    }

    /**
     * @phpstan-return array<array{string, array{false|string|null, string|null}}>
     */
    public static function httpAuthorizationHeaders(): array
    {
        return [
            [
                'not a base64 at all',
                [
                    null,
                    null,
                ],
            ],
            [
                base64_encode('user:'),
                [
                    'user',
                    null,
                ],
            ],
            [
                base64_encode('user'),
                [
                    'user',
                    null,
                ],
            ],
            [
                base64_encode('user:pw'),
                [
                    'user',
                    'pw',
                ],
            ],
            [
                base64_encode('user:pw'),
                [
                    'user',
                    'pw',
                ],
            ],
            [
                base64_encode('user:a:b'),
                [
                    'user',
                    'a:b',
                ],
            ],
            [
                base64_encode(':a:b'),
                [
                    null,
                    'a:b',
                ],
            ],
            [
                base64_encode(':'),
                [
                    null,
                    null,
                ],
            ],
        ];
    }

    /**
     * @phpstan-return array<array{array<string, int|string>, bool}>
     */
    public static function isSecureServer(): array
    {
        return [
            [
                ['HTTPS' => 1],
                true,
            ],
            [
                ['HTTPS' => 'on'],
                true,
            ],
            [
                ['HTTPS' => 0],
                false,
            ],
            [
                ['HTTPS' => 'off'],
                false,
            ],
            [
                [],
                false,
            ],
            [
                ['HTTP_X_FORWARDED_PROTO' => 'https'],
                false,
            ],
            [
                ['HTTP_X_FORWARDED_PROTO' => 'http'],
                false,
            ],
            [
                [
                    'HTTP_X_FORWARDED_PROTO' => 'https',
                    'REMOTE_HOST' => 'test.com',
                ],
                false,
            ],
            [
                [
                    'HTTP_X_FORWARDED_PROTO' => 'https',
                    'REMOTE_HOST' => 'othertest.com',
                ],
                false,
            ],
            [
                [
                    'HTTP_X_FORWARDED_PROTO' => 'https',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                true,
            ],
            [
                [
                    'HTTP_X_FORWARDED_PROTO' => 'https',
                    'REMOTE_ADDR' => '192.169.0.1',
                ],
                false,
            ],
            [
                ['HTTP_FRONT_END_HTTPS' => 'on'],
                false,
            ],
            [
                ['HTTP_FRONT_END_HTTPS' => 'off'],
                false,
            ],
            [
                [
                    'HTTP_FRONT_END_HTTPS' => 'on',
                    'REMOTE_HOST' => 'test.com',
                ],
                false,
            ],
            [
                [
                    'HTTP_FRONT_END_HTTPS' => 'on',
                    'REMOTE_HOST' => 'othertest.com',
                ],
                false,
            ],
            [
                [
                    'HTTP_FRONT_END_HTTPS' => 'on',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                true,
            ],
            [
                [
                    'HTTP_FRONT_END_HTTPS' => 'on',
                    'REMOTE_ADDR' => '192.169.0.1',
                ],
                false,
            ],
            // RFC 7239 forwarded from untrusted proxy
            [
                ['HTTP_FORWARDED' => 'proto=https'],
                false,
            ],
            // RFC 7239 forwarded from two untrusted proxies
            [
                ['HTTP_FORWARDED' => 'proto=https,proto=http'],
                false,
            ],
            // RFC 7239 forwarded from trusted proxy
            [
                [
                    'HTTP_FORWARDED' => 'proto=https',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                true,
            ],
            // RFC 7239 forwarded from trusted proxy, second proxy not encrypted
            [
                [
                    'HTTP_FORWARDED' => 'proto=https,proto=http',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                false,
            ],
            // RFC 7239 forwarded from trusted proxy, second proxy encrypted, while client request not encrypted
            [
                [
                    'HTTP_FORWARDED' => 'proto=http,proto=https',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                true,
            ],
            // RFC 7239 forwarded from untrusted proxy
            [
                [
                    'HTTP_FORWARDED' => 'proto=https',
                    'REMOTE_ADDR' => '192.169.0.1',
                ],
                false,
            ],
            // RFC 7239 forwarded from untrusted proxy, second proxy not encrypted
            [
                [
                    'HTTP_FORWARDED' => 'proto=https,proto=http',
                    'REMOTE_ADDR' => '192.169.0.1',
                ],
                false,
            ],
            // RFC 7239 forwarded from untrusted proxy, second proxy encrypted, while client request not encrypted
            [
                [
                    'HTTP_FORWARDED' => 'proto=http,proto=https',
                    'REMOTE_ADDR' => '192.169.0.1',
                ],
                false,
            ],
        ];
    }

    /**
     * @phpstan-return array<array{array<string, string>, bool}>
     */
    public static function isSecureServerWithoutTrustedHost(): array
    {
        return [
            // RFC 7239 forwarded header is not enabled
            [
                [
                    'HTTP_FORWARDED' => 'proto=https',
                    'REMOTE_ADDR' => '192.168.0.1',
                ],
                false,
            ],
        ];
    }

    /**
     * @phpstan-return array<array{string, string, string, string}>
     */
    public static function parseForwardedHeader(): array
    {
        return [
            [
                '192.168.10.10',
                'for=10.0.0.2;host=yiiframework.com;proto=https',
                'https://yiiframework.com',
                '10.0.0.2',
            ],
            [
                '192.168.10.10',
                'for=10.0.0.2;proto=https',
                'https://example.com',
                '10.0.0.2',
            ],
            [
                '192.168.10.10',
                'host=yiiframework.com;proto=https',
                'https://yiiframework.com',
                '192.168.10.10',
            ],
            [
                '192.168.10.10',
                'host=yiiframework.com;for=10.0.0.2',
                'http://yiiframework.com',
                '10.0.0.2',
            ],
            [
                '192.168.20.10',
                'host=yiiframework.com;for=10.0.0.2;proto=https',
                'https://yiiframework.com',
                '10.0.0.2',
            ],
            [
                '192.168.10.10',
                'for=10.0.0.1;host=yiiframework.com;proto=https, for=192.168.20.20;host=awesome.proxy.com;proto=http',
                'https://yiiframework.com',
                '10.0.0.1',
            ],
            [
                '192.168.10.10',
                'for=8.8.8.8;host=spoofed.host;proto=https, for=10.0.0.1;host=yiiframework.com;proto=https, for=192.168.20.20;host=trusted.proxy;proto=http',
                'https://yiiframework.com',
                '10.0.0.1',
            ],
        ];
    }

    /**
     * @phpstan-return array<array-key, array{int|string|null, string|null}>
     */
    public static function remoteHostCases(): array
    {
        return [
            'absent' => [
                null,
                null,
            ],
            'domain' => [
                'api.example-service.com',
                'api.example-service.com',
            ],
            'empty string' => [
                '',
                '',
            ],
            'numeric string' => [
                '123',
                '123',
            ],
            'string zero' => [
                '0',
                '0',
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
            'non-string' => [
                123,
                null,
            ],
        ];
    }

    /**
     * @phpstan-return array<array-key, array{mixed, string|null}>
     */
    public static function serverNameCases(): array
    {
        return [
            'absent' => [
                null,
                null,
            ],
            'boolean false' => [
                false,
                null,
            ],
            'boolean true' => [
                true,
                null,
            ],
            'empty array' => [
                [],
                null,
            ],
            'empty string' => [
                '',
                '',
            ],
            'float value' => [
                123.45,
                null,
            ],
            'integer value' => [
                12345,
                null,
            ],
            'IPv4 address' => [
                '192.168.1.100',
                '192.168.1.100',
            ],
            'localhost' => [
                'localhost',
                'localhost',
            ],
            'numeric string' => [
                '123',
                '123',
            ],
            'valid domain' => [
                'example.server.com',
                'example.server.com',
            ],
        ];
    }

    /**
     * @phpstan-return array<
     *     string,
     *     array{string, string, array<array-key, string>|null, array<array-key, string>, string}
     * >
     */
    public static function trustedHostAndInjectedXForwardedFor(): array
    {
        return [
            'emptyIPs' => [
                '1.1.1.1',
                '',
                null,
                ['10.10.10.10'],
                '1.1.1.1',
            ],
            'invalidIp' => [
                '1.1.1.1',
                '127.0.0.1, 8.8.8.8, 2.2.2.2, apple',
                null,
                ['10.10.10.10'],
                '1.1.1.1',
            ],
            'invalidIp2' => [
                '1.1.1.1',
                '127.0.0.1, 8.8.8.8, 2.2.2.2, 300.300.300.300',
                null,
                ['10.10.10.10'],
                '1.1.1.1',
            ],
            'invalidIp3' => [
                '1.1.1.1',
                '127.0.0.1, 8.8.8.8, 2.2.2.2, 10.0.0.0/26',
                null,
                ['10.0.0.0/24'],
                '1.1.1.1',
            ],
            'invalidLatestIp' => [
                '1.1.1.1',
                '127.0.0.1, 8.8.8.8, 2.2.2.2, apple, 2.2.2.2',
                null,
                [
                    '1.1.1.1',
                    '2.2.2.2',
                ],
                '2.2.2.2',
            ],
            'notTrusted' => [
                '1.1.1.1',
                '127.0.0.1, 8.8.8.8, 2.2.2.2',
                null,
                ['10.10.10.10'],
                '1.1.1.1',
            ],
            'trustedLevel1' => [
                '1.1.1.1', '127.0.0.1, 8.8.8.8, 2.2.2.2',
                null,
                ['1.1.1.1'],
                '2.2.2.2',
            ],
            'trustedLevel2' => [
                '1.1.1.1',
                '127.0.0.1, 8.8.8.8, 2.2.2.2',
                null,
                [
                    '1.1.1.1',
                    '2.2.2.2',
                ],
                '8.8.8.8',
            ],
            'trustedLevel3' => [
                '1.1.1.1',
                '127.0.0.1, 8.8.8.8, 2.2.2.2',
                null,
                [
                    '1.1.1.1',
                    '2.2.2.2',
                    '8.8.8.8',
                ],
                '127.0.0.1',
            ],
            'trustedLevel4' => [
                '1.1.1.1',
                '127.0.0.1, 8.8.8.8, 2.2.2.2',
                null,
                [
                    '1.1.1.1',
                    '2.2.2.2',
                    '8.8.8.8',
                    '127.0.0.1',
                ],
                '127.0.0.1',
            ],
            'trustedLevel4EmptyElements' => [
                '1.1.1.1',
                '127.0.0.1, 8.8.8.8,,,, ,   , 2.2.2.2',
                null,
                [
                    '1.1.1.1',
                    '2.2.2.2',
                    '8.8.8.8',
                    '127.0.0.1',
                ],
                '127.0.0.1',
            ],
            'trustedWithCidr' => [
                '10.0.0.2',
                '127.0.0.1, 8.8.8.8, 10.0.0.240, 10.0.0.32, 10.0.0.99',
                null,
                ['10.0.0.0/24'],
                '8.8.8.8',
            ],
            'trustedAll' => [
                '10.0.0.2',
                '127.0.0.1, 8.8.8.8, 10.0.0.240, 10.0.0.32, 10.0.0.99',
                null,
                ['0.0.0.0/0'],
                '127.0.0.1',
            ],
            'emptyIpHeaders' => [
                '1.1.1.1', '127.0.0.1, 8.8.8.8, 2.2.2.2',
                [],
                ['1.1.1.1'],
                '1.1.1.1',
            ],
        ];
    }

    /**
     * @phpstan-return array<string, array{string, int, int|null, array<array-key, string>|null, int}>
     */
    public static function trustedHostAndXForwardedPort(): array
    {
        return [
            'defaultPlain' => [
                '1.1.1.1',
                80,
                null,
                null,
                80,
            ],
            'defaultSSL' => [
                '1.1.1.1',
                443,
                null,
                null,
                443,
            ],
            'trustedForwardedPlain' => [
                '10.10.10.10',
                443,
                80,
                ['10.0.0.0/8'],
                80,
            ],
            'trustedForwardedSSL' => [
                '10.10.10.10',
                80,
                443,
                ['10.0.0.0/8'],
                443,
            ],
            'untrustedForwardedPlain' => [
                '1.1.1.1',
                443,
                80,
                ['10.0.0.0/8'],
                443,
            ],
            'untrustedForwardedSSL' => [
                '1.1.1.1',
                80,
                443,
                ['10.0.0.0/8'],
                80,
            ],
        ];
    }
}
