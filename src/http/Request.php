<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

use Psr\Http\Message\{ServerRequestInterface, UploadedFileInterface};
use yii\base\InvalidConfigException;
use yii\web\{CookieCollection, HeaderCollection};
use yii2\extensions\psrbridge\adapter\ServerRequestAdapter;

final class Request extends \yii\web\Request
{
    private ServerRequestAdapter|null $adapter = null;
    private bool $workerMode = true;

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

    public function getCsrfTokenFromHeader(): string|null
    {
        if ($this->adapter !== null) {
            return $this->getHeaders()->get($this->csrfHeader);
        }

        return parent::getCsrfTokenFromHeader();
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
        return $this->getAdapter()->getParsedBody();
    }

    public function getPsr7Request(): ServerRequestInterface
    {
        return $this->getAdapter()->psrRequest;
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
     * @return array<string, array<string, UploadedFileInterface>|UploadedFileInterface>
     */
    public function getUploadedFiles(): array
    {
        // @phpstan-ignore return.type
        return $this->getPsr7Request()->getUploadedFiles();
    }

    public function getUrl(): string
    {
        return $this->getAdapter()->getUrl();
    }

    public function reset(): void
    {
        $this->adapter = null;
    }

    public function setPsr7Request(ServerRequestInterface $request): void
    {
        $this->adapter = new ServerRequestAdapter($request);
    }

    private function getAdapter(): ServerRequestAdapter
    {
        if ($this->adapter === null) {
            throw new InvalidConfigException('PSR-7 request adapter is not set.');
        }

        return $this->adapter;
    }
}
