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
 * Creates PSR-7 and PSR-17 objects used by tests.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class HelperFactory
{
    /**
     * Creates a PSR-7 {@see ServerRequestInterface} instance.
     *
     * Usage example:
     * ```php
     * HelperFactory::createRequest($method, $uri, $headers, $parsedBody, $serverParams);
     * ```
     *
     * @param string $method Request method.
     * @param string $uri Request URI.
     * @param array $headers Request headers.
     * @param array|object|null $parsedBody Request parsed body.
     * @param array $serverParams Request server parameters.
     *
     * @return ServerRequestInterface PSR-7 server request instance.
     *
     * @phpstan-param array<string, array<int, string>|int|string> $headers
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
     * Usage example:
     * ```php
     * HelperFactory::createResponse($statusCode, $headers, $body, $protocol, $reasonPhrase);
     * ```
     *
     * @param int $statusCode Response status code.
     * @param array $headers Response headers.
     * @param resource|StreamInterface|string|null $body Response body.
     * @param string $protocol Response protocol version.
     * @param string $reasonPhrase Response reason phrase.
     *
     * @return ResponseInterface PSR-7 response instance.
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
     * Usage example:
     * ```php
     * HelperFactory::createResponseFactory();
     * ```
     *
     * @return ResponseFactoryInterface PSR-17 response factory instance.
     */
    public static function createResponseFactory(): ResponseFactoryInterface
    {
        return new ResponseFactory();
    }

    /**
     * Creates a PSR-17 {@see ServerRequestCreator} instance.
     *
     * Usage example:
     * ```php
     * HelperFactory::createServerRequestCreator();
     * ```
     *
     * @return ServerRequestCreator PSR-17 server request creator instance.
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
     * Usage example:
     * ```php
     * HelperFactory::createServerRequestFactory();
     * ```
     *
     * @return ServerRequestFactory PSR-17 server request factory instance.
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
     * Usage example:
     * ```php
     * HelperFactory::createStream($stream, $mode);
     * ```
     *
     * @return StreamInterface PSR-7 stream instance.
     */
    public static function createStream(string $stream = 'php://temp', string $mode = 'wb+'): StreamInterface
    {
        return new Stream($stream, $mode);
    }

    /**
     * Creates a PSR-17 {@see StreamFactoryInterface} instance.
     *
     * Usage example:
     * ```php
     * HelperFactory::createStreamFactory();
     * ```
     *
     * @return StreamFactoryInterface PSR-17 stream factory instance.
     */
    public static function createStreamFactory(): StreamFactoryInterface
    {
        return new StreamFactory();
    }

    /**
     * Creates a PSR-7 {@see UploadedFile} instance.
     *
     * Usage example:
     * ```php
     * HelperFactory::createUploadedFile($name, $type, $tmpName, $error, $size);
     * ```
     *
     * @param string $name Client filename.
     * @param string $type Client media type.
     * @param StreamInterface|string $tmpName Temporary file name or stream.
     * @param int $error Upload error code.
     * @param int $size File size.
     *
     * @return UploadedFileInterface PSR-7 uploaded file instance.
     */
    public static function createUploadedFile(
        string $name = '',
        string $type = '',
        string|StreamInterface $tmpName = '',
        int $error = 0,
        int $size = 0,
    ): UploadedFileInterface {
        return new UploadedFile($tmpName, $size, $error, $name, $type);
    }

    /**
     * Creates a PSR-17 {@see UploadedFileFactoryInterface} instance.
     *
     * Usage example:
     * ```php
     * HelperFactory::createUploadedFileFactory();
     * ```
     *
     * @return UploadedFileFactoryInterface PSR-17 uploaded file factory instance.
     */
    public static function createUploadedFileFactory(): UploadedFileFactoryInterface
    {
        return new UploadedFileFactory();
    }

    /**
     * Creates a PSR-7 {@see UriInterface} instance.
     *
     * Usage example:
     * ```php
     * HelperFactory::createUri($uri);
     * ```
     *
     * @param string $uri URI string.
     *
     * @return UriInterface PSR-7 URI instance.
     */
    public static function createUri(string $uri = ''): UriInterface
    {
        return new Uri($uri);
    }
}
