<?php

declare(strict_types=1);

use HttpSoft\Message\{ResponseFactory, StreamFactory};
use Psr\Http\Message\{ResponseFactoryInterface, StreamFactoryInterface};
use yii2\extensions\psrbridge\http\{ErrorHandler, Request, Response};
use yii2\extensions\psrbridge\tests\support\stub\Identity;

return [
    'components' => [
        'errorHandler' => [
            'class' => ErrorHandler::class,
        ],
        'request' => [
            'class' => Request::class,
        ],
        'response' => [
            'class' => Response::class,
        ],
        'user' => [
            'identityClass' => Identity::class,
        ],
    ],
    'container' => [
        'definitions' => [
            ResponseFactoryInterface::class => ResponseFactory::class,
            StreamFactoryInterface::class => StreamFactory::class,
        ],
    ],
];
