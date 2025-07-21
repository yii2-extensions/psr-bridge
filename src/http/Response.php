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

        $session = Yii::$app->getSession();

        $this->cookies->add(
            new Cookie(
                [
                    'name' => $session->getName(),
                    'value' => $session->id,
                    'path' => ini_get('session.cookie_path'),
                ],
            ),
        );

        $session->close();

        $response = $adapter->toPsr7();

        $this->trigger(self::EVENT_AFTER_SEND);

        $this->isSent = true;

        return $response;
    }
}
