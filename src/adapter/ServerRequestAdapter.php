<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\adapter;

use Psr\Http\Message\{ServerRequestInterface, UploadedFileInterface};
use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\Json;
use yii\web\{Cookie, HeaderCollection};

final class ServerRequestAdapter
{
    public function __construct(public ServerRequestInterface $psrRequest) {}

    /**
     * @phpstan-return array<mixed, mixed>|object
     */
    public function getBodyParams(string $methodParam): array|object
    {
        $parsedBody = $this->psrRequest->getParsedBody();

        // remove method parameter if present (same logic as parent)
        if (is_array($parsedBody) && isset($parsedBody[$methodParam])) {
            $bodyParams = $parsedBody;

            unset($bodyParams[$methodParam]);

            return $bodyParams;
        }

        return $parsedBody ?? [];
    }

    /**
     * @phpstan-return array<mixed, mixed>
     */
    public function getCookieParams(): array
    {
        return $this->psrRequest->getCookieParams();
    }

    /**
     * @phpstan-return array<Cookie>
     */
    public function getCookies(bool $enableValidation = false, string $validationKey = ''): array
    {
        $cookies = [];
        $cookieParams = $this->psrRequest->getCookieParams();

        if ($enableValidation) {
            if ($validationKey === '') {
                throw new InvalidConfigException('Cookie validation key must be provided.');
            }

            foreach ($cookieParams as $name => $value) {
                if (is_string($value) && $value !== '') {
                    $data = Yii::$app->getSecurity()->validateData($value, $validationKey);

                    if (is_string($data) === false) {
                        continue;
                    }

                    $data = Json::decode($data, true);

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
        } else {
            foreach ($cookieParams as $name => $value) {
                if ($value === '') {
                    continue;
                }

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

    public function getHeaders(): HeaderCollection
    {
        $headerCollection = new HeaderCollection();

        foreach ($this->psrRequest->getHeaders() as $name => $values) {
            $headerCollection->set((string) $name, implode(', ', $values));
        }

        return $headerCollection;
    }

    public function getMethod(): string
    {
        return $this->psrRequest->getMethod();
    }

    public function getMethodWithOverride(string $methodParam = '_method'): string
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

        // check for X-Http-Method-Override header
        if ($this->psrRequest->hasHeader('X-Http-Method-Override')) {
            $overrideHeader = $this->psrRequest->getHeaderLine('X-Http-Method-Override');

            if ($overrideHeader !== '') {
                return strtoupper($overrideHeader);
            }
        }

        return $this->psrRequest->getMethod();
    }

    /**
     * @phpstan-return array<mixed, mixed>|object|null
     */
    public function getParsedBody(): array|object|null
    {
        return $this->psrRequest->getParsedBody();
    }

    /**
     * @phpstan-return array<mixed, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->psrRequest->getQueryParams();
    }

    public function getQueryString(): string
    {
        return $this->psrRequest->getUri()->getQuery();
    }

    public function getRawBody(): string
    {
        $body = $this->psrRequest->getBody();
        $body->rewind();

        return $body->getContents();
    }

    public function getScriptUrl(): string
    {
        $serverParams = $this->psrRequest->getServerParams();

        // for traditional PSR-7 apps where SCRIPT_NAME is available
        if (isset($serverParams['SCRIPT_NAME']) && is_string($serverParams['SCRIPT_NAME'])) {
            return $serverParams['SCRIPT_NAME'];
        }

        // for PSR-7 workers (RoadRunner, Franken, etc.) where no script file exists
        // return empty to prevent URL duplication as routing is handled internally
        return '';
    }

    /**
     * @phpstan-return array<mixed, mixed>
     */
    public function getServerParams(): array
    {
        return $this->psrRequest->getServerParams();
    }

    /**
     * @phpstan-return array<
     *   string,
     *   array{
     *     name: string|array<mixed>,
     *     type: string|array<mixed>,
     *     tmp_name: string|array<mixed>,
     *     error: int|array<mixed>,
     *     size: int|array<mixed>,
     *   }
     * >
     */
    public function getUploadedFiles(): array
    {
        /** @phpstan-var array<string, UploadedFileInterface|array<mixed>> $uploadedFiles */
        $uploadedFiles = $this->psrRequest->getUploadedFiles();
        return $this->normalizeUploadedFiles($uploadedFiles);
    }

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
     * @phpstan-return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private function convertSingleFile(UploadedFileInterface $file): array
    {
        $stream = $file->getStream();
        $streamMeta = $stream->getMetadata();
        $tempName = '';

        if (is_array($streamMeta) && isset($streamMeta['uri']) && is_string($streamMeta['uri'])) {
            $tempName = $streamMeta['uri'];

            // For in-memory streams, create a temporary file
            if (str_starts_with($tempName, 'php://')) {
                $tempFile = tempnam(sys_get_temp_dir(), 'upload');
                if ($tempFile !== false) {
                    $stream->rewind();
                    file_put_contents($tempFile, $stream->getContents());
                    $tempName = $tempFile;
                }
            }
        }

        return [
            'name' => $file->getClientFilename() ?? '',
            'type' => $file->getClientMediaType() ?? '',
            'tmp_name' => $tempName,
            'error' => $file->getError(),
            'size' => $file->getSize() ?? 0,
        ];
    }

    /**
     * @phpstan-param array<mixed> $fileArray
     *
     * @phpstan-return array{
     *   name: array<mixed>,
     *   type: array<mixed>,
     *   tmp_name: array<mixed>,
     *   error: array<mixed>,
     *   size: array<mixed>,
     * }
     */
    private function normalizeFileArray(array $fileArray): array
    {
        $names = [];
        $types = [];
        $tmpNames = [];
        $errors = [];
        $sizes = [];

        foreach ($fileArray as $key => $file) {
            if ($file instanceof UploadedFileInterface) {
                $converted = $this->convertSingleFile($file);
                $names[$key] = $converted['name'];
                $types[$key] = $converted['type'];
                $tmpNames[$key] = $converted['tmp_name'];
                $errors[$key] = $converted['error'];
                $sizes[$key] = $converted['size'];
            } elseif (is_array($file)) {
                // Nested array - recursively normalize
                $nestedNormalized = $this->normalizeFileArray($file);
                $names[$key] = $nestedNormalized['name'];
                $types[$key] = $nestedNormalized['type'];
                $tmpNames[$key] = $nestedNormalized['tmp_name'];
                $errors[$key] = $nestedNormalized['error'];
                $sizes[$key] = $nestedNormalized['size'];
            }
        }

        return [
            'name' => $names,
            'type' => $types,
            'tmp_name' => $tmpNames,
            'error' => $errors,
            'size' => $sizes,
        ];
    }

    /**
     * @phpstan-param array<string, UploadedFileInterface|array<mixed>> $uploadedFiles
     *
     * @phpstan-return array<
     *   string,
     *   array{
     *     name: string|array<mixed>,
     *     type: string|array<mixed>,
     *     tmp_name: string|array<mixed>,
     *     error: int|array<mixed>,
     *     size: int|array<mixed>,
     *   }
     * >
     */
    private function normalizeUploadedFiles(array $uploadedFiles): array
    {
        $normalized = [];

        foreach ($uploadedFiles as $fieldName => $fileData) {
            if ($fileData instanceof UploadedFileInterface) {
                // Single file
                $normalized[$fieldName] = $this->convertSingleFile($fileData);
            } elseif (is_array($fileData)) {
                // Multiple files or nested structure
                $normalized[$fieldName] = $this->normalizeFileArray($fileData);
            }
        }

        return $normalized;
    }
}
