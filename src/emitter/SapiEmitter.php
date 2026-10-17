<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\emitter;

use Psr\Http\Message\ResponseInterface;
use yii\base\InvalidArgumentException;
use yii2\extensions\psrbridge\exception\{HeadersAlreadySentException, Message, OutputAlreadySentException};

use function array_map;
use function implode;
use function sprintf;
use function str_replace;
use function strtolower;
use function ucwords;

/**
 * Emits PSR-7 responses through PHP SAPI.
 */
final class SapiEmitter
{
    /**
     * Configures the maximum bytes read in each body-emission chunk.
     *
     * @param int $bufferLength Positive chunk size in bytes; defaults to 8192.
     *
     * @throws InvalidArgumentException when the buffer length is less than 1.
     */
    public function __construct(private readonly int $bufferLength = 8192)
    {
        if ($bufferLength < 1) {
            throw new InvalidArgumentException(
                Message::BUFFER_LENGTH_INVALID->getMessage(self::class, $bufferLength),
            );
        }
    }

    /**
     * Emits status, headers, and optionally a readable body through PHP SAPI.
     *
     * Suppresses the body for statuses recognized by {@see HttpNoBodyStatus}. The caller must pass `false` for
     * HEAD because this method has no originating request. Reads are bounded by the configured chunk size;
     * after-send lifecycle processing remains the caller's responsibility.
     *
     * @param ResponseInterface $response Response to emit.
     * @param bool $body Whether to emit the body when allowed by status code.
     *
     * @throws HeadersAlreadySentException when PHP has already sent headers.
     * @throws OutputAlreadySentException when the current output buffer contains bytes.
     * @throws \RuntimeException when reading or positioning the body stream fails.
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
     * Reads the prepared PSR-7 body in bounded chunks without applying Content-Range offsets again.
     *
     * @param ResponseInterface $response Response whose body is emitted.
     */
    private function emitBody(ResponseInterface $response): void
    {
        flush();
        $body = $response->getBody();
        if ($body->isSeekable() === true) {
            $body->rewind();
        }

        while ($body->eof() === false) {
            echo $body->read($this->bufferLength);
        }
    }

    /**
     * Emit the response headers.
     *
     * Iterates through the response headers and emits each one. Special handling is provided for the Set-Cookie header
     * to ensure multiple cookies are handled correctly.
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
