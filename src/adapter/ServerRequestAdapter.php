<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\adapter;

use Psr\Http\Message\{ServerRequestInterface};
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
     * @phpstan-return array<Cookie>
     */
    public function getCookies(bool $enableValidation = false, string $validationKey = ''): array
    {
        return $enableValidation
            ? $this->getValidatedCookies($validationKey)
            : $this->getSimpleCookies();
    }

    public function getHeaders(): HeaderCollection
    {
        $headerCollection = new HeaderCollection();

        foreach ($this->psrRequest->getHeaders() as $name => $values) {
            $headerCollection->set((string) $name, implode(', ', $values));
        }

        return $headerCollection;
    }

    public function getMethod(string $methodParam = '_method'): string
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

        // check for 'X-Http-Method-Override' header
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

    public function getScriptUrl(bool $workerMode): string
    {
        $serverParams = $this->psrRequest->getServerParams();

        // for traditional 'PSR-7' apps where 'SCRIPT_NAME' is available
        if ($workerMode === false && isset($serverParams['SCRIPT_NAME']) && is_string($serverParams['SCRIPT_NAME'])) {
            return $serverParams['SCRIPT_NAME'];
        }

        // for 'PSR-7' workers (RoadRunner, FrankenPHP, etc.) where no script file exists
        // return empty to prevent URL duplication as routing is handled internally
        return '';
    }

    /**
     * @phpstan-return array<mixed, mixed>
     */
    public function getUploadedFiles(): array
    {
        return $this->psrRequest->getUploadedFiles();
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
     * @phpstan-return array<Cookie>
     */
    private function getValidatedCookies(string $validationKey): array
    {
        if ($validationKey === '') {
            throw new InvalidConfigException('Cookie validation key must be provided.');
        }

        $cookies = [];
        $cookieParams = $this->psrRequest->getCookieParams();

        foreach ($cookieParams as $name => $value) {
            if (is_string($value) && $value !== '') {
                $data = Yii::$app->getSecurity()->validateData($value, $validationKey);

                if (is_string($data) === false) {
                    continue;
                }

                $decodedData = Json::decode($data, true);

                if (is_array($decodedData) &&
                    isset($decodedData[0], $decodedData[1]) &&
                    $decodedData[0] === $name) {
                    $cookies[$name] = new Cookie(
                        [
                            'name' => $name,
                            'value' => $decodedData[1],
                            'expire' => null,
                        ],
                    );
                }
            }
        }

        return $cookies;
    }
}
