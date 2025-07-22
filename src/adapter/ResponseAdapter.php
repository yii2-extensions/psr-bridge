<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\adapter;

use DateTimeInterface;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, StreamFactoryInterface};
use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\Json;
use yii\web\{Cookie, Response};
use yii2\extensions\psrbridge\exception\Message;

use function gmdate;
use function is_numeric;
use function is_string;
use function max;
use function strtotime;
use function time;
use function urlencode;

/**
 * Adapter for PSR-7 ResponseInterface to Yii2 Response component.
 *
 * Provides a bridge between Yii2 {@see Response} and PSR-7 {@see ResponseInterface}, enabling seamless interoperability
 * with PSR-7 compatible HTTP stacks in Yii2 Application.
 *
 * This adapter exposes methods to convert Yii2 Response objects to PSR-7 ResponseInterface, including header and cookie
 * translation, body stream creation, and support for Yii2 Cookie validation mechanism.
 *
 * All conversions are immutable and type-safe, ensuring compatibility with both legacy Yii2 workflows and modern PSR-7
 * middleware stacks.
 *
 * Key features.
 * - Handles cookie formatting and validation key enforcement.
 * - Immutable, fluent conversion for safe usage in middleware pipelines.
 * - PSR-7 to Yii2 Response component for seamless interoperability.
 * - Supports custom status text and content body.
 * - Translates headers and cookies, including Yii2 Cookie validation.
 *
 * @see ResponseInterface for PSR-7 ResponseInterface contract.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class ResponseAdapter
{
    /**
     * Creates a new instance of the {@see ResponseAdapter} class.
     *
     * @param Response $response Yii2 Response instance to adapt.
     * @param ResponseFactoryInterface $responseFactory PSR-7 ResponseFactoryInterface instance for response creation.
     * @param StreamFactoryInterface $streamFactory PSR-7 StreamFactoryInterface instance for body stream creation.
     */
    public function __construct(
        private readonly Response $response,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * Converts the Yii2 {@see Response} instance to a PSR-7 {@see ResponseInterface}.
     *
     * Creates a new PSR-7 ResponseInterface using the configured response and stream factories, copying status code,
     * status text, headers, cookies, and body content from the Yii2 Response component.
     *
     * - All headers are transferred to the PSR-7 ResponseInterface, with multiple values preserved.
     * - Cookies are formatted and added as separate 'Set-Cookie' headers.
     * - Response body is created from the Yii2 Response content using the PSR-7 StreamFactoryInterface.
     *
     * This method enables seamless interoperability between Yii2 and PSR-7 compatible HTTP stacks by providing a fully
     * constructed PSR-7 ResponseInterface based on the Yii2 Response state.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return ResponseInterface PSR-7 ResponseInterface instance with headers, cookies, and body content.
     *
     * Usage example:
     * ```php
     * $psr7Response = $adapter->toPsr7();
     * ```
     */
    public function toPsr7(): ResponseInterface
    {
        // create base response
        $psr7Response = $this->responseFactory->createResponse(
            $this->response->getStatusCode(),
            $this->response->statusText,
        );

        /** @phpstan-var array<string, string[]> $headers */
        $headers = $this->response->getHeaders()->toArray();

        // add headers
        foreach ($headers as $name => $values) {
            $psr7Response = $psr7Response->withHeader($name, $values);
        }

        // add cookies with proper formatting
        foreach ($this->buildCookieHeaders() as $cookieHeader) {
            $psr7Response = $psr7Response->withAddedHeader('Set-Cookie', $cookieHeader);
        }

        // create body stream from response content
        $body = $this->streamFactory->createStream($this->response->content ?? '');

        return $psr7Response->withBody($body);
    }

    /**
     * Builds and returns formatted cookie headers from the Yii2 Response component.
     *
     * Iterates over all cookies in the Yii2 Response component and generates an array of formatted cookie header
     * strings suitable for use as 'Set-Cookie' headers in a PSR-7 ResponseInterface.
     *
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
        $request = Yii::$app->getRequest();

        $enableValidation = $request->enableCookieValidation;
        $validationKey = null;

        if ($enableValidation) {
            $validationKey = $request->cookieValidationKey;

            if ($validationKey === '') {
                throw new InvalidConfigException(
                    Message::COOKIE_VALIDATION_KEY_NOT_CONFIGURED->getMessage($request::class),
                );
            }
        }

        foreach ($this->response->getCookies() as $cookie) {
            if ($cookie->value !== null && $cookie->value !== '') {
                $headers[] = $this->formatCookieHeader($cookie, $enableValidation, $validationKey);
            }
        }

        return $headers;
    }

    /**
     * Formats a {@see Cookie} object as a 'Set-Cookie' header string for PSR-7 ResponseInterface.
     *
     * Converts the provided {@see Cookie} instance into a properly formatted 'Set-Cookie' header string, applying Yii2
     * Cookie validation if enabled and including all standard cookie attributes (expires, max-age, path, domain,
     * secure, httponly, samesite) as required.
     *
     * - If cookie validation is enabled and a validation key is provided, the cookie value is hashed using Yii2
     *   Security component for integrity protection.
     * - Expiration is normalized to a UNIX timestamp, supporting numeric, string, and {@see DateTimeInterface} values.
     * - All attributes are appended in compliance with RFC 6265 for interoperability with HTTP clients.
     *
     * This method is used internally to generate 'Set-Cookie' headers for PSR-7 ResponseInterface objects, ensuring
     * compatibility with both Yii2 and PSR-7 HTTP stacks.
     *
     * @param Cookie $cookie Cookie instance to format.
     * @param bool $enableValidation Whether to apply Yii2 Cookie validation.
     * @param string|null $validationKey Validation key for hashing the cookie value if validation is enabled.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return string Formatted 'Set-Cookie' header string.
     */
    private function formatCookieHeader(Cookie $cookie, bool $enableValidation, string|null $validationKey): string
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

        if ($enableValidation && $validationKey !== null && ($expire === 0 || $expire >= time())) {
            $value = Yii::$app->getSecurity()->hashData(Json::encode([$cookie->name, $cookie->value]), $validationKey);
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
            'Secure' => $cookie->secure ? 'Secure' : null,
            'HttpOnly' => $cookie->httpOnly ? '' : null,
            'SameSite' => $cookie->sameSite,
        ];

        foreach ($attributes as $key => $val) {
            if ($val !== null) {
                $header .= "; {$key}" . ($val !== '' ? "={$val}" : '');
            }
        }

        return $header;
    }
}
