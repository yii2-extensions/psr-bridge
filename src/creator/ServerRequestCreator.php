<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\creator;

use Psr\Http\Message\{
    ServerRequestFactoryInterface,
    ServerRequestInterface,
    StreamFactoryInterface,
    UploadedFileFactoryInterface,
    UploadedFileInterface,
};
use Throwable;

use function fopen;
use function is_string;

/**
 * PSR-7 ServerRequest creator for SAPI and worker environments.
 *
 * Provides a factory for creating {@see ServerRequestInterface} instances from PHP global variables, enabling seamless
 * interoperability between PSR-7 compatible HTTP stacks and Yii2 applications.
 *
 * This class delegates the creation of server requests, streams, and uploaded files to the provided PSR-7 factories,
 * supporting both traditional SAPI and worker-based environments.
 *
 * It ensures strict type safety and immutability throughout the request creation process.
 *
 * The creation process includes.
 * - Attaching the request body stream from 'php://input' for compatibility with raw payloads.
 * - Extracting HTTP method and URI from global variables.
 * - Handling uploaded files via {@see UploadedFileCreator} when present.
 * - Populating cookies, parsed body, and query parameters from PHP globals.
 *
 * Key features.
 * - Automatic handling of uploaded files and body streams.
 * - Designed for compatibility with SAPI and worker runtimes.
 * - Exception-safe body stream attachment.
 * - Immutable, type-safe request creation from PHP globals.
 * - PSR-7 factory integration for server requests, streams, and uploaded files.
 *
 * @phpstan-type UnknownFileInput array<mixed>
 * @phpstan-type FilesArray array<UploadedFileInterface|UnknownFileInput>
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class ServerRequestCreator
{
    /**
     * Creates a new instance of the {@see ServerRequestCreator} class.
     *
     * @param ServerRequestFactoryInterface $serverRequestFactory Factory to create server requests.
     * @param StreamFactoryInterface $streamFactory Factory to create streams.
     * @param UploadedFileFactoryInterface $uploadedFileFactory Factory to create uploaded files.
     */
    public function __construct(
        private readonly ServerRequestFactoryInterface $serverRequestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly UploadedFileFactoryInterface $uploadedFileFactory,
    ) {}

    /**
     * Creates a {@see ServerRequestInterface} instance from PHP global variables.
     *
     * Provides a factory method for building a PSR-7 server request using the current SAPI or worker environment
     * globals.
     *
     * This method extracts the HTTP method and URI from '$_SERVER¿, attaches cookies, parsed body, and query
     * parameters, and automatically handles uploaded files when present. The request body stream is attached from
     * 'php://input' for compatibility with raw payloads.
     *
     * @return ServerRequestInterface PSR-7 ServerRequestInterface created from PHP globals.
     *
     * Usage example:
     * ```php
     * $creator = new ServerRequestCreator($serverRequestFactory, $streamFactory, $uploadedFileFactory);
     * $request = $creator->createFromGlobals();
     * ```
     */
    public function createFromGlobals(): ServerRequestInterface
    {
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
            ? $_SERVER['REQUEST_METHOD']
            : 'GET';
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? $_SERVER['REQUEST_URI']
            : '/';

        $request = $this->serverRequestFactory
            ->createServerRequest($method, $uri, $_SERVER)
            ->withCookieParams($_COOKIE)
            ->withParsedBody($_POST)
            ->withQueryParams($_GET);

        /** @phpstan-var FilesArray $_FILES */
        if ($_FILES !== []) {
            $uploadedFileCreator = new UploadedFileCreator($this->uploadedFileFactory, $this->streamFactory);

            /** @phpstan-var array<array<mixed>|UploadedFileInterface> $uploadedFileFromGlobals */
            $uploadedFileFromGlobals = $uploadedFileCreator->createFromGlobals($_FILES);
            $request = $request->withUploadedFiles($uploadedFileFromGlobals);
        }

        return $this->withBodyStream($request);
    }

    /**
     * Attaches the request body stream from 'php://input' to the provided {@see ServerRequestInterface} instance.
     *
     * Attempts to open the 'php://input' stream in read-only binary mode and, if successful, creates a PSR-7 stream
     * using the configured {@see StreamFactoryInterface}. The resulting stream is then attached to the request as the
     * body. If the stream cannot be opened or an exception occurs, the original request is returned unchanged.
     *
     * This method ensures exception-safe and immutable attachment of the request body stream for compatibility with
     * raw payloads in SAPI and worker environments.
     *
     * @param ServerRequestInterface $request PSR-7 ServerRequestInterface instance to attach the body stream to.
     *
     * @return ServerRequestInterface ServerRequestInterface with the body stream attached, or the original request if
     * the stream cannot be opened.
     */
    private function withBodyStream(ServerRequestInterface $request): ServerRequestInterface
    {
        try {
            $handle = fopen('php://input', 'rb');

            if ($handle !== false) {
                $stream = $this->streamFactory->createStreamFromResource($handle);

                return $request->withBody($stream);
            }
        } catch (Throwable) {
        }

        return $request;
    }
}
