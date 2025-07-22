<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\adapter;

use DateTimeInterface;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, StreamFactoryInterface};
use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\Json;
use yii\web\{Cookie, Response};
use yii2\extensions\psrbridge\exception\Message;

use function gmdate;
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
                    Message::COOKIE_VALIDATION_KEY_NOT_CONFIGURED->getMessage($request::class),
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
        $expire = $cookie->expire;

        if (is_numeric($expire)) {
            $expire = (int) $expire;
        }

        if (is_string($expire)) {
            $expire = (int) strtotime($expire);
        }

        if ($expire instanceof DateTimeInterface) {
            $expire = $expire->getTimestamp();
        }

        if (
            $enableValidation &&
            $validationKey !== null &&
            $expire !== 1 &&
            ($expire === 0 || $expire >= time())
        ) {
            $value = Yii::$app->getSecurity()->hashData(Json::encode([$cookie->name, $cookie->value]), $validationKey);
        }

        $header = urlencode($cookie->name) . '=' . urlencode($value);

        if ($expire !== null && $expire !== 0) {
            $expires = gmdate('D, d-M-Y H:i:s T', $expire);
            $maxAge = max(0, $expire - time());

            $header .= "; Expires={$expires}";
            $header .= "; Max-Age={$maxAge}";
        }

        $attributes = [
            'Path' => $cookie->path !== '' ? $cookie->path : null,
            'Domain' => $cookie->domain !== '' ? $cookie->domain : null,
            'Secure' => $cookie->secure ? 'Secure' : null,
            'HttpOnly' => $cookie->httpOnly ? '' : null,
            'SameSite' => $cookie->sameSite !== null ? $cookie->sameSite : null,
        ];

        foreach ($attributes as $key => $val) {
            if ($val !== null) {
                $header .= "; $key" . ($val !== '' ? "=$val" : '');
            }
        }

        return $header;
    }
}
