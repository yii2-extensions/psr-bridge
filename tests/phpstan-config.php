<?php

declare(strict_types=1);

use HttpSoft\Message\{ResponseFactory, StreamFactory};
use Psr\Http\Message\{ResponseFactoryInterface, StreamFactoryInterface};

return [
    'container' => [
        'definitions' => [
            ResponseFactoryInterface::class => ResponseFactory::class,
            StreamFactoryInterface::class => StreamFactory::class,
        ],
    ],
];
