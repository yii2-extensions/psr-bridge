<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, StreamFactoryInterface};
use Yii;
use yii\web\Cookie;
use yii\web\Response as BaseResponse;
use yii2\extensions\psrbridge\adapter\ResponseAdapter;

final class Response extends BaseResponse
{
    public function getPsr7Response(): ResponseInterface
    {
        $adapter = new ResponseAdapter(
            $this,
            Yii::$container->get(ResponseFactoryInterface::class),
            Yii::$container->get(StreamFactoryInterface::class),
        );

        $this->trigger(self::EVENT_BEFORE_SEND);
        $this->prepare();
        $this->trigger(self::EVENT_AFTER_PREPARE);

        $session = Yii::$app->getSession();
        $sessionCookie = $session->getCookieParams();

        if ($session->getIsActive()) {
            $this->cookies->add(
                new Cookie(
                    [
                        'name' => $session->getName(),
                        'value' => $session->id,
                        'path' => $sessionCookie['path'] ?? '/',
                        'domain' => $sessionCookie['domain'] ?? '',
                        'secure' => $sessionCookie['secure'] ?? false,
                        'httpOnly' => $sessionCookie['httponly'] ?? true,
                        'sameSite' => $sessionCookie['samesite'] ?? null,
                    ],
                ),
            );
        }

        $session->close();

        $response = $adapter->toPsr7();

        $this->trigger(self::EVENT_AFTER_SEND);

        $this->isSent = true;

        return $response;
    }
}
