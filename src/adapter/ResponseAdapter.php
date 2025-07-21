<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\adapter;

use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, StreamFactoryInterface};
use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\Json;
use yii\web\{Cookie, Response};

use function get_class;
use function gmdate;
use function is_int;
use function max;
use function time;
use function urlencode;

final class ResponseAdapter
{
    public function __construct(
        private Response $response,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function toPsr7(): ResponseInterface
    {
        // Create base response
        $psr7Response = $this->responseFactory->createResponse(
            $this->response->getStatusCode(),
            $this->response->statusText,
        );

        // Add headers
        foreach ($this->response->getHeaders() as $name => $values) {
            // @phpstan-ignore-next-line
            $psr7Response = $psr7Response->withHeader($name, $values);
        }

        // Add cookies with proper formatting
        foreach ($this->buildCookieHeaders() as $cookieHeader) {
            $psr7Response = $psr7Response->withAddedHeader('Set-Cookie', $cookieHeader);
        }

        // Add body
        $body = $this->streamFactory->createStream($this->response->content ?? '');
        return $psr7Response->withBody($body);
    }

    /**
     * Build cookie headers with proper formatting and validation
     *
     * @phpstan-return string[] Array of formatted cookie headers.
     */
    private function buildCookieHeaders(): array
    {
        $headers = [];
        $request = Yii::$app->getRequest();

        // Check if cookie validation is enabled
        $enableValidation = $request->enableCookieValidation;
        $validationKey = null;

        if ($enableValidation) {
            $validationKey = $request->cookieValidationKey;

            if ($validationKey === '') {
                throw new InvalidConfigException(
                    get_class($request) . '::cookieValidationKey must be configured with a secret key.',
                );
            }
        }

        foreach ($this->response->getCookies() as $cookie) {
            // Skip cookies with empty values
            if ($cookie->value !== null && $cookie->value !== '') {
                $headers[] = $this->formatCookieHeader($cookie, $enableValidation, $validationKey);
            }
        }

        return $headers;
    }

    private function formatCookieHeader(Cookie $cookie, bool $enableValidation, string|null $validationKey): string
    {
        $value = $cookie->value;

        // apply validation if enabled and not a delete cookie
        if (
            $enableValidation &&
            $validationKey !== null &&
            ($cookie->value === '' || ($cookie->expire !== 0 && $cookie->expire < time()))
        ) {
            $value = Yii::$app->getSecurity()->hashData(Json::encode([$cookie->name, $cookie->value]), $validationKey);
        }

        // build cookie header
        $header = urlencode($cookie->name) . '=' . urlencode($value);

        // add expiration
        if (is_int($cookie->expire) && $cookie->expire !== 0) {
            $expires = gmdate('D, d-M-Y H:i:s T', $cookie->expire);
            $maxAge = max(0, $cookie->expire - time());

            $header .= "; Expires={$expires}";
            $header .= "; Max-Age={$maxAge}";
        }

        // add path
        if ($cookie->path !== '') {
            $header .= "; Path={$cookie->path}";
        }

        // add domain
        if ($cookie->domain !== '') {
            $header .= "; Domain={$cookie->domain}";
        }

        // Add secure flag
        if ($cookie->secure) {
            $header .= '; Secure';
        }

        // add httpOnly flag
        if ($cookie->httpOnly) {
            $header .= '; HttpOnly';
        }

        // add sameSite attribute
        if ($cookie->sameSite !== null) {
            $header .= "; SameSite={$cookie->sameSite}";
        }

        return $header;
    }
}
