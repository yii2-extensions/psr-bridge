<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\adapter;

use DateTimeInterface;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, StreamFactoryInterface, StreamInterface};
use yii\base\{InvalidConfigException, Security};
use yii\web\Cookie;
use yii2\extensions\psrbridge\exception\Message;
use yii2\extensions\psrbridge\http\{Request, Response};

use function count;
use function fclose;
use function fseek;
use function gmdate;
use function is_array;
use function is_int;
use function is_numeric;
use function is_resource;
use function is_string;
use function max;
use function serialize;
use function strtotime;
use function time;
use function urlencode;

/**
 * Adapts Yii responses to PSR-7 responses.
 *
 * {@see ResponseInterface} PSR-7 response contract.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class ResponseAdapter
{
    /**
     * Creates a new instance of the {@see ResponseAdapter} class.
     *
     * @param Response $psrResponse Yii Response instance to adapt.
     * @param ResponseFactoryInterface $responseFactory PSR-7 ResponseFactoryInterface instance for response creation.
     * @param StreamFactoryInterface $streamFactory PSR-7 StreamFactoryInterface instance for body stream creation.
     * @param Security $security Optional Security component for cookie validation.
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
     * Usage example:
     * ```php
     * $adapter = new \yii2\extensions\psrbridge\adapter\ResponseAdapter(
     *     $response,
     *     $responseFactory,
     *     $streamFactory,
     *     $security,
     * );
     * $psr7Response = $adapter->toPsr7();
     * ```
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

        /** @phpstan-var array<string, string[]> $headers */
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
     * Builds and returns formatted cookie headers from the Yii Response component.
     *
     * Iterates over all cookies in the Yii Response component and generates an array of formatted cookie header strings
     * suitable for use as Set-Cookie headers in a PSR-7 ResponseInterface.
     * - Each cookie is formatted using {@see formatCookieHeader()}.
     * - If cookie validation is enabled, each cookie value is validated using the configured validation key.
     * - Only non-empty cookie values are included in the result.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @phpstan-return string[] Array of formatted cookie headers.
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
     * Creates a PSR-7 stream from a file handle and byte range defined in the Yii Response component.
     *
     * Reads the specified byte range from the file handle provided by the Yii Response stream property and return a
     * new PSR-7 StreamInterface containing the file data.
     *
     * This method validates the stream format, range, and handle before reading, ensuring type safety and correct
     * operation for file streaming scenarios.
     * - Ensures the byte range is valid and the handle is a resource.
     * - Reads the requested range and closes the file handle after reading.
     * - Returns a new PSR-7 stream containing the file content for the specified range.
     * - Validates that the stream is a three-element array: [resource, int $begin, int $end].
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return StreamInterface PSR-7 StreamInterface containing the file data for the specified range.
     */
    private function createStreamFromFileHandle(): StreamInterface
    {
        $stream = $this->psrResponse->stream;

        if (
            is_array($stream) === false
            || count($stream) !== 3
            || isset($stream[0]) === false
            || isset($stream[1]) === false || is_int($stream[1]) === false
            || isset($stream[2]) === false || is_int($stream[2]) === false
        ) {
            throw new InvalidConfigException(Message::RESPONSE_STREAM_FORMAT_INVALID->getMessage());
        }

        [$handle, $begin, $end] = $stream;

        if ($begin < 0 || $end < $begin) {
            throw new InvalidConfigException(Message::RESPONSE_STREAM_RANGE_INVALID->getMessage($begin, $end));
        }

        if (is_resource($handle) === false) {
            throw new InvalidConfigException(Message::RESPONSE_STREAM_HANDLE_INVALID->getMessage());
        }

        // read the specified range from the file
        fseek($handle, $begin);

        $content = stream_get_contents($handle, $end - $begin + 1);

        if ($content === false) {
            fclose($handle);

            throw new InvalidConfigException(Message::RESPONSE_STREAM_READ_ERROR->getMessage());
        }

        fclose($handle);

        return $this->streamFactory->createStream($content);
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
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
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
}
