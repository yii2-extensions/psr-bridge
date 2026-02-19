<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\adapter;

use Psr\Http\Message\ServerRequestInterface;
use Yii;
use yii\base\InvalidConfigException;
use yii\web\{Cookie, HeaderCollection, RequestParserInterface};
use yii2\extensions\psrbridge\exception\Message;

use function implode;
use function in_array;
use function is_array;
use function is_string;
use function strpos;
use function strtoupper;
use function substr;
use function unserialize;

/**
 * Adapts PSR-7 server requests to Yii request expectations.
 *
 * {@see ServerRequestInterface} PSR-7 server request contract.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class ServerRequestAdapter
{
    /**
     * Adapted PSR-7 ServerRequestInterface instance.
     */
    public readonly ServerRequestInterface $psrRequest;

    /**
     * Creates a new instance of the {@see ServerRequestAdapter} class.
     *
     * If the PSR-7 request does not already have a parsed body, and a matching parser is configured for the request's
     * Content-Type, the body will be parsed and set on the request.
     *
     * This approach centralizes all PSR-7 request adaptation logic within the adapter, avoiding double parsing when the
     * body was already parsed by a PSR-7 server (RoadRunner, FrankenPHP, etc.).
     *
     * @param ServerRequestInterface $psrRequest PSR-7 ServerRequestInterface instance to adapt.
     * @param array $parsers Optional array of Content-Type to parser class mappings. If provided, and the request has
     * no parsed body, the adapter will attempt to parse the body using the configured parsers.
     *
     * @throws InvalidConfigException if a configured parser does not implement RequestParserInterface.
     *
     * @phpstan-param array<string, class-string|array{class: class-string, ...}|callable(): object> $parsers
     */
    public function __construct(ServerRequestInterface $psrRequest, array $parsers = [])
    {
        $this->psrRequest = $this->parseBody($psrRequest, $parsers);
    }

    /**
     * Retrieves the request body parameters, excluding the HTTP method override parameter if present.
     *
     * Returns the parsed body parameters from the PSR-7 ServerRequestInterface, removing the specified method override
     * parameter (such as `_method`) if it exists.
     *
     * This ensures that the method override value does not appear in the Yii Controller action parameters, maintaining
     * compatibility with Yii Request component logic.
     * - If the parsed body is not an array or the method parameter is not present, the original parsed body is
     *   returned.
     * - If the parsed body is `null`, an empty `array` is returned for consistency.
     *
     * Usage example:
     * ```php
     * $adapter = new \yii2\extensions\psrbridge\adapter\ServerRequestAdapter(
     *     $psrRequest,
     *     $parsers,
     * );
     * $params = $adapter->getBodyParams('_method');
     * ```
     *
     * @param string $methodParam Name of the HTTP method override parameter to exclude (for example, `_method`).
     *
     * @return array|object Request body parameters with the method override parameter removed if present.
     *
     * @phpstan-return array<mixed, mixed>|object
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
     * Retrieves cookies from the request, with optional Yii Cookie style validation.
     *
     * - If validation is enabled, each cookie value is validated using the provided validation key according to Yii
     *   Cookie conventions.
     * - If validation is not enabled, cookies are returned as-is.
     *
     * Usage example:
     * ```php
     * $adapter = new \yii2\extensions\psrbridge\adapter\ServerRequestAdapter(
     *     $psrRequest,
     *     $parsers,
     * );
     * $cookies = $adapter->getCookies(true, 'my-validation-key');
     * ```
     *
     * @param bool $enableValidation Whether to enable Yii Cookie style validation.
     * @param string $validationKey Validation key used for cookie validation (required if validation is enabled).
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return array Array of {@see Cookie} objects extracted from the PSR-7 ServerRequestInterface.
     *
     * @phpstan-return array<Cookie>
     */
    public function getCookies(bool $enableValidation, string $validationKey = ''): array
    {
        return $enableValidation
            ? $this->getValidatedCookies($validationKey)
            : $this->getSimpleCookies();
    }

    /**
     * Retrieves all HTTP headers from the PSR-7 ServerRequestInterface as a Yii HeaderCollection instance.
     *
     * Iterates over each header in the PSR-7 ServerRequestInterface and adds it to a new {@see HeaderCollection}
     * instance, concatenating multiple values with a comma and space, as expected by Yii.
     *
     * Usage example:
     * ```php
     * $adapter = new \yii2\extensions\psrbridge\adapter\ServerRequestAdapter(
     *     $psrRequest,
     *     $parsers,
     * );
     * $headers = $adapter->getHeaders();
     * $authorization = $headers->get('Authorization');
     * ```
     *
     * @return HeaderCollection Collection of HTTP headers from the PSR-7 ServerRequestInterface.
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
     * Determines the HTTP method by checking for an override parameter in the parsed body (such as `_method`) or the
     * X-Http-Method-Override header.
     * - If a valid override is found and is not one of GET, HEAD, or OPTIONS, it is returned in uppercase.
     * - Otherwise, the original method from the PSR-7 ServerRequestInterface is returned.
     *
     * Usage example:
     * ```php
     * $adapter = new \yii2\extensions\psrbridge\adapter\ServerRequestAdapter(
     *     $psrRequest,
     *     $parsers,
     * );
     * $method = $adapter->getMethod('_method');
     * ```
     *
     * @param string $methodParam Name of the HTTP method override parameter to check in the body (default: `_method`).
     *
     * @return string Resolved HTTP method for the request.
     */
    public function getMethod(string $methodParam = '_method'): string
    {
        $parsedBody = $this->psrRequest->getParsedBody();

        // check for method override in body
        if (
            is_array($parsedBody)
            && isset($parsedBody[$methodParam])
            && is_string($parsedBody[$methodParam])
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
     * Usage example:
     * ```php
     * $adapter = new \yii2\extensions\psrbridge\adapter\ServerRequestAdapter(
     *     $psrRequest,
     *     $parsers,
     * );
     * $body = $adapter->getParsedBody();
     * ```
     *
     * @return array|object|null Parsed body parameters as `array`, `object`, or `null` if not present.
     *
     * @phpstan-return array<mixed, mixed>|object|null
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
     * Usage example:
     * ```php
     * $adapter = new \yii2\extensions\psrbridge\adapter\ServerRequestAdapter(
     *     $psrRequest,
     *     $parsers,
     * );
     * $queryParams = $adapter->getQueryParams();
     * ```
     *
     * @return array Query parameters as an associative array.
     *
     * @phpstan-return array<array-key, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->psrRequest->getQueryParams();
    }

    /**
     * Retrieves the raw query string from the PSR-7 ServerRequestInterface URI.
     *
     * Usage example:
     * ```php
     * $adapter = new \yii2\extensions\psrbridge\adapter\ServerRequestAdapter(
     *     $psrRequest,
     *     $parsers,
     * );
     * $queryString = $adapter->getQueryString();
     * ```
     *
     * @return string Raw query string from the request URI, or an empty string if not present.
     */
    public function getQueryString(): string
    {
        return $this->psrRequest->getUri()->getQuery();
    }

    /**
     * Retrieves the raw body content from the PSR-7 ServerRequestInterface stream.
     *
     * The stream is rewound before reading (if seekable) to ensure the full content is returned from the beginning.
     *
     * Usage example:
     * ```php
     * $adapter = new \yii2\extensions\psrbridge\adapter\ServerRequestAdapter(
     *     $psrRequest,
     *     $parsers,
     * );
     * $rawBody = $adapter->getRawBody();
     * ```
     *
     * @return string Raw body content from the PSR-7 ServerRequestInterface stream.
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
     * Usage example:
     * ```php
     * $adapter = new \yii2\extensions\psrbridge\adapter\ServerRequestAdapter(
     *     $psrRequest,
     *     $parsers,
     * );
     * $params = $adapter->getServerParams();
     * ```
     *
     * @return array Server parameters from the PSR-7 ServerRequestInterface.
     *
     * @phpstan-return array<array-key, mixed>
     */
    public function getServerParams(): array
    {
        return $this->psrRequest->getServerParams();
    }

    /**
     * Retrieves uploaded files from the PSR-7 ServerRequestInterface.
     *
     * Usage example:
     * ```php
     * $adapter = new \yii2\extensions\psrbridge\adapter\ServerRequestAdapter(
     *     $psrRequest,
     *     $parsers,
     * );
     * $files = $adapter->getUploadedFiles();
     * ```
     *
     * @return array Uploaded files from the PSR-7 ServerRequestInterface.
     *
     * @phpstan-return array<array-key, mixed>
     */
    public function getUploadedFiles(): array
    {
        return $this->psrRequest->getUploadedFiles();
    }

    /**
     * Retrieves the request URL path and query string from the PSR-7 URI.
     *
     * This method provides the full URL as seen by the application, excluding the scheme, host, and fragment, ensuring
     * compatibility with Yii Routing and Request processing expectations.
     * - If the URI contains a query string, it is appended to the path with a '?' separator.
     * - If no query string is present, only the path is returned.
     *
     * Usage example:
     * ```php
     * $adapter = new \yii2\extensions\psrbridge\adapter\ServerRequestAdapter(
     *     $psrRequest,
     *     $parsers,
     * );
     * $url = $adapter->getUrl();
     * // '/site/index?foo=bar'
     * ```
     *
     * @return string Request URL path and query string, excluding scheme, host, and fragment.
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
     * Extracts and validates cookies from the PSR-7 ServerRequestInterface using Yii style validation.
     *
     * - Iterates over cookie parameters from the PSR-7 request.
     * - Validates each value with the specified validation key.
     * - Only cookies that pass validation and decoding are included in the result.
     *
     * @param string $validationKey Validation key used for Yii Cookie validation.
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

    /**
     * Parses the request body if needed using the configured parsers.
     *
     * If the PSR-7 request already has a parsed body (not `null`), this method returns it unchanged.
     *
     * Otherwise, it extracts the Content-Type, finds a matching parser from the provided parsers array, parses the body
     * content, and returns a new request with the parsed body set.
     *
     * This approach avoids double parsing when the body was already parsed by a PSR-7 server (RoadRunner, FrankenPHP,
     * etc.).
     *
     * @param ServerRequestInterface $request PSR-7 ServerRequestInterface instance to potentially parse.
     * @param array $parsers Array of Content-Type to parser class mappings.
     *
     * @throws InvalidConfigException if a configured parser does not implement RequestParserInterface.
     *
     * @return ServerRequestInterface Request with parsed body set if parsing was performed, or unchanged.
     *
     * @phpstan-param array<string, class-string|array{class: class-string, ...}|callable(): object> $parsers
     */
    private function parseBody(ServerRequestInterface $request, array $parsers): ServerRequestInterface
    {
        if ($request->getParsedBody() !== null) {
            return $request;
        }

        $rawContentType = $request->getHeaderLine('Content-Type');

        $pos = strpos($rawContentType, ';');
        $contentType = $pos !== false ? substr($rawContentType, 0, $pos) : $rawContentType;

        $parsedParams = null;

        if (isset($parsers[$contentType])) {
            $parser = Yii::createObject($parsers[$contentType]);

            if ($parser instanceof RequestParserInterface === false) {
                throw new InvalidConfigException(
                    Message::INVALID_REQUEST_PARSER->getMessage($contentType, RequestParserInterface::class),
                );
            }

            $parsedParams = $parser->parse((string) $request->getBody(), $rawContentType);
        } elseif (isset($parsers['*'])) {
            $parser = Yii::createObject($parsers['*']);

            if ($parser instanceof RequestParserInterface === false) {
                throw new InvalidConfigException(
                    Message::INVALID_FALLBACK_REQUEST_PARSER->getMessage(RequestParserInterface::class),
                );
            }

            $parsedParams = $parser->parse((string) $request->getBody(), $rawContentType);
        }

        return $request->withParsedBody($parsedParams);
    }
}
