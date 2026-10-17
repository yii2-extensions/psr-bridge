<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\adapter;

use DateTimeInterface;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, StreamFactoryInterface, StreamInterface};
use Throwable;
use yii\base\{InvalidConfigException, Security};
use yii\web\Cookie;
use yii2\extensions\psrbridge\exception\Message;
use yii2\extensions\psrbridge\http\{Request, Response};

use function count;
use function filter_var;
use function gmdate;
use function is_array;
use function is_int;
use function is_numeric;
use function is_resource;
use function is_string;
use function ltrim;
use function max;
use function preg_match;
use function serialize;
use function strtotime;
use function time;
use function urlencode;

use const FILTER_VALIDATE_INT;
use const PHP_INT_MAX;

/**
 * Adapts Yii responses to PSR-7 responses.
 *
 * `ResponseInterface` PSR-7 response contract.
 */
final class ResponseAdapter
{
    /**
     * Creates a new instance of the {@see ResponseAdapter} class.
     *
     * @param Response $psrResponse Yii Response instance to adapt.
     * @param ResponseFactoryInterface $responseFactory PSR-7 ResponseFactoryInterface instance for response creation.
     * @param StreamFactoryInterface $streamFactory PSR-7 StreamFactoryInterface instance for body stream creation.
     * @param Security $security Security component used to sign outgoing cookie values.
     */
    public function __construct(
        private readonly Response $psrResponse,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly Security $security,
    ) {}

    /**
     * Converts the Yii Response instance to a PSR-7 ResponseInterface.
     *
     * Creates a new PSR-7 ResponseInterface using the configured response and stream factories, copying status code,
     * status text, headers, cookies, and body content from the Yii Response component.
     * - All headers are transferred to the PSR-7 ResponseInterface, with multiple values preserved.
     * - Cookies are formatted and added as separate 'Set-Cookie' headers.
     * - File streaming is supported with HTTP range handling for efficient large file downloads.
     * - Response body is created from the Yii Response content or stream using the PSR-7 StreamFactoryInterface.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return ResponseInterface PSR-7 ResponseInterface instance with headers, cookies, and body content.
     */
    public function toPsr7(): ResponseInterface
    {
        // create base response
        $psr7Response = $this->responseFactory->createResponse(
            $this->psrResponse->getStatusCode(),
            $this->psrResponse->statusText,
        );

        /** @var array<string, string[]> $headers */
        $headers = $this->psrResponse->getHeaders()->toArray();

        // add headers
        foreach ($headers as $name => $values) {
            $psr7Response = $psr7Response->withHeader($name, $values);
        }

        // add cookies with proper formatting
        foreach ($this->buildCookieHeaders() as $cookieHeader) {
            $psr7Response = $psr7Response->withAddedHeader('Set-Cookie', $cookieHeader);
        }

        // create body stream from response content or stream
        $body = $this->createBodyStream();

        return $psr7Response->withBody($body);
    }

    /**
     * Formats non-`null` cookie values as separate Set-Cookie headers, including empty or expired values.
     *
     * @throws InvalidConfigException when cookie signing is enabled without a key.
     *
     * @return list<string> Formatted cookie headers.
     */
    private function buildCookieHeaders(): array
    {
        $headers = [];

        if ($this->psrResponse->enableCookieValidation && $this->psrResponse->cookieValidationKey === '') {
            throw new InvalidConfigException(
                Message::COOKIE_VALIDATION_KEY_NOT_CONFIGURED->getMessage(Request::class),
            );
        }

        foreach ($this->psrResponse->getCookies() as $cookie) {
            if ($cookie->value !== null) {
                $headers[] = $this->formatCookieHeader($cookie);
            }
        }

        return $headers;
    }

    /**
     * Creates a PSR-7 body stream from the Yii Response content or file stream.
     *
     * Handles both regular content and file streaming scenarios.
     * - If Yii Response component contains a file stream, this method delegates to {@see createStreamFromFileHandle()}
     *   to generate a stream for the specified file range.
     * - Otherwise, it creates a stream from the response content using the configured PSR-7 StreamFactoryInterface.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return StreamInterface PSR-7 StreamInterface containing the response body content or file data.
     */
    private function createBodyStream(): StreamInterface
    {
        // handle file streaming case (sendFile/sendStreamAsFile)
        if ($this->psrResponse->stream !== null) {
            return $this->createStreamFromFileHandle();
        }

        // handle regular content case
        return $this->streamFactory->createStream($this->psrResponse->content ?? '');
    }

    /**
     * Creates a PSR-7 stream bounded to the byte range defined in the Yii Response component.
     *
     * Positions seekable handles at the start offset and wraps them in a {@see RangeStream} so consumers cannot read past the
     * declared `$end`, preserving the PSR-7 body contract for partial-content responses without buffering bytes into
     * memory or temporary storage. Yii may supply decimal-string offsets from an HTTP Range header; they are
     * normalized to integers before positioning, with overflow rejected for offsets and range length. Empty downloads
     * return an empty body; non-seekable handles are accepted only at offset zero.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return StreamInterface PSR-7 stream bounded to the requested byte range.
     */
    private function createStreamFromFileHandle(): StreamInterface
    {
        $stream = $this->psrResponse->stream;

        if (
            is_array($stream) === false
            || count($stream) !== 3
            || isset($stream[0]) === false
            || isset($stream[1]) === false
            || isset($stream[2]) === false
        ) {
            throw new InvalidConfigException(Message::RESPONSE_STREAM_FORMAT_INVALID->getMessage());
        }

        [$handle, $begin, $end] = $stream;

        $begin = $this->normalizeStreamOffset($begin);
        $end = $this->normalizeStreamOffset($end);

        if ($begin === 0 && $end === -1 && is_resource($handle)) {
            fclose($handle);

            return $this->streamFactory->createStream('');
        }

        if ($begin < 0 || $end < $begin) {
            throw new InvalidConfigException(Message::RESPONSE_STREAM_RANGE_INVALID->getMessage($begin, $end));
        }

        if ($end - $begin === PHP_INT_MAX) {
            throw new InvalidConfigException(Message::RESPONSE_STREAM_RANGE_OVERFLOW->getMessage());
        }

        if (is_resource($handle) === false) {
            throw new InvalidConfigException(Message::RESPONSE_STREAM_HANDLE_INVALID->getMessage());
        }

        $seekable = stream_get_meta_data($handle)['seekable'];

        if (($seekable && fseek($handle, $begin) !== 0) || (!$seekable && $begin !== 0)) {
            fclose($handle);

            throw new InvalidConfigException(Message::RESPONSE_STREAM_READ_ERROR->getMessage());
        }

        try {
            $body = $this->streamFactory->createStreamFromResource($handle);
        } catch (Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw $e;
        }

        return new RangeStream($body, $begin, $end);
    }

    /**
     * Formats a {@see Cookie} object as a Set-Cookie header string for PSR-7 ResponseInterface.
     *
     * Converts the provided {@see Cookie} instance into a formatted Set-Cookie header string, applying Yii Cookie
     * validation if enabled and including all standard cookie attributes (expires, max-age, path, domain, secure,
     * httponly, samesite) as required.
     * - If cookie validation is enabled and a validation key is provided, the cookie value is hashed using Yii Security
     *   component for integrity protection.
     * - Expiration is normalized to a UNIX timestamp, supporting numeric, string, and {@see DateTimeInterface} values.
     * - All attributes are appended in compliance with RFC 6265 for interoperability with HTTP clients.
     *
     * @param Cookie $cookie Cookie instance to format.
     *
     * @return string Formatted 'Set-Cookie' header string.
     */
    private function formatCookieHeader(Cookie $cookie): string
    {
        $value = $cookie->value;
        $expire = $cookie->expire;

        if (is_numeric($expire)) {
            $expire = (int) $expire;
        }

        if (is_string($expire)) {
            $expire = (int) strtotime($expire);
        }

        if ($expire instanceof DateTimeInterface) {
            $expire = $expire->getTimestamp();
        }

        if (
            $this->psrResponse->enableCookieValidation
            && $this->psrResponse->cookieValidationKey !== ''
            && ($expire === 0 || $expire >= time())
        ) {
            $value = $this->security->hashData(
                serialize([$cookie->name, $cookie->value]),
                $this->psrResponse->cookieValidationKey,
            );
        }

        $header = urlencode($cookie->name) . '=' . urlencode($value);

        if ($expire !== null && $expire !== 0) {
            $expires = gmdate('D, d-M-Y H:i:s T', $expire);
            $maxAge = max(0, $expire - time());

            $header .= "; Expires={$expires}";
            $header .= "; Max-Age={$maxAge}";
        }

        $attributes = [
            'Path' => $cookie->path !== '' ? $cookie->path : null,
            'Domain' => $cookie->domain !== '' ? $cookie->domain : null,
            'Secure' => $cookie->secure ? '' : null,
            'HttpOnly' => $cookie->httpOnly ? '' : null,
            'SameSite' => $cookie->sameSite,
        ];

        // if `SameSite=None`, ensure Secure is present (browser requirement)
        if ($attributes['SameSite'] === Cookie::SAME_SITE_NONE && $attributes['Secure'] === null) {
            $attributes['Secure'] = '';
        }

        foreach ($attributes as $key => $val) {
            if ($val !== null) {
                $header .= "; {$key}" . ($val !== '' ? "={$val}" : '');
            }
        }

        return $header;
    }

    /**
     * Normalizes native integers and digit-only decimal strings without allowing integer overflow.
     *
     * @param mixed $offset Byte offset supplied by the Yii response.
     *
     * @throws InvalidConfigException when the offset is not an integer or a representable decimal string.
     *
     * @return int Normalized offset; range ordering and non-negative bounds are checked by the caller.
     */
    private function normalizeStreamOffset(mixed $offset): int
    {
        if (is_int($offset)) {
            return $offset;
        }

        if (is_string($offset) && preg_match('/\A[0-9]+\z/', $offset) === 1) {
            $decimal = ltrim($offset, '0');
            $normalized = filter_var($decimal === '' ? '0' : $decimal, FILTER_VALIDATE_INT);

            if (is_int($normalized)) {
                return $normalized;
            }
        }

        throw new InvalidConfigException(
            Message::RESPONSE_STREAM_FORMAT_INVALID->getMessage(),
        );
    }
}
