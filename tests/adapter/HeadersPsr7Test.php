<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\adapter;

use PHPUnit\Framework\Attributes\Group;
use yii2\extensions\psrbridge\http\Request;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

#[Group('adapter')]
final class HeadersPsr7Test extends TestCase
{
    #[Group('csrf-token')]
    public function testGetCsrfTokenFromHeaderUsesAdapterWhenAdapterIsNotNull(): void
    {
        $expectedToken = 'adapter-csrf-token-123';
        $csrfHeaderName = 'X-CSRF-Token';

        $request = new Request();

        $request->csrfHeader = $csrfHeaderName;

        $request->setPsr7Request(
            FactoryHelper::createRequest('POST', '/test', [$csrfHeaderName => $expectedToken]),
        );

        self::assertSame(
            $expectedToken,
            $request->getCsrfTokenFromHeader(),
            "Should return CSRF token from adapter headers when adapter is not 'null'",
        );
    }

    #[Group('content-type')]
    public function testReturnContentTypeFromPsr7RequestWhenHeaderIsPresent(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'text/plain';

        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'POST',
                '/api/upload',
                ['Content-Type' => 'multipart/form-data; boundary=----WebKitFormBoundary'],
            ),
        );

        self::assertSame(
            'multipart/form-data; boundary=----WebKitFormBoundary',
            $request->getContentType(),
            "'getContentType()' should return the 'Content-Type' header from the PSR-7 request when present, " .
            "overriding 'text/plain' from \$_SERVER['CONTENT_TYPE'].",
        );
    }

    #[Group('csrf-token')]
    public function testReturnCsrfTokenFromHeaderCaseInsensitive(): void
    {
        $csrfToken = 'case-insensitive-token';

        $request = new Request();

        $request->csrfHeader = 'X-CSRF-Token';

        $request->setPsr7Request(
            FactoryHelper::createRequest('POST', '/test', ['x-csrf-token' => $csrfToken]),
        );

        self::assertSame($csrfToken, $request->getCsrfTokenFromHeader());
    }

    #[Group('csrf-token')]
    public function testReturnCsrfTokenFromHeaderWhenAdapterIsSet(): void
    {
        $csrfToken = 'test-csrf-token-value';

        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('POST', '/test', ['X-CSRF-Token' => $csrfToken]),
        );

        self::assertSame(
            $csrfToken,
            $request->getCsrfTokenFromHeader(),
            "CSRF token from header should match the value provided in the PSR-7 request header 'X-CSRF-Token'.",
        );
    }

    #[Group('csrf-token')]
    public function testReturnCsrfTokenFromHeaderWithCustomHeaderWhenAdapterIsSet(): void
    {
        $customHeaderName = 'X-Custom-CSRF';
        $csrfToken = 'custom-csrf-token-value';

        $request = new Request();

        $request->csrfHeader = $customHeaderName;

        $request->setPsr7Request(
            FactoryHelper::createRequest('PUT', '/api/resource', [$customHeaderName => $csrfToken]),
        );

        self::assertSame(
            $csrfToken,
            $request->getCsrfTokenFromHeader(),
            'CSRF token from header should match the value provided in the custom PSR-7 request header.',
        );
    }

    #[Group('csrf-token')]
    public function testReturnEmptyStringFromHeaderWhenCsrfHeaderPresentButEmpty(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('PATCH', '/api/update', ['X-CSRF-Token' => '']),
        );

        self::assertSame(
            '',
            $request->getCsrfTokenFromHeader(),
            'CSRF token from header should return empty string when CSRF header is present but empty in the PSR-7 ' .
            'request.',
        );
    }

    #[Group('csrf-token')]
    public function testReturnNullFromHeaderWhenCsrfHeaderNotPresentAndAdapterIsSet(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('DELETE', '/api/resource'),
        );

        self::assertNull(
            $request->getCsrfTokenFromHeader(),
            "CSRF token from header should return 'null' when no CSRF header is present in the PSR-7 request.",
        );
    }

    #[Group('csrf-token')]
    public function testReturnParentCsrfTokenFromHeaderWhenAdapterIsNull(): void
    {
        $request = new Request();

        // ensure adapter is `null` (default state)
        $request->reset();

        self::assertNull(
            $request->getCsrfTokenFromHeader(),
            "CSRF token from header should return parent implementation result when adapter is 'null'.",
        );
    }

    #[Group('headers')]
    public function testSecureHeadersAreFilteredWhenNotFromTrustedHost(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';

        $request = new Request(
            [
                'trustedHosts' => [
                    '10.0.0.0/24',
                ],
                'secureHeaders' => [
                    'X-Forwarded-For',
                    'X-Forwarded-Proto',
                    'X-Forwarded-Host',
                    'X-Forwarded-Port',
                    'Front-End-Https',
                    'X-Real-IP',
                ],
            ],
        );

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'GET',
                '/test',
                [
                    'X-Forwarded-For' => '10.0.0.1',
                    'X-Forwarded-Proto' => 'https',
                    'X-Forwarded-Host' => 'malicious-host.com',
                    'X-Forwarded-Port' => '443',
                    'Front-End-Https' => 'on',
                    'X-Real-IP' => '8.8.8.8',
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer token123',
                    'User-Agent' => 'Test-Agent/1.0',
                ],
            ),
        );

        $headerCollection = $request->getHeaders();

        self::assertNull(
            $headerCollection->get('X-Forwarded-For'),
            "'X-Forwarded-For' header should be filtered out when request is not from 'trustedHosts'.",
        );
        self::assertNull(
            $headerCollection->get('X-Forwarded-Proto'),
            "'X-Forwarded-Proto' header should be filtered out when request is not from 'trustedHosts'.",
        );
        self::assertNull(
            $headerCollection->get('X-Forwarded-Host'),
            "'X-Forwarded-Host' header should be filtered out when request is not from 'trustedHosts'.",
        );
        self::assertNull(
            $headerCollection->get('X-Forwarded-Port'),
            "'X-Forwarded-Port' header should be filtered out when request is not from 'trustedHosts'.",
        );
        self::assertNull(
            $headerCollection->get('Front-End-Https'),
            "'Front-End-Https' header should be filtered out when request is not from 'trustedHosts'.",
        );
        self::assertNull(
            $headerCollection->get('X-Real-IP'),
            "'X-Real-IP' header should be filtered out when request is not from 'trustedHosts'.",
        );
        self::assertSame(
            'application/json',
            $headerCollection->get('Content-Type'),
            "'Content-Type' header should NOT be filtered as it is not a 'secureHeaders'.",
        );
        self::assertSame(
            'Bearer token123',
            $headerCollection->get('Authorization'),
            "'Authorization' header should NOT be filtered as it is not a 'secureHeaders'.",
        );
        self::assertSame(
            'Test-Agent/1.0',
            $headerCollection->get('User-Agent'),
            "'User-Agent' header should NOT be filtered as it is not a secure header.",
        );
    }
}
