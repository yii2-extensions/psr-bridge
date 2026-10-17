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

use function array_map;
use function explode;
use function fopen;
use function implode;
use function is_string;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;

/**
 * Creates PSR-7 server requests from PHP globals.
 *
 * @phpstan-type UnknownFileInput array<mixed>
 * @phpstan-type FilesArray array<UploadedFileInterface|UnknownFileInput>
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
     * Builds a PSR request from the current SAPI globals and attaches a `php://input` body when available.
     *
     * Copies `HTTP_*` and CGI content headers, server parameters, cookies, query parameters, and nested uploads.
     * Nonempty `$_POST`, and empty `$_POST` for POST form or multipart requests, become the parsed body. Other
     * bodies stay `null` for configured parsers or the URL-encoded fallback. Input stream failures leave the
     * factory's original body unchanged.
     *
     * @return ServerRequestInterface Request created from the current globals.
     */
    public function createFromGlobals(): ServerRequestInterface
    {
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
            ? $_SERVER['REQUEST_METHOD']
            : 'GET';
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? $_SERVER['REQUEST_URI']
            : '/';

        // extract HTTP headers from $_SERVER
        $headers = $this->extractHeaders($_SERVER);

        $request = $this->serverRequestFactory->createServerRequest($method, $uri, $_SERVER);

        // manually add headers since PSR7 doesn't extract them automatically
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $contentType = $request->getHeaderLine('Content-Type');
        $separator = strpos($contentType, ';');
        $contentType = strtolower($separator === false ? $contentType : substr($contentType, 0, $separator));
        $parsedBody = $_POST !== []
            || (strtoupper($method) === 'POST' && in_array($contentType, ['application/x-www-form-urlencoded', 'multipart/form-data'], true))
            ? $_POST
            : null;

        $request = $request
            ->withCookieParams($_COOKIE)
            ->withParsedBody($parsedBody)
            ->withQueryParams($_GET);

        if ($_FILES !== []) {
            /** @var non-empty-array<UnknownFileInput|UploadedFileInterface> $files */
            $files = $_FILES;

            $uploadedFileCreator = new UploadedFileCreator($this->uploadedFileFactory, $this->streamFactory);

            /** @var array<array<mixed>|UploadedFileInterface> $uploadedFileFromGlobals */
            $uploadedFileFromGlobals = $uploadedFileCreator->createFromGlobals($files);
            $request = $request->withUploadedFiles($uploadedFileFromGlobals);
        }

        return $this->withBodyStream($request);
    }

    /**
     * Extracts HTTP headers from the provided server array in SAPI or worker environments.
     *
     * Iterates over the input server array and collects all entries whose keys start with HTTP_.
     *
     * Each header is normalized to standard HTTP header format using {@see normalizeHeaderName()} and added to the
     * result array.
     *
     * @param array<mixed> $server Input server array, typically $_SERVER globals.
     *
     * @return array<string, string> Array of normalized HTTP headers extracted from the server array.
     */
    private function extractHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (is_string($key) && is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headerName = $this->normalizeHeaderName(substr($key, 5));

                $headers[$headerName] = $value;
            }
        }

        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'] as $key) {
            if (isset($server[$key]) && is_string($server[$key])) {
                $headers[$this->normalizeHeaderName($key)] = $server[$key];
            }
        }

        return $headers;
    }

    /**
     * Normalizes a server header name to standard HTTP header format.
     *
     * Converts a header name from server variable format (for example, CONTENT_TYPE or HTTP_X_CUSTOM_HEADER) to
     * standard HTTP header format (for example, Content-Type or X-Custom-Header).
     *
     * This method replaces underscores with hyphens, lowercases the name, and capitalizes each segment for
     * compatibility with PSR-7 header expectations and interoperability with HTTP stacks.
     *
     * @param string $name Header name in server variable format (for example, CONTENT_TYPE).
     *
     * @return string Normalized HTTP header name (for example, Content-Type).
     */
    private function normalizeHeaderName(string $name): string
    {
        $name = str_replace('_', '-', strtolower($name));

        return implode('-', array_map(ucfirst(...), explode('-', $name)));
    }

    /**
     * Attaches the request body stream from `php://input` to the provided {@see ServerRequestInterface} instance.
     *
     * Attempts to open the `php://input` stream in read-only binary mode and, if successful, creates a PSR-7 stream
     * using the configured {@see StreamFactoryInterface}. The resulting stream is then attached to the request as the
     * body. If the stream cannot be opened or an exception occurs, the original request is returned unchanged.
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
