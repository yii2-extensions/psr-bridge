<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\provider;

/**
 * Data provider for {@see \yii2\extensions\psrbridge\tests\http\RequestTest} and related HTTP request test classes.
 *
 * Supplies comprehensive test data for HTTP request scenarios, including headers, methods, query strings, URLs, user
 * IP resolution, authorization, secure server detection, and trusted proxy handling.
 *
 * Key features.
 * - Covers trusted/untrusted proxy scenarios, RFC 7239, and header injection.
 * - Enables robust testing of query string, URL, and authorization header parsing.
 * - Provides data for edge cases in HTTP request parsing and normalization.
 * - Supports validation of AJAX, PJAX, and secure server detection logic.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class RequestProvider
{
    /**
     * Provides test data for already resolved IP address scenarios.
     *
     * This provider supplies test cases for validating the resolution of client IP addresses when the 'X-Forwarded-For'
     * header is already resolved, covering both HTTP and HTTPS schemes.
     *
     * Each test case includes the resolved IP, the 'X-Forwarded-For' header value, the scheme, trusted hosts, and
     * expected results for IP and secure connection.
     *
     * @return array test data with resolved IP, 'X-Forwarded-For' header, scheme, trusted hosts, and expected checks.
     *
     * @phpstan-return array<string, array{string, string, string, array<string>, string, string, bool}>
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
     * Provides test data for HTTP request body parameter parsing.
     *
     * This provider supplies test cases for validating the extraction and parsing of body parameters from different
     * content types, including URL-encoded forms, JSON, and JSONP payloads.
     *
     * Each test case includes the content type, the raw body string, and the expected parsed result as an array.
     *
     * @return array test data with content type, raw body, and expected parsed parameters.
     *
     * @phpstan-return array<string, array{string, string, array<string, int|string>}>
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
     * Provides test data for HTTP host info extraction scenarios.
     *
     * This provider supplies test cases for validating the extraction of host information from various server parameter
     * combinations, including standard headers, missing headers, and trusted/untrusted proxy scenarios.
     *
     * Each test case includes the server parameters and the expected host info array with the full host info string
     * and the host name.
     *
     * @return array test data with server parameters and expected host info results.
     *
     * @phpstan-return array<int, array{array<string, mixed>|array<string, string>, array{string|null, string|null}}>
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
     * Provides test data for AJAX request detection logic.
     *
     * This provider supplies test cases for validating the detection of AJAX requests based on the
     * 'HTTP_X_REQUESTED_WITH' server parameter, covering both standard and missing header scenarios.
     *
     * Each test case includes the server parameters and the expected boolean result indicating whether the request
     * should be recognized as AJAX.
     *
     * @return array test data with server parameters and expected AJAX detection results.
     *
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
     * Provides test data for PJAX request detection logic.
     *
     * This provider supplies test cases for validating the detection of PJAX requests based on the
     * 'HTTP_X_REQUESTED_WITH' and 'HTTP_X_PJAX' server parameters, covering both standard and missing header
     * scenarios.
     *
     * Each test case includes the server parameters and the expected boolean result indicating whether the request
     * should be recognized as PJAX.
     *
     * @return array test data with server parameters and expected PJAX detection results.
     *
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
     * Provides test data for HTTP method override logic.
     *
     * This provider supplies test cases for validating the extraction of the HTTP method from server parameters,
     * including scenarios where the 'HTTP_X_HTTP_METHOD_OVERRIDE' header is present or absent.
     *
     * Each test case includes the server parameters array and the expected HTTP method string result.
     *
     * @return array test data with server parameters and expected HTTP method results.
     *
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
     * Provides test data for HTTP query string extraction and normalization.
     *
     * This provider supplies test cases for validating the handling and normalization of HTTP query strings, including
     * scenarios with complex queries, empty values, encoded parameters, multiple parameters, parameters without values,
     * and single parameters.
     *
     * Each test case consists of the input query string and the expected normalized query string result.
     *
     * @return array test data with input and expected normalized query strings.
     *
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
     * Provides test data for HTTP URL extraction and normalization scenarios.
     *
     * This provider supplies test cases for validating the handling and normalization of HTTP URLs, including scenarios
     * with complex query strings, root paths, and encoded parameters.
     *
     * Each test case consists of the input URL and the expected normalized URL result.
     *
     * @return array test data with input and expected normalized URLs.
     *
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
     * Provides test data for remote user IP address extraction.
     *
     * This provider supplies test cases for validating the extraction of the user's IP address from various server
     * parameter scenarios, including trusted and untrusted proxies, RFC 7239 Forwarded headers, IPv4 and IPv6 formats,
     * and optional port handling.
     *
     * Each test case consists of the server parameters and the expected resolved user IP address.
     *
     * @return array test data with server parameters and expected user IP addresses.
     *
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
            // RFC 7239 forwarded from trusted proxy with optional port
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
     * Provides test data for remote user IP extraction without trusted host configuration.
     *
     * This provider supplies test cases for validating the extraction of the user's IP address when trusted hosts are
     * not enabled, including scenarios where RFC 7239 Forwarded headers are present but ignored.
     *
     * Each test case consists of the server parameters and the expected resolved user IP address.
     *
     * @return array test data with server parameters and expected user IP addresses.
     *
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
     * Provides test data for HTTP Basic Authorization header parsing scenarios.
     *
     * This provider supplies test cases for validating the extraction of username and password credentials from HTTP
     * Basic Authorization headers, including malformed, partial, and edge case encodings.
     *
     * Each test case consists of the base64-encoded credentials string and the expected username and password values.
     *
     * @return array test data with base64-encoded credentials and expected username/password pairs.
     *
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
     * Provides test data for secure server connection detection logic.
     *
     * This provider supplies test cases for validating the detection of secure (HTTPS) connections based on various
     * server parameters and proxy headers, including trusted and untrusted proxy scenarios, as well as direct HTTPS
     * indicators.
     *
     * Each test case consists of the server parameters array and the expected boolean indicating whether the connection
     * should be considered secure.
     *
     * @return array test data with server parameters and expected secure connection boolean.
     *
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
     * Provides test data for secure server connection detection without trusted host configuration.
     *
     * This provider supplies test cases for validating the detection of secure (HTTPS) connections when trusted hosts
     * are not enabled, including scenarios where RFC 7239 Forwarded headers are present but ignored.
     *
     * Each test case consists of the server parameters and the expected boolean indicating whether the connection
     * should be considered secure.
     *
     * @return array test data with server parameters and expected secure connection boolean.
     *
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
     * Provides test data for parsing RFC 7239 Forwarded headers.
     *
     * This provider supplies test cases for validating the extraction of protocol, host, and user IP information from
     * various Forwarded header formats, including multiple proxies and mixed parameter orders.
     *
     * Each test case consists of the remote address, the Forwarded header string, the expected host info, and the
     * expected user IP after parsing.
     *
     * @return array test data with remote address, Forwarded header, expected host info, and expected user IP.
     *
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
     * Provides test data for trusted host and injected 'X-Forwarded-For' scenarios.
     *
     * This provider supplies test cases for validating the extraction and resolution of user IP addresses when trusted
     * hosts and various 'X-Forwarded-For' header values are present, including invalid, empty, and CIDR-based
     * configurations.
     *
     * Each test case includes the remote address, the 'X-Forwarded-For' header value, optional IP headers, the list of
     * trusted hosts, and the expected resolved user IP address.
     *
     * @return array test data with remote address, 'X-Forwarded-For', optional IP headers, trusted hosts, and expected
     * user IP.
     *
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
     * Provides test data for trusted host and 'X-Forwarded-Port' scenarios.
     *
     * This provider supplies test cases for validating the extraction and resolution of server port values when trusted
     * hosts and various 'X-Forwarded-Port' header values are present, including default, trusted, and untrusted
     * configurations for both plain and SSL connections.
     *
     * Each test case includes the remote address, the server port, the 'X-Forwarded-Port' value, the list of trusted
     * hosts, and the expected resolved port.
     *
     * @return array test data with remote address, server port, 'X-Forwarded-Port', trusted hosts, and expected port.
     *
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
