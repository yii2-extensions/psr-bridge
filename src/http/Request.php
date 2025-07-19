<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

use Psr\Http\Message\{ServerRequestInterface, UploadedFileInterface};
use yii\base\InvalidConfigException;
use yii\web\{CookieCollection, HeaderCollection};
use yii2\extensions\psrbridge\adapter\ServerRequestAdapter;

final class Request extends \yii\web\Request
{
    private CookieCollection|null $_cookies = null;
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

    public function getCookies()
    {
        if ($this->_cookies === null) {
            $cookies = $this->adapter !== null
                ? $this->adapter->getCookies($this->enableCookieValidation, $this->cookieValidationKey)
                : parent::loadCookies();

            $this->_cookies = new CookieCollection($cookies, ['readOnly' => true]);
        }

        return $this->_cookies;
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
            return $this->adapter->getMethodWithOverride($this->methodParam);
        }

        return parent::getMethod();
    }

    public function getParsedBody(): mixed
    {
        return $this->getAdapter()->getParsedBody();
    }

    public function getPsr7Request(): ServerRequestInterface
    {
        return $this->getAdapter()->psrRequest;
    }

    /**
     * @phpstan-return array<string, mixed>
     */
    public function getQueryParams(): array
    {
        if ($this->adapter !== null) {
            return $this->adapter->getQueryParams();
        }

        // @phpstan-ignore return.type
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
            return $this->adapter->getScriptUrl();
        }

        return parent::getScriptUrl();
    }

    /**
     * @return array<string, UploadedFileInterface|array<string, UploadedFileInterface>>
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

    /**
     * Reset para workers
     */
    public function reset(): void
    {
        $this->adapter = null;
        $this->_cookies = null;
    }

    /**
     * Establece la request PSR-7
     */
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
