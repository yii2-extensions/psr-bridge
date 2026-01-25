<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\emitter;

use Psr\Http\Message\{ResponseInterface, StreamInterface};
use yii\base\InvalidArgumentException;
use yii2\extensions\psrbridge\exception\{HeadersAlreadySentException, Message, OutputAlreadySentException};

use function array_map;
use function implode;
use function sprintf;
use function str_replace;
use function strlen;
use function strtolower;
use function ucwords;

/**
 * SAPI (Server API) ResponseInterface Emitter.
 *
 * This class is responsible for emitting PSR-7 ResponseInterface objects to the output buffer using PHP's Server API.
 *
 * It handles the emission of headers, status line, and response body while supporting features like content range and
 * buffered output.
 *
 * According to 'HTTP/1.1' specifications, certain status codes MUST NOT include a message body:
 * - '1xx' (Informational): '100' Continue, '101' Switching Protocols, '102' Processing, '103' Early Hints.
 * - '204' No Content, '205' Reset Content.
 * - '304' Not Modified.
 *
 * When these status codes are used, the emitter will not emit anybody's content regardless of whether one is present
 * in the response object.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class SapiEmitter
{
    /**
     * Creates a new instance of the {@see SapiEmitter} class.
     *
     * @param int|null $bufferLength Length of the buffer to use when emitting the response body (default: `null`).
     * - If `null`, the response body will be emitted all at once.
     *
     * @throws InvalidArgumentException if one or more arguments are invalid, of incorrect type or format.
     */
    public function __construct(private readonly int|null $bufferLength = null)
    {
        if ($bufferLength !== null && $bufferLength < 1) {
            throw new InvalidArgumentException(
                Message::BUFFER_LENGTH_INVALID->getMessage(self::class, $bufferLength),
            );
        }
    }

    /**
     * Emit a response to the PHP output buffer.
     *
     * This method will emit the response headers and body to the PHP output buffer. If headers have already been sent
     * or if content has already been emitted, an exception will be raised.
     *
     * Note: According to 'HTTP/1.1' specifications, responses with certain status codes
     * ('100' - '103', '204', '205', '304') MUSTN'T include a message body. For these status codes, the body will not
     * be emitted even if present in the response object. {@see HttpNoBodyStatus} for the complete list.
     *
     * @param ResponseInterface $response PSR-7 ResponseInterface instance.
     * @param bool $body Whether to emit the response with body (default: `true`).
     *
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     *
     * ```php
     * $emitter = new SapiEmitter();
     * $response = new Response();
     * $emitter->emit($response);
     * ```
     */
    public function emit(ResponseInterface $response, bool $body = true): void
    {
        $this->validateOutput();
        $this->emitHeaders($response);
        $this->emitStatusLine($response);

        if (
            $body === true
            && HttpNoBodyStatus::shouldHaveNoBody($response->getStatusCode()) === false
            && $response->getBody()->isReadable() === true
        ) {
            $this->emitBody($response);
        }
    }

    /**
     * Emit the response body.
     *
     * Handles the emission of the response body, supporting both buffered and unbuffered output, also supports content
     * range responses for partial content delivery.
     *
     * @param ResponseInterface $response PSR-7 ResponseInterface instance.
     */
    private function emitBody(ResponseInterface $response): void
    {
        if ($this->bufferLength === null) {
            echo $response->getBody();

            return;
        }

        flush();
        $body = $response->getBody();
        $range = ContentRange::fromHeader($response->getHeaderLine('Content-Range'));

        if ($range?->unit === ContentRangeUnit::BYTES) {
            $this->emitBodyRange($body, $range->first, $range->last);

            return;
        }

        if ($body->isSeekable() === true) {
            $body->rewind();
        }

        while ($body->eof() === false) {
            echo $body->read($this->bufferLength);
        }
    }

    /**
     * Emit a range of the response body.
     *
     * Used for partial content responses, this method emits only the requested range of the response body.
     *
     * @param StreamInterface $body Response body stream.
     * @param int $first Starting byte position.
     * @param int $last Ending byte position.
     */
    private function emitBodyRange(StreamInterface $body, int $first, int $last): void
    {
        $length = $last - $first + 1;

        if ($body->isSeekable() === true) {
            $body->seek($first);
        }

        while ($body->eof() === false) {
            $readLength = min($this->bufferLength, $length);

            if ($readLength <= 0) {
                return;
            }

            $contents = $body->read($readLength);
            $contentLength = strlen($contents);

            $length -= $contentLength;

            echo $contents;
        }
    }

    /**
     * Emit the response headers.
     *
     * Iterates through the response headers and emits each one. Special handling is provided for the 'Set-Cookie'
     * header to ensure multiple cookies are handled correctly.
     *
     * @param ResponseInterface $response PSR-7 ResponseInterface instance.
     */
    private function emitHeaders(ResponseInterface $response): void
    {
        foreach ($response->getHeaders() as $name => $values) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('-', ' ', (string) $name))));

            match ($name) {
                'Set-Cookie' => array_map(
                    static fn(string $value) => header("$name: $value", false),
                    $values,
                ),
                default => header("$name: " . implode(', ', $values)),
            };
        }
    }

    /**
     * Emit the response status line.
     *
     * Emits the HTTP protocol version, status code, and reason phrase.
     *
     * @param ResponseInterface $response PSR-7 ResponseInterface instance.
     */
    private function emitStatusLine(ResponseInterface $response): void
    {
        $reasonPhrase = $response->getReasonPhrase();
        $statusCode = $response->getStatusCode();

        header(
            sprintf(
                'HTTP/%s %s',
                $response->getProtocolVersion(),
                "{$statusCode} {$reasonPhrase}",
            ),
            true,
            $statusCode,
        );
    }

    /**
     * Validate the output status.
     *
     * Ensures that headers haven't been sent and that there is no content in the output buffer before attempting to
     * emit the response.
     *
     * @throws HeadersAlreadySentException if HTTP headers have already been sent to the client.
     * @throws OutputAlreadySentException if response output has already been emitted.
     *
     * @codeCoverageIgnore
     */
    private function validateOutput(): void
    {
        if (headers_sent() === true) {
            throw new HeadersAlreadySentException(
                Message::UNABLE_TO_EMIT_RESPONSE_HEADERS_ALREADY_SENT->getMessage(),
            );
        }

        if (ob_get_level() > 0 && ob_get_length() > 0) {
            throw new OutputAlreadySentException(
                Message::UNABLE_TO_EMIT_OUTPUT_HAS_BEEN_EMITTED->getMessage(),
            );
        }
    }
}
