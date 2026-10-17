<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use HttpSoft\Message\ServerRequest;
use PHPUnit\Framework\Attributes\TestWith;
use yii2\extensions\psrbridge\http\Request;
use yii2\extensions\psrbridge\tests\support\TestCase;

/**
 * Checks transport inputs and mutable request overrides on the effective PSR request.
 */
final class RequestBoundaryTest extends TestCase
{
    #[TestWith(['https', null, true])]
    #[TestWith(['http', null, false])]
    #[TestWith(['http', 1, true])]
    #[TestWith(['http', 2, false])]
    #[TestWith(['http', '1', true])]
    #[TestWith(['http', 'on', true])]
    #[TestWith(['http', 'ON', true])]
    #[TestWith(['http', 'off', false])]
    public function testDirectTransportUsesCurrentInputs(string $scheme, mixed $https, bool $expected): void
    {
        $request = new Request();

        $request->setPsr7Request(new ServerRequest(serverParams: ['HTTPS' => $https], uri: $scheme . '://example.test/'));

        self::assertSame(
            $expected,
            $request->getIsSecureConnection(),
            'Secure connection state must reflect current transport inputs.',
        );
    }

    public function testExplicitScriptUrlCanBeResetAfterBinding(): void
    {
        $request = new Request(['scriptFile' => '/tmp/index.php']);

        $request->setPsr7Request(
            new ServerRequest(
                serverParams: [
                    'SCRIPT_NAME' => '/auto.php',
                    'SCRIPT_FILENAME' => '/tmp/index.php',
                ],
                uri: 'http://example.test/auto.php',
            ),
        );

        $request->setScriptUrl('/custom.php');

        self::assertSame(
            '/custom.php',
            $request->getScriptUrl(),
            'Explicit script URL must be returned.',
        );

        $request->setScriptUrl(null);

        self::assertSame(
            '/auto.php',
            $request->getScriptUrl(),
            'Reset script URL must use the detected value.',
        );
    }

    #[TestWith([true, true])]
    #[TestWith([false, false])]
    public function testForwardedProtocolRequiresAnExplicitlyTrustedProxy(bool $trusted, bool $expected): void
    {
        $request = new Request(['trustedHosts' => $trusted ? ['10.0.0.0/8'] : []]);

        $request->setPsr7Request(
            new ServerRequest(
                serverParams: ['REMOTE_ADDR' => '10.0.0.2'],
                headers: ['X-Forwarded-Proto' => 'https'],
                uri: 'http://example.test/',
            ),
        );

        self::assertSame(
            $expected,
            $request->getIsSecureConnection(),
            'Forwarded protocol must be trusted only from configured proxies.',
        );
    }

    #[TestWith([true, 'http', false])]
    #[TestWith([true, 'https', true])]
    #[TestWith([true, 'HTTPS', true])]
    #[TestWith([false, 'https', false])]
    public function testForwardedProtoUsesOnlyTrustedProxyValues(bool $trusted, string $proto, bool $expected): void
    {
        $request = new Request(
            [
                'trustedHosts' => $trusted ? ['10.0.0.0/8'] : [],
                'secureHeaders' => ['Forwarded'],
            ],
        );

        $request->setPsr7Request(
            new ServerRequest(
                serverParams: ['REMOTE_ADDR' => '10.0.0.2'],
                headers: ['Forwarded' => 'for=198.51.100.42;proto=' . $proto],
                uri: 'http://example.test/',
            ),
        );

        self::assertSame(
            $expected,
            $request->getIsSecureConnection(),
            'Forwarded proto must obey proxy trust.',
        );
    }

    public function testMethodOverridesUseMutableHeadersAndBodyWithExistingPrecedence(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            new ServerRequest(
                headers: ['X-Http-Method-Override' => 'DELETE'],
                method: 'POST',
                uri: 'http://example.test/'
            ),
        );

        self::assertSame(
            'DELETE',
            $request->getMethod(),
            'Header override must take precedence.',
        );

        $request->headers->remove('X-Http-Method-Override');

        self::assertSame(
            'POST',
            $request->getMethod(),
            'Removing the header override must restore the request method.',
        );

        $request->headers->set('X-Http-Method-Override', 'PATCH');

        self::assertSame(
            'PATCH',
            $request->getMethod(),
            'Updated header override must take effect.',
        );

        $request->setBodyParams(['_method' => 'PUT']);

        self::assertSame(
            'PUT',
            $request->getMethod(),
            'Body override must take precedence over the header override.',
        );

        $request->setBodyParams(['_method' => 'GET']);

        self::assertSame(
            'PATCH',
            $request->getMethod(),
            'Rejected GET body override must fall back to the header override.',
        );

        $request->headers->set('X-Http-Method-Override', 'OPTIONS');

        self::assertSame(
            'POST',
            $request->getMethod(),
            'Unsupported header override must leave the request method unchanged.',
        );
    }

    public function testMethodResolutionDoesNotReadAnUnparsedBodyAgain(): void
    {
        $body = $this->createMock(\Psr\Http\Message\StreamInterface::class);

        $body
            ->expects(self::never())
            ->method('__toString');

        $request = new Request();

        $request->setPsr7Request(
            new ServerRequest(
                headers: ['Content-Type' => 'application/json'],
                body: $body,
                method: 'POST',
                uri: 'http://example.test/',
            ),
        );

        self::assertSame(
            'POST',
            $request->getMethod(),
            'Unparsed body must not override the request method.',
        );

        $request->headers->set('X-Http-Method-Override', 'PATCH');

        self::assertSame(
            'PATCH',
            $request->getMethod(),
            'Header override must work without reading the body.',
        );
    }

    public function testMutableBodyOverrideReplacesAnExistingParsedOverride(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            new ServerRequest(
                parsedBody: ['_method' => 'DELETE'],
                method: 'POST',
                uri: 'http://example.test/',
            ),
        );

        self::assertSame(
            'DELETE',
            $request->getMethod(),
            'Parsed body override must be recognized.',
        );

        $request->setBodyParams(['_method' => 'PUT']);

        self::assertSame(
            'PUT',
            $request->getMethod(),
            'Mutable body override must replace the parsed override.',
        );

        $request->setBodyParams([]);

        self::assertSame(
            'POST',
            $request->getMethod(),
            'Empty body override must restore the request method.',
        );

        $request->setBodyParams(null);

        self::assertSame(
            'DELETE',
            $request->getMethod(),
            'Null body override must restore the parsed override.',
        );
    }

    public function testRemoteIpRejectsStringableObjectsAndNumericPortsBecomeIntegers(): void
    {
        $address = new class {
            public function __toString(): string
            {
                return '127.0.0.1';
            }
        };

        $request = new Request();

        $request->setPsr7Request(
            new ServerRequest(
                serverParams: ['REMOTE_ADDR' => $address, 'SERVER_PORT' => '8080'],
            ),
        );

        self::assertNull(
            $request->getRemoteIP(),
            'Stringable remote addresses must be rejected.',
        );
        self::assertSame(
            8080,
            $request->getServerPort(),
            'Numeric server port must be returned as an integer.',
        );
    }

    #[TestWith(['scalar'])]
    #[TestWith([42])]
    #[TestWith([false])]
    public function testScalarBodyOverridesBecomeAnEmptyArray(mixed $value): void
    {
        $request = new Request();

        $request->setPsr7Request(new ServerRequest());
        $request->setBodyParams($value);

        self::assertSame(
            [],
            $request->getBodyParams(),
            'Scalar body override must become an empty array.',
        );
    }

    public function testUrlencodedFallbackRespectsConfiguredMultibyteDecoding(): void
    {
        $encoding = mb_internal_encoding();
        try {
            mb_internal_encoding('ISO-8859-1');

            $request = new Request();

            $request->setPsr7Request(
                new ServerRequest(
                    headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
                    body: (new \HttpSoft\Message\StreamFactory())->createStream('name=%C3%A9'),
                    method: 'PUT',
                    uri: 'http://example.test/',
                ),
            );

            self::assertSame(
                ['name' => "\xE9"],
                $request->getBodyParams(),
                'Form values must use the configured multibyte encoding.',
            );
        } finally {
            mb_internal_encoding($encoding);
        }
    }
}
