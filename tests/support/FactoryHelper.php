<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support;

use HttpSoft\Message\{
    Response,
    ResponseFactory,
    ServerRequest,
    ServerRequestFactory,
    Stream,
    StreamFactory,
    UploadedFile,
    UploadedFileFactory,
    Uri,
};
use Psr\Http\Message\{
    ResponseFactoryInterface,
    ResponseInterface,
    ServerRequestInterface,
    StreamFactoryInterface,
    StreamInterface,
    UploadedFileFactoryInterface,
    UploadedFileInterface,
    UriInterface,
};
use yii2\extensions\psrbridge\creator\ServerRequestCreator;

use function parse_str;

/**
 * Factory helper for creating test dependencies and PSR objects for unit testing.
 *
 * Provides a unified API for instantiating common PSR-7 object HTTP message objects.
 *
 * This class is designed to simplify test setup and ensure consistent, type-safe creation of dependencies across the
 * test suite.
 *
 * Key features.
 * - Create PSR-7 request, response, stream, and URI instances for HTTP message testing.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class FactoryHelper
{
    /**
     * Creates a PSR-7 {@see ServerRequestInterface} instance.
     *
     * @param string $method Request method.
     * @param string $uri Request URI.
     * @param array $headers Request headers.
     * @param array|object|null $parsedBody Request parsed body.
     * @param array $serverParams Request server parameters.
     *
     * @return ServerRequestInterface PSR-7 server request instance.
     *
     * Usage example:
     * ```php
     * FactoryHelper::createRequest($method, $uri, $headers, $parsedBody, $serverParams);
     * ```
     *
     * @phpstan-param array<string, int|string> $headers
     * @phpstan-param array<string, mixed> $serverParams
     * @phpstan-param array<string, mixed>|object|null $parsedBody
     */
    public static function createRequest(
        string $method = '',
        string $uri = '',
        array $headers = [],
        array|object|null $parsedBody = null,
        array $serverParams = [],
    ): ServerRequestInterface {
        $uriObject = new Uri($uri);

        $queryParams = [];

        parse_str($uriObject->getQuery(), $queryParams);

        return new ServerRequest(
            serverParams: $serverParams,
            queryParams: $queryParams,
            parsedBody: $parsedBody,
            method: $method,
            uri: $uriObject,
            headers: $headers,
        );
    }

    /**
     * Creates a PSR-7 {@see ResponseInterface} instance.
     *
     * @param int $statusCode Response status code.
     * @param array $headers Response headers.
     * @param resource|StreamInterface|string|null $body Response body.
     * @param string $protocol Response protocol version.
     * @param string $reasonPhrase Response reason phrase.
     *
     * @return ResponseInterface PSR-7 response instance.
     *
     * Usage example:
     * ```php
     * FactoryHelper::createResponse($statusCode, $headers, $body, $protocol, $reasonPhrase);
     * ```
     *
     * @phpstan-param array<string, array<int, string>|int|string> $headers
     */
    public static function createResponse(
        int $statusCode = 200,
        array $headers = [],
        $body = null,
        string $protocol = '1.1',
        string $reasonPhrase = '',
    ): ResponseInterface {
        $response = new Response($statusCode, $headers, $body, $protocol, $reasonPhrase);

        if ($body instanceof StreamInterface) {
            return $response->withBody($body);
        }

        if (is_string($body)) {
            $response->getBody()->write($body);
        }

        return $response;
    }

    /**
     * Creates a PSR-17 {@see ResponseFactoryInterface} instance.
     *
     * @return ResponseFactoryInterface PSR-17 response factory instance.
     *
     * Usage example:
     * ```php
     * FactoryHelper::createResponseFactory();
     * ```
     */
    public static function createResponseFactory(): ResponseFactoryInterface
    {
        return new ResponseFactory();
    }

    /**
     * Creates a PSR-17 {@see ServerRequestCreator} instance.
     *
     * @return ServerRequestCreator PSR-17 server request creator instance.
     *
     * Usage example:
     * ```php
     * FactoryHelper::createServerRequestCreator();
     * ```
     */
    public static function createServerRequestCreator(): ServerRequestCreator
    {
        return new ServerRequestCreator(
            self::createServerRequestFactory(),
            self::createStreamFactory(),
            self::createUploadedFileFactory(),
        );
    }

    /**
     * Creates a PSR-17 {@see ServerRequestFactory} instance.
     *
     * @return ServerRequestFactory PSR-17 server request factory instance.
     *
     * Usage example:
     * ```php
     * FactoryHelper::createServerRequestFactory();
     * ```
     */
    public static function createServerRequestFactory(): ServerRequestFactory
    {
        return new ServerRequestFactory();
    }

    /**
     * Creates a PSR-7 {@see StreamInterface} instance.
     *
     * @param string $stream Stream content.
     * @param string $mode Stream mode.
     *
     * @return StreamInterface PSR-7 stream instance.
     *
     * Usage example:
     * ```php
     * FactoryHelper::createStream($stream, $mode);
     * ```
     */
    public static function createStream(string $stream = 'php://temp', string $mode = 'wb+'): StreamInterface
    {
        return new Stream($stream, $mode);
    }

    /**
     * Creates a PSR-17 {@see StreamFactoryInterface} instance.
     *
     * @return StreamFactoryInterface PSR-17 stream factory instance.
     *
     * Usage example:
     * ```php
     * FactoryHelper::createStreamFactory();
     * ```
     */
    public static function createStreamFactory(): StreamFactoryInterface
    {
        return new StreamFactory();
    }

    /**
     * Creates a PSR-7 {@see UploadedFile} instance.
     *
     * @param string $name Client filename.
     * @param string $type Client media type.
     * @param string $tmpName Temporary file name.
     * @param int $error Upload error code.
     * @param int $size File size.
     *
     * @return UploadedFileInterface PSR-7 uploaded file instance.
     *
     * Usage example:
     * ```php
     * FactoryHelper::createUploadedFile($name, $type, $tmpName, $error, $size);
     * ```
     */
    public static function createUploadedFile(
        string $name = '',
        string $type = '',
        string $tmpName = '',
        int $error = 0,
        int $size = 0,
    ): UploadedFileInterface {
        return new UploadedFile($tmpName, $size, $error, $name, $type);
    }

    /**
     * Creates a PSR-17 {@see UploadedFileFactoryInterface} instance.
     *
     * @return UploadedFileFactoryInterface PSR-17 uploaded file factory instance.
     *
     * Usage example:
     * ```php
     * FactoryHelper::createUploadedFileFactory();
     * ```
     */
    public static function createUploadedFileFactory(): UploadedFileFactoryInterface
    {
        return new UploadedFileFactory();
    }

    /**
     * Creates a PSR-7 {@see UriInterface} instance.
     *
     * @param string $uri URI string.
     *
     * @return UriInterface PSR-7 URI instance.
     *
     * Usage example:
     * ```php
     * FactoryHelper::createUri($uri);
     * ```
     */
    public static function createUri(string $uri = ''): UriInterface
    {
        return new Uri($uri);
    }
}
