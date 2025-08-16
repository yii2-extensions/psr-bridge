<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\emitter;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group};
use PHPUnit\Framework\MockObject\{Exception, MockObject};
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\{ResponseInterface, StreamInterface};
use yii\base\InvalidArgumentException;
use yii2\extensions\psrbridge\emitter\SapiEmitter;
use yii2\extensions\psrbridge\exception\{HeadersAlreadySentException, Message, OutputAlreadySentException};
use yii2\extensions\psrbridge\tests\provider\EmitterProvider;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\support\stub\MockerFunctions;

use function fopen;
use function implode;
use function is_int;
use function ob_clean;
use function ob_end_clean;
use function ob_get_length;
use function ob_get_level;
use function ob_start;

/**
 * Test suite for {@see SapiEmitter} class functionality and behavior.
 *
 * Verifies the HTTP response emission capabilities through PHP's SAPI interface, ensuring correct response handling,
 * header management, and body streaming functionality.
 *
 * These tests ensure emission features work correctly under different conditions and maintain consistent behavior after
 * code changes.
 *
 * The tests validate proper HTTP response emission with various configurations for headers, body content, and buffer
 * management, which are essential for reliable HTTP communication in the framework.
 *
 * Test coverage.
 * - Buffer configuration (custom sizes, validation, zero/negative values).
 * - Content range processing (partial content, byte ranges, streaming).
 * - Error detection (headers already sent, output already sent, buffer validation).
 * - Header handling (normalization, multiple cookies, order preservation).
 * - Output buffering (flush control, buffer level validation, empty buffer handling).
 * - Protocol support (HTTP/1.1, HTTP/2 emission).
 * - Response body emission (full content, suppression, chunked reading).
 * - Status code handling (standard codes, custom reason phrases, no-body status codes).
 * - Stream handling (seekable streams, empty streams, non-readable streams).
 *
 * @see EmitterProvider for test case data providers.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('emitter')]
final class SapiEmitterTest extends TestCase
{
    public static function tearDownAfterClass(): void
    {
        MockerFunctions::reset();
    }

    public function setUp(): void
    {
        MockerFunctions::reset();
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     */
    public function testEmitResponseWithContentRangeAndSmallBuffer(): void
    {
        $response = FactoryHelper::createResponse(headers: ['Content-Range' => 'bytes 0-3/8'], body: 'Contents');

        (new SapiEmitter(1))->emit($response);

        self::assertSame(
            200,
            MockerFunctions::http_response_code(),
            "Status code should be '200' for content range response.",
        );
        self::assertCount(
            1,
            MockerFunctions::headers_list(),
            "Should have exactly one 'Content-Range' header.",
        );
        self::assertSame(
            ['Content-Range: bytes 0-3/8'],
            MockerFunctions::headers_list(),
            "'Content-Range' header should be set correctly.",
        );
        self::assertSame(
            'HTTP/1.1 200 OK',
            $this->httpResponseStatusLine($response),
            "Status line should match 'HTTP/1.1 200 OK' format.",
        );
        $this->expectOutputString(
            'Cont',
        );
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     */
    public function testEmitResponseWithContentRangeAndSuppressedBody(): void
    {
        $emitter = new SapiEmitter(1);
        $response = FactoryHelper::createResponse(headers: ['Content-Range' => 'bytes 0-3/8'], body: 'Contents');

        $emitter->emit($response, false);

        self::assertSame(
            200,
            MockerFunctions::http_response_code(),
            "Status code should match the specified code ('200').",
        );
        self::assertCount(
            1,
            MockerFunctions::headers_list(),
            "Should have exactly one 'Content-Range' header.",
        );
        self::assertSame(
            ['Content-Range: bytes 0-3/8'],
            MockerFunctions::headers_list(),
            "'Content-Range' header should be set correctly.",
        );
        self::assertSame(
            'HTTP/1.1 200 OK',
            $this->httpResponseStatusLine($response),
            "Status line should match 'HTTP/1.1 200 OK' format.",
        );
        $this->expectOutputString(
            '',
        );
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     */
    public function testEmitResponseWithCustomBufferLength(): void
    {
        $response = FactoryHelper::createResponse();

        (new SapiEmitter(8192))->emit($response);

        self::assertSame(
            200,
            MockerFunctions::http_response_code(),
            "Status code should be '200' for default response.",
        );
        self::assertCount(
            0,
            MockerFunctions::headers_list(),
            'No headers should be present.',
        );
        self::assertSame(
            [],
            MockerFunctions::headers_list(),
            'Headers list should be empty.',
        );
        self::assertSame(
            'HTTP/1.1 200 OK',
            $this->httpResponseStatusLine($response),
            "Status line should match 'HTTP/1.1 200 OK' format.",
        );
        $this->expectOutputString(
            '',
        );
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     */
    public function testEmitResponseWithCustomHeadersAndSmallBuffer(): void
    {
        $response = FactoryHelper::createResponse(404, ['X-Test' => 'test'], 'Page not found', '2');

        (new SapiEmitter(2))->emit($response);

        self::assertSame(
            404,
            MockerFunctions::http_response_code(),
            "Status code should match the specified code ('404').",
        );
        self::assertCount(
            1,
            MockerFunctions::headers_list(),
            'Should have exactly one header entry.',
        );
        self::assertSame(
            ['X-Test: test'],
            MockerFunctions::headers_list(),
            'Header should match the specified header.',
        );
        self::assertSame(
            'HTTP/2 404 Not Found',
            $this->httpResponseStatusLine($response),
            "Status line should match 'HTTP/2 404 Not Found' format.",
        );
        $this->expectOutputString(
            'Page not found',
        );
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     */
    #[DataProviderExternal(EmitterProvider::class, 'reasonPhrase')]
    public function testEmitResponseWithCustomReasonPhrase(
        int $code,
        string $reasonPhrase,
        string $expectedHeader,
    ): void {
        $response = FactoryHelper::createResponse($code);

        $response = $response->withStatus($code, $reasonPhrase);

        (new SapiEmitter())->emit($response);

        self::assertSame(
            $expectedHeader,
            $this->httpResponseStatusLine($response),
            'Status line should match expected format with custom reason phrase.',
        );
        self::assertSame(
            $code,
            MockerFunctions::http_response_code(),
            'Status code should match the specified code.',
        );
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     */
    public function testEmitResponseWithCustomStatusAndHeaders(): void
    {
        $response = FactoryHelper::createResponse($code = 404, ['X-Test' => 'test'], $contents = 'Page not found', '2');

        (new SapiEmitter())->emit($response);

        self::assertSame(
            $code,
            MockerFunctions::http_response_code(),
            "Status code should match the specified code ('404').",
        );
        self::assertCount(
            1,
            MockerFunctions::headers_list(),
            'Should have exactly one header entry.',
        );
        self::assertSame(
            ['X-Test: test'],
            MockerFunctions::headers_list(),
            'Header should match the specified header.',
        );
        self::assertSame(
            'HTTP/2 404 Not Found',
            $this->httpResponseStatusLine($response),
            "Status line should match 'HTTP/2 404 Not Found' format.",
        );
        $this->expectOutputString(
            $contents,
        );
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     */
    public function testEmitResponseWithDefaultBufferLength(): void
    {
        $response = FactoryHelper::createResponse();

        (new SapiEmitter())->emit($response);

        self::assertSame(
            200,
            MockerFunctions::http_response_code(),
            "Status code should be '200' for default response.",
        );
        self::assertCount(
            0,
            MockerFunctions::headers_list(),
            'No headers should be present.',
        );
        self::assertSame(
            [],
            MockerFunctions::headers_list(),
            'Headers list should be empty.',
        );
        self::assertSame(
            'HTTP/1.1 200 OK',
            $this->httpResponseStatusLine($response),
            "Status line should match 'HTTP/1.1 200 OK' format.",
        );
        $this->expectOutputString(
            '',
        );
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     */
    public function testEmitResponseWithEmptyContent(): void
    {
        $response = FactoryHelper::createResponse(headers: ['Content-Range' => 'bytes 0-3/8']);

        (new SapiEmitter(8192))->emit($response);

        self::assertSame(
            200,
            MockerFunctions::http_response_code(),
            "Status code should be '200'.",
        );
        self::assertCount(
            1,
            MockerFunctions::headers_list(),
            'Should have exactly one header.',
        );
        self::assertSame(
            ['Content-Range: bytes 0-3/8'],
            MockerFunctions::headers_list(),
            'Content-Range header should be set correctly.',
        );
        $this->expectOutputString(
            '',
        );
    }

    /**
     * @throws Exception if an error occurs while creating the mock object.
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     */
    public function testEmitResponseWithEmptySeekableStream(): void
    {
        /** @var MockObject&StreamInterface $stream */
        $stream = $this->createMock(StreamInterface::class);

        $stream->method('isSeekable')->willReturn(true);
        $stream->method('eof')->willReturn(false, true);
        $stream->method('read')->willReturn('');
        $stream->method('isReadable')->willReturn(true);
        $stream->expects(self::once())->method('seek')->with(0);

        $response = FactoryHelper::createResponse(headers: ['Content-Range' => 'bytes 0-3/8'], body: $stream);

        (new SapiEmitter(8192))->emit($response);

        $this->expectOutputString('');
        self::assertSame(1, MockerFunctions::getFlushTimes(), 'Stream should be flushed exactly once.');
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     */
    public function testEmitResponseWithMultipleHeadersAndSetCookiesPreserved(): void
    {
        $response = FactoryHelper::createResponse(headers: ['X-Test' => ['test-1']], body: 'Contents');

        $response = $response
            ->withAddedHeader('Set-Cookie', 'key-1=value-1')
            ->withAddedHeader('Set-Cookie', 'key-2=value-2');

        (new SapiEmitter())->emit($response);

        self::assertSame(
            200,
            MockerFunctions::http_response_code(),
            "Status code should be '200' when adding multiple headers.",
        );
        self::assertSame(
            [
                'X-Test: test-1',
                'Set-Cookie: key-1=value-1',
                'Set-Cookie: key-2=value-2',
            ],
            MockerFunctions::headers_list(),
            "Multiple 'Set-Cookie' headers should be preserved as separate headers without overwriting.",
        );
        self::assertSame(
            'HTTP/1.1 200 OK',
            $this->httpResponseStatusLine($response),
            "Status line should match 'HTTP/1.1' format with proper status code and phrase.",
        );
        $this->expectOutputString(
            'Contents',
        );
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     */
    public function testEmitResponseWithMultipleHeaderTypes(): void
    {
        $response = FactoryHelper::createResponse(headers: ['Content-Type' => ['text/plain']]);

        $response = $response
            ->withAddedHeader('Set-Cookie', 'key-1=value-1')
            ->withAddedHeader('X-Custom', 'value1')
            ->withAddedHeader('X-Custom', 'value2');

        (new SapiEmitter())->emit($response);

        self::assertSame(
            [
                'Content-Type: text/plain',
                'Set-Cookie: key-1=value-1',
                'X-Custom: value1, value2',
            ],
            MockerFunctions::headers_list(),
            'Headers should be correctly formatted with multiple types and values.',
        );
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     */
    public function testEmitResponseWithMultipleSetCookieHeaders(): void
    {
        $response = FactoryHelper::createResponse(200, ['Set-Cookie' => ['cookie1=value1', 'cookie2=value2']]);

        (new SapiEmitter())->emit($response);

        self::assertSame(
            [
                'Set-Cookie: cookie1=value1',
                'Set-Cookie: cookie2=value2',
            ],
            MockerFunctions::headers_list(),
            'Multiple Set-Cookie headers should be preserved separately.',
        );
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     */
    #[DataProviderExternal(EmitterProvider::class, 'noBodyStatusCodes')]
    public function testEmitResponseWithNoBodyStatusCodes(int $code, string $phrase): void
    {
        $response = FactoryHelper::createResponse(
            $code,
            ['Content-Type' => ['text/plain']],
            'This content should not be emitted',
        );

        $response = $response->withStatus($code, $phrase);

        (new SapiEmitter())->emit($response);

        self::assertSame(
            $code,
            MockerFunctions::http_response_code(),
            "Status code should be '{$code}' for no-body HTTP responses.",
        );
        self::assertSame(
            ['Content-Type: text/plain'],
            MockerFunctions::headers_list(),
            'Headers should be preserved even when body is suppressed.',
        );
        self::assertSame(
            "HTTP/1.1 {$code} {$phrase}",
            $this->httpResponseStatusLine($response),
            "Status line should match 'HTTP/1.1 {$code} {$phrase}' for no-body HTTP response.",
        );
        $this->expectOutputString(
            '',
        );
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     */
    public function testEmitResponseWithNonReadableStream(): void
    {
        $fopen = fopen('php://output', 'cb');

        $response = FactoryHelper::createResponse(body: $fopen === false ? null : $fopen);

        self::assertSame(
            'php://output',
            $response->getBody()->getMetadata('uri'),
            "Stream URI should be 'php://output'.",
        );
        self::assertFalse(
            $response->getBody()->isReadable(),
            'Stream should not be readable.',
        );

        (new SapiEmitter())->emit($response);

        $this->expectOutputString(
            '',
        );

        (new SapiEmitter(8192))->emit($response);

        $this->expectOutputString(
            '',
        );

        $response = $response->withHeader('Content-Range', 'bytes 0-3/8');

        (new SapiEmitter(8192))->emit($response);

        self::assertSame(
            ['Content-Range: bytes 0-3/8'],
            MockerFunctions::headers_list(),
            "'Content-Range' header should be set.",
        );
        $this->expectOutputString(
            '',
        );
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     */
    public function testEmitResponseWithNormalizedHeaderNames(): void
    {
        $response = FactoryHelper::createResponse(headers: ['CONTENT-Type' => 'text/plain', 'X-Custom-HEADER' => 'value']);

        (new SapiEmitter())->emit($response);

        self::assertSame(
            [
                'Content-Type: text/plain',
                'X-Custom-Header: value',
            ],
            MockerFunctions::headers_list(),
            'Headers should be normalized to canonical case format.',
        );
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     */
    public function testEmitResponseWithoutHeaders(): void
    {
        $response = FactoryHelper::createResponse();

        (new SapiEmitter())->emit($response);

        self::assertSame(
            200,
            MockerFunctions::http_response_code(),
            "Status code should be '200' for default response.",
        );
        self::assertCount(
            0,
            MockerFunctions::headers_list(),
            'No headers should be sent for a response without headers.',
        );
        self::assertSame(
            [],
            MockerFunctions::headers_list(),
            'Headers list should be empty for response without headers.',
        );
        self::assertSame(
            'HTTP/1.1 200 OK',
            $this->httpResponseStatusLine($response),
            "Status line should match 'HTTP/1.1 200 OK' format.",
        );
        $this->expectOutputString(
            '',
        );
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     */
    public function testEmitResponseWithSuppressedBody(): void
    {
        $response = FactoryHelper::createResponse($code = 404, ['X-Test' => 'test'], 'Page not found', '2');

        (new SapiEmitter())->emit($response, false);

        self::assertSame(
            $code,
            MockerFunctions::http_response_code(),
            "Status code should match the specified code ('404').",
        );
        self::assertCount(
            1,
            MockerFunctions::headers_list(),
            'Should have exactly one header entry.',
        );
        self::assertSame(
            ['X-Test: test'],
            MockerFunctions::headers_list(),
            'Header should match the specified header.',
        );
        self::assertSame(
            'HTTP/2 404 Not Found',
            $this->httpResponseStatusLine($response),
            "Status line should match 'HTTP/2 404 Not Found' format.",
        );
        $this->expectOutputString(
            '',
        );
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     *
     * @phpstan-param string[] $expected
     */
    #[DataProviderExternal(EmitterProvider::class, 'body')]
    public function testEmitResponseWithVariousBodyContents(
        string $contents,
        array $expected,
        int|null $buffer,
        int|null $first,
        int|null $last,
    ): void {
        $isContentRange = (is_int($first) && is_int($last));
        $outputString = $isContentRange ? implode('', $expected) : $contents;
        $headers = $isContentRange ? ['Content-Range' => "bytes $first-$last/*"] : [];
        $expectedHeaders = $isContentRange ? ["Content-Range: bytes $first-$last/*"] : [];

        $response = FactoryHelper::createResponse(200, $headers, $contents === '' ? null : $contents);

        (new SapiEmitter($buffer))->emit($response);

        self::assertSame(
            200,
            MockerFunctions::http_response_code(),
            "Status code should be '200'.",
        );
        self::assertCount(
            count($expectedHeaders),
            MockerFunctions::headers_list(),
            'Number of headers should match expected count.',
        );
        self::assertSame(
            $expectedHeaders,
            MockerFunctions::headers_list(),
            'Headers should match expected values.',
        );
        $this->expectOutputString(
            $outputString,
        );
    }

    public function testThrowExceptionWhenBufferLengthIsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Message::BUFFER_LENGTH_INVALID->getMessage(SapiEmitter::class, -1));

        new SapiEmitter(-1);
    }

    public function testThrowExceptionWhenBufferLengthIsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Message::BUFFER_LENGTH_INVALID->getMessage(SapiEmitter::class, 0));

        new SapiEmitter(0);
    }

    /**
     * @throws OutputAlreadySentException if response output has already been emitted.
     */
    public function testThrowExceptionWhenHeadersAlreadySent(): void
    {
        MockerFunctions::set_headers_sent(true, 'file', 123);

        $this->expectException(HeadersAlreadySentException::class);
        $this->expectExceptionMessage(Message::UNABLE_TO_EMIT_RESPONSE_HEADERS_ALREADY_SENT->getMessage());

        (new SapiEmitter())->emit(FactoryHelper::createResponse());
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     */
    public function testThrowExceptionWhenOutputAlreadySent(): void
    {
        $fopen = fopen('php://output', 'cb');

        $response = FactoryHelper::createResponse(body: $fopen === false ? null : $fopen);

        $response->getBody()->write('Contents');

        $this->expectOutputString('Contents');
        $this->expectException(OutputAlreadySentException::class);
        $this->expectExceptionMessage(Message::UNABLE_TO_EMIT_OUTPUT_HAS_BEEN_EMITTED->getMessage());

        (new SapiEmitter())->emit($response);
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     */
    public function testValidateOutputWithNonEmptyBuffer(): void
    {
        ob_start();
        echo 'buffer content';

        self::assertSame(2, ob_get_level());
        self::assertGreaterThan(0, ob_get_length());
        $this->expectException(OutputAlreadySentException::class);

        $emitter = new SapiEmitter();
        $response = FactoryHelper::createResponse();

        try {
            $emitter->emit($response);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     */
    public function testValidateOutputWithZeroBufferLevel(): void
    {
        $this->expectNotToPerformAssertions();

        ob_start();
        ob_clean();

        $emitter = new SapiEmitter();
        $response = FactoryHelper::createResponse();

        $emitter->emit($response);

        ob_end_clean();
    }

    /**
     * Generate the HTTP response status line based on the provided response.
     */
    private function httpResponseStatusLine(ResponseInterface $response): string
    {
        return match ($response->getReasonPhrase() !== '') {
            true => "HTTP/{$response->getProtocolVersion()} {$response->getStatusCode()} {$response->getReasonPhrase()}",
            default => "HTTP/{$response->getProtocolVersion()} {$response->getStatusCode()}",
        };
    }
}
