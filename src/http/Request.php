<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

use Psr\Http\Message\{ServerRequestInterface, UploadedFileInterface};
use yii\base\{InvalidArgumentException, InvalidConfigException};
use yii\web\{CookieCollection, HeaderCollection, UploadedFile};
use yii2\extensions\psrbridge\adapter\ServerRequestAdapter;
use yii2\extensions\psrbridge\exception\Message;

use function is_array;

final class Request extends \yii\web\Request
{
    public bool $workerMode = true;
    private ServerRequestAdapter|null $adapter = null;

    /**
     * @phpstan-return array<mixed, mixed>|object
     */
    public function getBodyParams(): array|object
    {
        if ($this->adapter !== null) {
            return $this->adapter->getBodyParams($this->methodParam);
        }

        return parent::getBodyParams();
    }

    public function getCookies(): CookieCollection
    {
        if ($this->adapter !== null) {
            $cookies = $this->adapter->getCookies($this->enableCookieValidation, $this->cookieValidationKey);

            return new CookieCollection($cookies, ['readOnly' => true]);
        }

        return parent::getCookies();
    }

    /**
     * @phpstan-ignore return.unusedType
     */
    public function getCsrfTokenFromHeader(): string|null
    {
        return $this->getHeaders()->get($this->csrfHeader);
    }

    public function getHeaders(): HeaderCollection
    {
        if ($this->adapter !== null) {
            $headers = $this->adapter->getHeaders();

            $this->filterHeaders($headers);

            return $headers;
        }

        return parent::getHeaders();
    }

    public function getMethod(): string
    {
        if ($this->adapter !== null) {
            return $this->adapter->getMethod($this->methodParam);
        }

        return parent::getMethod();
    }

    /**
     * @phpstan-return array<mixed, mixed>|object|null
     */
    public function getParsedBody(): array|object|null
    {
        if ($this->adapter !== null) {
            return $this->adapter->getParsedBody();
        }

        return parent::getBodyParams();
    }

    public function getPsr7Request(): ServerRequestInterface
    {
        if ($this->adapter === null) {
            throw new InvalidConfigException('PSR-7 request adapter is not set.');
        }

        return $this->adapter->psrRequest;
    }

    /**
     * @phpstan-return array<mixed, mixed>
     */
    public function getQueryParams(): array
    {
        if ($this->adapter !== null) {
            return $this->adapter->getQueryParams();
        }

        return parent::getQueryParams();
    }

    /**
     * Retrieves the query string from the current request.
     *
     * Returns the query string portion of the request URI, which contains parameters sent via GET method.
     *
     * When using PSR-7 adapter, the query string is extracted from the PSR-7 request URI. Otherwise, falls back
     * to the parent implementation which typically reads from $_SERVER['QUERY_STRING'].
     *
     * The query string includes all parameters after the '?' character in the URL, without the leading '?'.
     *
     * @return string Query string without leading '?' character, or empty string if no query parameters exist.
     *
     * Usage example:
     * ```php
     * $queryString = $request->getQueryString(); // Returns 'page=1&limit=10'
     * ```
     */
    public function getQueryString()
    {
        if ($this->adapter !== null) {
            return $this->adapter->getQueryString();
        }

        return parent::getQueryString();
    }

    public function getRawBody(): string
    {
        if ($this->adapter !== null) {
            return $this->adapter->getRawBody();
        }

        return parent::getRawBody();
    }

    public function getScriptUrl(): string
    {
        if ($this->adapter !== null) {
            return $this->adapter->getScriptUrl($this->workerMode);
        }

        return parent::getScriptUrl();
    }

    /**
     * @phpstan-return array<mixed, mixed>
     */
    public function getUploadedFiles(): array
    {
        if ($this->adapter !== null) {
            return $this->convertPsr7ToUploadedFiles($this->adapter->getUploadedFiles());
        }

        return [];
    }

    public function getUrl(): string
    {
        if ($this->adapter !== null) {
            return $this->adapter->getUrl();
        }

        return parent::getUrl();
    }

    public function reset(): void
    {
        $this->adapter = null;
    }

    public function setPsr7Request(ServerRequestInterface $request): void
    {
        $this->adapter = new ServerRequestAdapter($request);
    }

    /**
     * @phpstan-param array<mixed, mixed> $uploadedFiles
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

    private function createUploadedFile(UploadedFileInterface $psrFile): UploadedFile
    {
        return new UploadedFile(
            [
                'error' => $psrFile->getError(),
                'name' => $psrFile->getClientFilename() ?? '',
                'size' => $psrFile->getSize() ?? null,
                'tempName' => $psrFile->getStream()->getMetadata('uri') ?? '',
                'type' => $psrFile->getClientMediaType() ?? '',
            ],
        );
    }
}
