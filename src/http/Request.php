<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

use Psr\Http\Message\{ServerRequestInterface, UploadedFileInterface};
use yii\base\InvalidConfigException;
use yii\web\{CookieCollection, HeaderCollection, UploadedFile};
use yii2\extensions\psrbridge\adapter\ServerRequestAdapter;
use yii2\extensions\psrbridge\exception\Message;

use function is_array;

/**
 * HTTP Request extension with PSR-7 bridge and worker mode support.
 *
 * Provides a drop-in replacement for {@see \yii\web\Request} that integrates PSR-7 ServerRequestInterface handling,
 * enabling seamless interoperability with PSR-7 compatible HTTP stacks and modern PHP runtimes.
 *
 * This class delegates request data access (body, headers, cookies, files, etc.) to a {@see ServerRequestAdapter}
 * when a PSR-7 ServerRequestInterface is set, supporting both traditional SAPI and worker-based environments (such as
 * RoadRunner, FrankenPHP, or similar).
 *
 * All methods transparently fall back to the parent implementation if no PSR-7 adapter is present, ensuring
 * compatibility with legacy Yii2 workflows.
 *
 * The class also provides conversion utilities for PSR-7 {@see UploadedFileInterface} and exposes the underlying PSR-7
 * ServerRequestInterface for advanced use cases.
 *
 * Key features:
 * - Automatic fallback to Yii2 parent methods when no adapter is set.
 * - Conversion utilities for PSR-7 UploadedFileInterface to Yii2 format.
 * - Full compatibility with Yii2 Cookie validation and CSRF protection.
 * - Immutable, type-safe access to request data (body, headers, cookies, files, query, etc.).
 * - PSR-7 ServerRequestAdapter integration via {@see setPsr7Request()} and {@see getPsr7Request()}.
 * - Worker mode support for modern runtimes (see {@see $workerMode}).
 *
 * @see ServerRequestAdapter for PSR-7 to Yii2 Request adapter.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class Request extends \yii\web\Request
{
    /**
     * Whether the request is in worker mode.
     */
    public bool $workerMode = true;

    /**
     * PSR-7 ServerRequestAdapter for bridging PSR-7 ServerRequestInterface with Yii2 Request component.
     *
     * Adapter allows the Request class to access PSR-7 ServerRequestInterface data while maintaining compatibility with
     * Yii2 Request component.
     */
    private ServerRequestAdapter|null $adapter = null;

    /**
     * Retrieves the request body parameters, excluding the HTTP method override parameter if present.
     *
     * Returns the parsed body parameters from the PSR-7 ServerRequestInterface, removing the specified method override
     * parameter (such as '_method') if it exists.
     *
     * If the PSR-7 adapter is not set, it falls back to the parent implementation.
     *
     * This ensures that the method override value does not appear in the Yii2 Controller action parameters, maintaining
     * compatibility with Yii2 Request component logic.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return array|object Request body parameters with the method override parameter removed if present.
     *
     * @phpstan-return array<mixed, mixed>|object
     *
     * Usage example:
     * ```php
     * $params = $request->getBodyParams();
     * ```
     */
    public function getBodyParams(): array|object
    {
        if ($this->adapter !== null) {
            return $this->adapter->getBodyParams($this->methodParam);
        }

        return parent::getBodyParams();
    }

    /**
     * Retrieves cookies from the current request, supporting PSR-7 and Yii2 validation.
     *
     * Returns a {@see CookieCollection} containing cookies extracted from the PSR-7 ServerRequestInterface if the
     * adapter is set, applying Yii2 style validation when enabled.
     *
     * If no adapter is present, falls back to the parent implementation.
     *
     * This method ensures compatibility with Yii2 Cookie validation mechanism and provides immutable, read-only access
     * to cookies for both SAPI and worker-based environments.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return CookieCollection Collection of cookies for the current request.
     *
     * Usage example:
     * ```php
     * $cookies = $request->getCookies();
     * $value = $cookies->getValue('session_id');
     * ```
     */
    public function getCookies(): CookieCollection
    {
        if ($this->adapter !== null) {
            $cookies = $this->adapter->getCookies($this->enableCookieValidation, $this->cookieValidationKey);

            return new CookieCollection($cookies, ['readOnly' => true]);
        }

        return parent::getCookies();
    }

    /**
     * Retrieves the CSRF token from the request headers.
     *
     * Returns the value of the CSRF token as obtained from the configured CSRF header in the request.
     *
     * This method uses {@see getHeaders()} to access the header collection and retrieves the value for
     * {@see self::csrfHeader}.
     *
     * @return string|null CSRF token value from the request header, or `null` if not present.
     *
     * Usage example:
     * ```php
     * $token = $request->getCsrfTokenFromHeader();
     * ```
     *
     * @phpstan-ignore return.unusedType
     */
    public function getCsrfTokenFromHeader(): string|null
    {
        return $this->getHeaders()->get($this->csrfHeader);
    }

    /**
     * Retrieves HTTP headers from the current request, supporting PSR-7 and Yii2 fallback.
     *
     * Returns a {@see HeaderCollection} containing all HTTP headers extracted from the PSR-7 ServerRequestInterface if
     * the adapter is set.
     *
     * Applies internal header filtering for compatibility with Yii2 expectations.
     *
     * If no adapter is present, falls back to the parent implementation.
     *
     * This method ensures immutable, type-safe access to headers for both SAPI and worker-based environments.
     *
     * @return HeaderCollection Collection of HTTP headers for the current request.
     *
     * Usage example:
     * ```php
     * $headers = $request->getHeaders();
     * $authorization = $headers->get('Authorization');
     * ```
     */
    public function getHeaders(): HeaderCollection
    {
        if ($this->adapter !== null) {
            $headers = $this->adapter->getHeaders();

            $this->filterHeaders($headers);

            return $headers;
        }

        return parent::getHeaders();
    }

    /**
     * Retrieves the HTTP method for the current request, supporting PSR-7 and Yii2 fallback.
     *
     * Returns the HTTP method as determined by the PSR-7 adapter if present, using the configured method override
     * parameter.
     *
     * If no adapter is set, falls back to the parent implementation.
     *
     * This method enables support for HTTP method spoofing and ensures compatibility with both PSR-7 and Yii2
     * Controller action routing.
     *
     * @return string Resolved HTTP method for the current request.
     *
     * Usage example:
     * ```php
     * $method = $request->getMethod();
     * ```
     */
    public function getMethod(): string
    {
        if ($this->adapter !== null) {
            return $this->adapter->getMethod($this->methodParam);
        }

        return parent::getMethod();
    }

    /**
     * Retrieves the parsed body parameters from the current request.
     *
     * Returns the parsed body parameters as provided by the PSR-7 adapter if present, or falls back to the parent
     * implementation otherwise.
     *
     * This method enables seamless access to request body data in both PSR-7 and Yii2 environments, supporting
     * interoperability with modern HTTP stacks and legacy workflows.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @phpstan-return array<mixed, mixed>|object|null
     *
     * Usage example:
     * ```php
     * $bodyParams = $request->getParsedBody();
     * ```
     */
    public function getParsedBody(): array|object|null
    {
        if ($this->adapter !== null) {
            return $this->adapter->getParsedBody();
        }

        return parent::getBodyParams();
    }

    /**
     * Retrieves the underlying PSR-7 ServerRequestInterface instance from the adapter.
     *
     * Returns the PSR-7 {@see ServerRequestInterface} associated with this request via the internal adapter.
     *
     * If the adapter is not set, an {@see InvalidConfigException} is thrown to indicate misconfiguration or missing
     * bridge setup.
     *
     * This method enables advanced interoperability with PSR-7 compatible HTTP stacks by exposing the raw ServerRequest
     * object.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return ServerRequestInterface PSR-7 ServerRequestInterface instance from the adapter.
     *
     * Usage example:
     * ```php
     * $psr7Request = $request->getPsr7Request();
     * ```
     */
    public function getPsr7Request(): ServerRequestInterface
    {
        if ($this->adapter === null) {
            throw new InvalidConfigException(Message::PSR7_REQUEST_ADAPTER_NOT_SET->getMessage());
        }

        return $this->adapter->psrRequest;
    }

    /**
     * Retrieves query parameters from the current request, supporting PSR-7 and Yii2 fallback.
     *
     * Returns the query parameters as an array from the PSR-7 adapter if present.
     *
     * If no adapter is set, falls back to the parent implementation.
     *
     * @return array Query parameters for the current request.
     *
     * @phpstan-return array<mixed, mixed>
     *
     * Usage example:
     * ```php
     * $queryParams = $request->getQueryParams();
     * ```
     */
    public function getQueryParams(): array
    {
        if ($this->adapter !== null) {
            return $this->adapter->getQueryParams();
        }

        return parent::getQueryParams();
    }

    /**
     * Retrieves the query string from the current request, supporting PSR-7 and Yii2 fallback.
     *
     * Returns the query string as a string from the PSR-7 adapter if present.
     *
     * If no adapter is set, falls back to the parent implementation.
     *
     * This method enables seamless access to the raw query string in both PSR-7 and Yii2 environments, supporting
     * interoperability with modern HTTP stacks and legacy workflows.
     *
     * @return string Query string for the current request.
     *
     * Usage example:
     * ```php
     * $queryString = $request->getQueryString();
     * ```
     */
    public function getQueryString(): string
    {
        if ($this->adapter !== null) {
            return $this->adapter->getQueryString();
        }

        return parent::getQueryString();
    }

    /**
     * Retrieves the raw body content from the current request, supporting PSR-7 and Yii2 fallback.
     *
     * Returns the raw body as a string from the PSR-7 adapter if present.
     *
     * If no adapter is set, falls back to the parent implementation.
     *
     * This method enables seamless access to the raw request body in both PSR-7 and Yii2 environments, supporting
     * interoperability with modern HTTP stacks and legacy workflows.
     *
     * @return string Raw body content for the current request.
     *
     * Usage example:
     * ```php
     * $rawBody = $request->getRawBody();
     * ```
     */
    public function getRawBody(): string
    {
        if ($this->adapter !== null) {
            return $this->adapter->getRawBody();
        }

        return parent::getRawBody();
    }

    /**
     * Retrieves the script URL of the current request, supporting PSR-7 and Yii2 fallback.
     *
     * Returns the script URL as determined by the PSR-7 adapter if present, using the configured worker mode flag.
     *
     * If no adapter is set, falls back to the parent implementation.
     *
     * This method enables seamless access to the script URL in both PSR-7 and Yii2 environments, supporting
     * interoperability with modern HTTP stacks and legacy workflows.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return string Script URL of the current request.
     *
     * Usage example:
     * ```php
     * $scriptUrl = $request->getScriptUrl();
     * ```
     */
    public function getScriptUrl(): string
    {
        if ($this->adapter !== null) {
            return $this->adapter->getScriptUrl($this->workerMode);
        }

        return parent::getScriptUrl();
    }

    /**
     * Retrieves uploaded files from the current request, supporting PSR-7 and Yii2 fallback.
     *
     * Returns an array of uploaded files converted from the PSR-7 format if the adapter is set.
     *
     * If no adapter is present, an empty array is returned.
     *
     * This method enables seamless access to uploaded files in both PSR-7 and Yii2 environments, supporting
     * interoperability with modern HTTP stacks and legacy workflows.
     *
     * @return array Array of uploaded files for the current request.
     *
     * @phpstan-return array<array<UploadedFile>, mixed>
     *
     * Usage example:
     * ```php
     * $files = $request->getUploadedFiles();
     * ```
     */
    public function getUploadedFiles(): array
    {
        if ($this->adapter !== null) {
            return $this->convertPsr7ToUploadedFiles($this->adapter->getUploadedFiles());
        }

        return [];
    }

    /**
     * Retrieves the URL of the current request, supporting PSR-7 and Yii2 fallback.
     *
     * Returns the URL as determined by the PSR-7 adapter if present.
     *
     * If no adapter is set, falls back to the parent implementation.
     *
     * This method enables seamless access to the request URL in both PSR-7 and Yii2 environments, supporting
     * interoperability with modern HTTP stacks and legacy workflows.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return string URL of the current request.
     *
     * Usage example:
     * ```php
     * $url = $request->getUrl();
     * ```
     */
    public function getUrl(): string
    {
        if ($this->adapter !== null) {
            return $this->adapter->getUrl();
        }

        return parent::getUrl();
    }

    /**
     * Reset the PSR-7 ServerRequestInterface adapter to its initial state.
     *
     * Sets the internal adapter property to `null`, removing any previously set PSR-7 ServerRequestInterface adapter
     * and restoring the default behavior of the request component.
     *
     * This method is used to clear the PSR-7 bridge in worker mode, ensuring that subsequent request operations fall
     * back to the parent Yii2 implementation.
     *
     * Usage example:
     * ```php
     * $request->reset();
     * ```
     */
    public function reset(): void
    {
        $this->adapter = null;
    }

    /**
     * Sets the PSR-7 ServerRequestInterface instance for the current request.
     *
     * Assigns a new {@see ServerRequestAdapter} wrapping the provided PSR-7 {@see ServerRequestInterface} to enable
     * PSR-7 interoperability for the Yii2 Request component.
     *
     * This method is used to bridge PSR-7 compatible HTTP stacks with Yii2, allowing request data to be accessed via
     * the adapter.
     *
     * Once set, all request operations will use the PSR-7 ServerRequestInterface until {@see reset()} is called.
     *
     * @param ServerRequestInterface $request PSR-7 ServerRequestInterface instance to bridge.
     *
     * Usage example:
     * ```php
     * $psr7Request = new \GuzzleHttp\Psr7\ServerRequest('GET', '/api/resource');
     * $request->setPsr7Request($psr7Request);
     * ```
     */
    public function setPsr7Request(ServerRequestInterface $request): void
    {
        $this->adapter = new ServerRequestAdapter($request);
    }

    /**
     * Converts an array of PSR-7 UploadedFileInterface to Yii2 UploadedFile instances recursively.
     *
     * Iterates through the provided array of uploaded files, converting each {@see UploadedFileInterface} instance
     * to a Yii2 {@see UploadedFile} object.
     *
     * If a nested array is encountered, the method is called recursively to process all levels of the structure.
     *
     * This utility ensures compatibility between PSR-7 file upload structures and Yii2 expected format for handling
     * uploaded files, supporting both flat and nested file arrays as produced by HTML forms.
     *
     * @param array $uploadedFiles Array of uploaded files or nested arrays to convert.
     *
     * @return array Converted array of Yii2 UploadedFile instances, preserving keys and nesting.
     *
     * @phpstan-param array<mixed, mixed> $uploadedFiles Array of uploaded files or nested arrays to convert.
     *
     * @phpstan-return array<array<UploadedFile>, mixed>
     */
    private function convertPsr7ToUploadedFiles(array $uploadedFiles): array
    {
        $converted = [];

        foreach ($uploadedFiles as $key => $file) {
            if ($file instanceof UploadedFileInterface) {
                $converted[$key] = $this->createUploadedFile($file);
            } elseif (is_array($file)) {
                $converted[$key] = $this->convertPsr7ToUploadedFiles($file);
            }
        }

        return $converted;
    }

    /**
     * Creates a new {@see UploadedFile} instance from a PSR-7 UploadedFileInterface.
     *
     * Converts a {@see UploadedFileInterface} object to a Yii2 {@see UploadedFile} instance by extracting the error
     * code, client filename, file size, temporary file path, and media type from the PSR-7 UploadedFileInterface.
     *
     * This method is used internally to bridge PSR-7 UploadedFileInterface with Yii2 file upload handling, ensuring
     * compatibility between modern HTTP stacks and Yii2 expected file structure.
     *
     * @param UploadedFileInterface $psrFile PSR-7 UploadedFileInterface instance to convert.
     *
     * @return UploadedFile Yii2 UploadedFile instance created from the PSR-7 UploadedFileInterface.
     */
    private function createUploadedFile(UploadedFileInterface $psrFile): UploadedFile
    {
        return new UploadedFile(
            [
                'error' => $psrFile->getError(),
                'name' => $psrFile->getClientFilename() ?? '',
                'size' => $psrFile->getSize(),
                'tempName' => $psrFile->getStream()->getMetadata('uri') ?? '',
                'type' => $psrFile->getClientMediaType() ?? '',
            ],
        );
    }
}
