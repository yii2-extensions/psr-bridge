<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\adapter;

use Psr\Http\Message\ServerRequestInterface;
use Yii;
use yii\base\InvalidConfigException;
use yii\web\{Cookie, HeaderCollection};
use yii2\extensions\psrbridge\exception\Message;

use function implode;
use function in_array;
use function is_array;
use function is_string;
use function strtoupper;
use function unserialize;

/**
 * Adapter for PSR-7 ServerRequestInterface to Yii2 Request component.
 *
 * Provides a bridge between PSR-7 ServerRequestInterface and Yii2 Request component, enabling seamless integration of
 * PSR-7 compatible HTTP stacks with Yii2 Application.
 *
 * This adapter exposes methods to access request data such as body parameters, cookies, headers, HTTP method, query
 * parameters, uploaded files, and URL information in a format compatible with Yii2 expectations.
 *
 * The adapter supports cookie validation, HTTP method override detection, and proper extraction of request metadata
 * for both traditional SAPI and worker-based (RoadRunner, FrankenPHP, etc.) environments.
 *
 * All returned data is immutable and designed for safe, read-only access.
 *
 * Key features.
 * - Cookie extraction with optional Yii2 style validation.
 * - Fluent, exception-safe API for request inspection.
 * - HTTP method override support via body and headers.
 * - Immutable, type-safe access to request data and metadata.
 * - PSR-7 to Yii2 Request component for seamless interoperability.
 * - Worker mode compatibility for modern PHP runtimes.
 *
 * @see ServerRequestInterface for PSR-7 ServerRequestInterface contract.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class ServerRequestAdapter
{
    /**
     * Creates a new instance of the {@see ServerRequestAdapter} class.
     *
     * @param ServerRequestInterface $psrRequest PSR-7 ServerRequestInterface instance to adapt.
     */
    public function __construct(public readonly ServerRequestInterface $psrRequest) {}

    /**
     * Retrieves the request body parameters, excluding the HTTP method override parameter if present.
     *
     * Returns the parsed body parameters from the PSR-7 ServerRequestInterface, removing the specified method override
     * parameter (such as '_method') if it exists.
     *
     * This ensures that the method override value does not appear in the Yii2 Controller action parameters, maintaining
     * compatibility with Yii2 Request component logic.
     *
     * - If the parsed body is not an array or the method parameter is not present, the original parsed body is
     *   returned.
     * - If the parsed body is `null`, an empty array is returned for consistency.
     *
     * @param string $methodParam Name of the HTTP method override parameter to exclude (for example, '_method').
     *
     * @return array|object Request body parameters with the method override parameter removed if present.
     *
     * @phpstan-return array<mixed, mixed>|object
     *
     * Usage example:
     * ```php
     * $params = $adapter->getBodyParams('_method');
     * ```
     */
    public function getBodyParams(string $methodParam): array|object
    {
        $parsedBody = $this->psrRequest->getParsedBody();

        if (is_array($parsedBody) && isset($parsedBody[$methodParam])) {
            $bodyParams = $parsedBody;

            unset($bodyParams[$methodParam]);

            return $bodyParams;
        }

        return $parsedBody ?? [];
    }

    /**
     * Retrieves cookies from the request, with optional Yii2 style validation.
     *
     * - If validation is enabled, each cookie value is validated using the provided validation key according to Yii2
     * conventions.
     * - If validation is not enabled, cookies are returned as-is.
     *
     * This method ensures compatibility with Yii2 Cookie validation mechanism, supporting secure extraction of cookies
     * for use in Yii2 Application.
     *
     * @param bool $enableValidation Whether to enable Yii2 Cookie style validation.
     * @param string $validationKey Validation key used for cookie validation (required if validation is enabled).
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return array Array of {@see Cookie} objects extracted from the PSR-7 ServerRequestInterface.
     *
     * @phpstan-return array<Cookie>
     *
     * Usage example:
     * ```php
     * $cookies = $adapter->getCookies(true, 'my-validation-key');
     * ```
     */
    public function getCookies(bool $enableValidation, string $validationKey = ''): array
    {
        return $enableValidation
            ? $this->getValidatedCookies($validationKey)
            : $this->getSimpleCookies();
    }

    /**
     * Retrieves all HTTP headers from the PSR-7 ServerRequestInterface as a Yii2 HeaderCollection instance.
     *
     * Iterates over each header in the PSR-7 ServerRequestInterface and adds it to a new {@see HeaderCollection}
     * instance, concatenating multiple values with a comma and space, as expected by Yii2.
     *
     * @return HeaderCollection Collection of HTTP headers from the PSR-7 ServerRequestInterface.
     *
     * Usage example:
     * ```php
     * $headers = $adapter->getHeaders();
     * $authorization = $headers->get('Authorization');
     * ```
     */
    public function getHeaders(): HeaderCollection
    {
        $headerCollection = new HeaderCollection();

        foreach ($this->psrRequest->getHeaders() as $name => $values) {
            $headerCollection->set((string) $name, implode(', ', $values));
        }

        return $headerCollection;
    }

    /**
     * Retrieves the HTTP method for the current request, supporting method override via body or header.
     *
     * Determines the HTTP method by checking for an override parameter in the parsed body (such as '_method') or the
     * 'X-Http-Method-Override' header.
     *
     * - If a valid override is found and is not one of 'GET', 'HEAD', or 'OPTIONS', it is returned in uppercase.
     * - Otherwise, the original method from the PSR-7 ServerRequestInterface is returned.
     *
     * This method enables support for HTTP method spoofing in environments where certain HTTP verbs are not natively
     * supported by clients or proxies, ensuring compatibility with RESTful routing and Yii2 Controller actions.
     *
     * @param string $methodParam Name of the HTTP method override parameter to check in the body (default: '_method').
     *
     * @return string Resolved HTTP method for the request.
     *
     * Usage example:
     * ```php
     * $method = $adapter->getMethod('_method');
     * ```
     */
    public function getMethod(string $methodParam = '_method'): string
    {
        $parsedBody = $this->psrRequest->getParsedBody();

        // check for method override in body
        if (
            is_array($parsedBody) &&
            isset($parsedBody[$methodParam]) &&
            is_string($parsedBody[$methodParam])
        ) {
            $methodOverride = strtoupper($parsedBody[$methodParam]);

            if (in_array($methodOverride, ['GET', 'HEAD', 'OPTIONS'], true) === false) {
                return $methodOverride;
            }
        }

        // check for 'X-Http-Method-Override' header
        if ($this->psrRequest->hasHeader('X-Http-Method-Override')) {
            $overrideHeader = $this->psrRequest->getHeaderLine('X-Http-Method-Override');

            if ($overrideHeader !== '') {
                return $overrideHeader;
            }
        }

        return $this->psrRequest->getMethod();
    }

    /**
     * Retrieves the parsed body parameters from the PSR-7 ServerRequestInterface.
     *
     * @return array|object|null Parsed body parameters as `array`, `object`, or `null` if not present.
     *
     * @phpstan-return array<mixed, mixed>|object|null
     *
     * Usage example:
     * ```php
     * $body = $adapter->getParsedBody();
     * ```
     */
    public function getParsedBody(): array|object|null
    {
        return $this->psrRequest->getParsedBody();
    }

    /**
     * Retrieves query parameters from the PSR-7 ServerRequestInterface or cached values.
     *
     * Returns the cached query parameters if previously set, otherwise retrieves them directly from the PSR-7
     * ServerRequestInterface instance.
     *
     * This method provides immutable, type-safe access to query parameters for use in Yii2 Request component logic and
     * controller actions.
     *
     * @return array Query parameters as an associative array.
     *
     * @phpstan-return array<array-key, mixed>
     *
     * Usage example:
     * ```php
     * $queryParams = $adapter->getQueryParams();
     * ```
     */
    public function getQueryParams(): array
    {
        return $this->psrRequest->getQueryParams();
    }

    /**
     * Retrieves the raw query string from the PSR-7 ServerRequestInterface URI.
     *
     * @return string Raw query string from the request URI, or an empty string if not present.
     *
     * Usage example:
     * ```php
     * $queryString = $adapter->getQueryString();
     * ```
     */
    public function getQueryString(): string
    {
        return $this->psrRequest->getUri()->getQuery();
    }

    /**
     * Retrieves the raw body content from the PSR-7 ServerRequestInterface stream.
     *
     * Returns the entire contents of the underlying stream as a string, rewinding first if the stream is seekable.
     *
     * This method provides direct access to the unparsed request body, which is useful for processing raw payloads such
     * as JSON, XML, or binary data.
     *
     * The stream is rewound before reading (if seekable) to ensure the full content is returned from the beginning.
     *
     * @return string Raw body content from the PSR-7 ServerRequestInterface stream.
     *
     * Usage example:
     * ```php
     * $rawBody = $adapter->getRawBody();
     * ```
     */
    public function getRawBody(): string
    {
        $body = $this->psrRequest->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $body->getContents();
    }

    /**
     * Retrieves server parameters from the PSR-7 ServerRequestInterface.
     *
     * Returns the server parameters as provided by the underlying PSR-7 ServerRequestInterface instance.
     *
     * This method exposes the raw server parameters array, enabling direct access to all server environment values in
     * a format compatible with PSR-7 expectations.
     *
     * @return array Server parameters from the PSR-7 ServerRequestInterface.
     *
     * @phpstan-return array<array-key, mixed>
     *
     * Usage example:
     * ```php
     * $params = $adapter->getServerParams();
     * ```
     */
    public function getServerParams(): array
    {
        return $this->psrRequest->getServerParams();
    }

    /**
     * Retrieves uploaded files from the PSR-7 ServerRequestInterface.
     *
     * Returns the uploaded files as provided by the underlying PSR-7 ServerRequestInterface instance.
     *
     * This method exposes the raw uploaded files array, enabling direct access to all files sent with the request
     * in a format compatible with PSR-7 expectations.
     *
     * @return array Uploaded files from the PSR-7 ServerRequestInterface.
     *
     * @phpstan-return array<mixed, mixed>
     *
     * Usage example:
     * ```php
     * $files = $adapter->getUploadedFiles();
     * ```
     */
    public function getUploadedFiles(): array
    {
        return $this->psrRequest->getUploadedFiles();
    }

    /**
     * Retrieves the request URL path and query string from the PSR-7 URI.
     *
     * This method provides the full URL as seen by the application, excluding the scheme, host, and fragment, ensuring
     * compatibility with Yii2 Routing and Request processing expectations.
     *
     * - If the URI contains a query string, it is appended to the path with a '?' separator.
     * - If no query string is present, only the path is returned.
     *
     * @return string Request URL path and query string, excluding scheme, host, and fragment.
     *
     * Usage example:
     * ```php
     * $url = $adapter->getUrl();
     * // '/site/index?foo=bar'
     * ```
     */
    public function getUrl(): string
    {
        $uri = $this->psrRequest->getUri();
        $url = $uri->getPath();

        if ($uri->getQuery() !== '') {
            $url .= '?' . $uri->getQuery();
        }

        return $url;
    }

    /**
     * Extracts cookies from the PSR-7 ServerRequestInterface without validation.
     *
     * Iterates over the cookie parameters provided by the PSR-7 ServerRequestInterface and creates a {@see Cookie}
     * instance for each non-empty value.
     *
     * This method returns all cookies as-is, without applying Yii2 style validation or decoding.
     *
     * It is intended for use in cases where cookie integrity is not enforced by a validation key.
     *
     * @return array Array of {@see Cookie} objects extracted from the PSR-7 ServerRequestInterface.
     *
     * @phpstan-return array<Cookie>
     */
    private function getSimpleCookies(): array
    {
        $cookies = [];
        $cookieParams = $this->psrRequest->getCookieParams();

        foreach ($cookieParams as $name => $value) {
            if ($value !== '') {
                $cookies[$name] = new Cookie(
                    [
                        'name' => $name,
                        'value' => $value,
                        'expire' => null,
                    ],
                );
            }
        }

        return $cookies;
    }

    /**
     * Extracts and validates cookies from the PSR-7 ServerRequestInterface using Yii2 style validation.
     *
     * Iterates over the cookie parameters provided by the PSR-7 ServerRequestInterface and validates each value using the
     * specified validation key.
     *
     * Only cookies that pass validation and decoding are included in the result.
     *
     * This ensures that only cookies with integrity verified by Yii2 Security component are returned, supporting
     * secure cookie extraction for Yii2 Application.
     *
     * @param string $validationKey Validation key used for Yii2 Cookie validation.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return array Array of {@see Cookie} objects with validated names and values.
     *
     * @phpstan-return array<Cookie>
     */
    private function getValidatedCookies(string $validationKey): array
    {
        if ($validationKey === '') {
            throw new InvalidConfigException(Message::COOKIE_VALIDATION_KEY_REQUIRED->getMessage());
        }

        $cookies = [];
        $cookieParams = $this->psrRequest->getCookieParams();

        foreach ($cookieParams as $name => $value) {
            if (is_string($value) && $value !== '') {
                $data = Yii::$app->getSecurity()->validateData($value, $validationKey);

                if (is_string($data)) {
                    $data = @unserialize($data, ['allowed_classes' => false]);
                }

                if (is_array($data) && isset($data[0], $data[1]) && $data[0] === $name) {
                    $cookies[$name] = new Cookie(
                        [
                            'name' => $name,
                            'value' => $data[1],
                            'expire' => null,
                        ],
                    );
                }
            }
        }

        return $cookies;
    }
}
