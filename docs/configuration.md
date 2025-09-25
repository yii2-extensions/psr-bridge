# Configuration reference

## Overview

This guide covers all configuration options for the PSR Bridge extension, from
basic setup to advanced HTTP message handling, worker mode integration, and
stateless application configuration.

## Basic configuration

### Core components setup

Replace the default Yii2 Request and Response components with PSR Bridge
enhanced versions.

```php
<?php

declare(strict_types=1);

use yii2\extensions\psrbridge\http\ErrorHandler;
use yii2\extensions\psrbridge\http\Request;
use yii2\extensions\psrbridge\http\Response;

return [
    'components' => [
        'request' => [
            'class' => Request::class,
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ],
        'response' => [
            'class' => Response::class,
            'enableCookieValidation' => false,
        ],
        'errorHandler' => [
            'class' => ErrorHandler::class,
        ],
    ],
];
```

## Error handling configuration

```php
<?php

declare(strict_types=1);

use yii2\extensions\psrbridge\http\ErrorHandler;

'errorHandler' => [
    'class' => ErrorHandler::class,
    'errorAction' => 'site/error',
    'discardExistingOutput' => true,
],
```

### Custom error views and formats

```php
<?php

declare(strict_types=1);

use yii2\extensions\psrbridge\http\ErrorHandler;

'errorHandler' => [
    'class' => ErrorHandler::class,
    'errorView' => '@app/views/error.php',
    'exceptionView' => '@app/views/exception.php',
    'callStackItemView' => '@app/views/callStackItem.php',
    'displayVars' => ['_GET', '_POST', '_FILES', '_COOKIE'],
],
```

### Memory management configuration

Configure memory limits and garbage collection behavior.

```php
<?php

declare(strict_types=1);

use yii2\extensions\psrbridge\http\StatelessApplication;

$app = new StatelessApplication($config);

// Enable logger flushing (default: true)
$app->flushLogger = true;

// Memory management example
if ($app->clean()) {
    // Memory usage exceeded 90% of limit
    // Trigger worker restart or cleanup
    exit(0);
}
```

### PSR-7 factory dependencies

Configure PSR-7 HTTP message factories for request/response conversion.

```php
<?php

declare(strict_types=1);

use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequestFactory;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UploadedFileFactory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;

'container' => [
    'definitions' => [
        ResponseFactoryInterface::class => ResponseFactory::class,
        ServerRequestFactoryInterface::class => ServerRequestFactory::class,
        StreamFactoryInterface::class => StreamFactory::class,
        UploadedFileFactoryInterface::class => UploadedFileFactory::class,
    ],
],
```

### StatelessApplication configuration

For worker-based environments (FrankenPHP, RoadRunner).

```php
<?php

declare(strict_types=1);

use yii2\extensions\psrbridge\http\Request;
use yii2\extensions\psrbridge\http\Response;
use yii2\extensions\psrbridge\http\StatelessApplication;

$config = [
    'id' => 'stateless-app',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'app\\controllers',
    'components' => [
        'request' => [
            'class' => Request::class,
            'workerMode' => true,
        ],
        'response' => [
            'class' => Response::class,
        ],
        // ... other components
    ],
];

$app = new StatelessApplication($config);
```

## Next steps

- 💡 [Usage Examples](examples.md)
- 🧪 [Testing Guide](testing.md)
