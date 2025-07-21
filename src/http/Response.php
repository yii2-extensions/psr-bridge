<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, StreamFactoryInterface};
use Yii;
use yii\web\Cookie;
use yii2\extensions\psrbridge\adapter\ResponseAdapter;

final class Response extends \yii\web\Response
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

        if (Yii::$app->has('session') === false) {
            $response = $adapter->toPsr7();

            $this->trigger(self::EVENT_AFTER_SEND);

            $this->isSent = true;

            return $response;
        }

        $session = Yii::$app->getSession();
        $cookieParams = $session->getCookieParams();

        if ($session->getIsActive()) {
            $this->cookies->add(
                new Cookie(
                    [
                        'name' => $session->getName(),
                        'value' => $session->getId(),
                        'path' => $cookieParams['path'] ?? '/',
                        'domain' => $cookieParams['domain'] ?? '',
                        'secure' => $cookieParams['secure'] ?? false,
                        'httpOnly' => $cookieParams['httponly'] ?? true,
                        'sameSite' => $cookieParams['samesite'] ?? null,
                    ],
                ),
            );

            $session->close();
        }

        $response = $adapter->toPsr7();

        $this->trigger(self::EVENT_AFTER_SEND);

        $this->isSent = true;

        return $response;
    }
}
