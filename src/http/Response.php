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

        if (Yii::$app->has('session') && ($session = Yii::$app->getSession())->getIsActive()) {
            $cookieParams = $session->getCookieParams();

            $cookieConfig = [
                'name' => $session->getName(),
                'value' => $session->getId(),
                'path' => $cookieParams['path'] ?? '/',
                'domain' => $cookieParams['domain'] ?? '',
                'sameSite' => $cookieParams['samesite'] ?? Cookie::SAME_SITE_LAX,
            ];

            if (isset($cookieParams['secure'])) {
                $cookieConfig['secure'] = $cookieParams['secure'];
            }

            if (isset($cookieParams['httponly'])) {
                $cookieConfig['httpOnly'] = $cookieParams['httponly'];
            }

            $this->cookies->add(new Cookie($cookieConfig));
            $session->close();
        }

        $response = $adapter->toPsr7();
        $this->trigger(self::EVENT_AFTER_SEND);

        $this->isSent = true;

        return $response;
    }
}
