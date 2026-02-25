# Configuration reference

## Overview

This guide covers all configuration options for the PSR Bridge extension, from
basic setup to advanced HTTP message handling, worker mode integration, and
application configuration.

## Basic configuration

### Core components setup

Replace the default Yii2 Request and Response components with PSR Bridge
enhanced versions.

To enable automatic body parsing (for example, for JSON APIs), configure
the `parsers` property.

```php
<?php

declare(strict_types=1);

use yii2\extensions\psrbridge\http\{ErrorHandler, Request, Response};
use yii\web\{JsonParser, MultipartFormDataParser};

return [
    'components' => [
        'request' => [
            'class' => Request::class,
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
            'parsers' => [
                'application/json' => JsonParser::class,
                'multipart/form-data' => MultipartFormDataParser::class,
            ],
        ],
        'response' => [
            'class' => Response::class,
        ],
        'errorHandler' => [
            'class' => ErrorHandler::class,
        ],
    ],
];
```

### Request body parsing

The `Request` component includes built-in logic to handle PSR-7 body parsing automatically.

When `setPsr7Request()` is called (typically by the worker runner), the bridge will:

1. Detect the `Content-Type` of the incoming PSR-7 request.
2. Check the `parsers` configuration for a matching parser.
3. Parse the body content.
4. Update the PSR-7 Request instance using `withParsedBody()`.

This ensures that `Yii::$app->request->post()` and `Yii::$app->request->bodyParams` are correctly
populated immediately after the request is handled.

#### Wildcard parsing

You can also configure a fallback parser using the `*` wildcard.

```php
'request' => [
    'class' => Request::class,
    'parsers' => [
        'application/json' => JsonParser::class,
        // Use JSON parser for any content type not explicitly defined above
        '*' => JsonParser::class,
    ],
],
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

use yii2\extensions\psrbridge\http\Application;

$app = new Application($config);

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

use HttpSoft\Message\{ResponseFactory, ServerRequestFactory, StreamFactory, UploadedFileFactory};
use Psr\Http\Message\{
    ResponseFactoryInterface,
    ServerRequestFactoryInterface,
    StreamFactoryInterface,
    UploadedFileFactoryInterface,
};

'container' => [
    'definitions' => [
        ResponseFactoryInterface::class => ResponseFactory::class,
        ServerRequestFactoryInterface::class => ServerRequestFactory::class,
        StreamFactoryInterface::class => StreamFactory::class,
        UploadedFileFactoryInterface::class => UploadedFileFactory::class,
    ],
],
```

### Application configuration

For worker-based environments (FrankenPHP, RoadRunner).

```php
<?php

declare(strict_types=1);

use yii2\extensions\psrbridge\http\{Application, Request, Response};

$config = [
    'id' => 'app',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'app\\controllers',
    'components' => [
        'request' => [
            'class' => Request::class,
        ],
        'response' => [
            'class' => Response::class,
        ],
        // ... other components
    ],
];

$app = new Application($config);
```

### Worker lifecycle flags

`yii2\extensions\psrbridge\http\Application` provides lifecycle flags to tune behavior in long-running workers.

```php
<?php

declare(strict_types=1);

use yii2\extensions\psrbridge\http\Application;

$app = new Application($config);

// Recommended defaults for worker mode
$app->useSession = true;
$app->syncCookieValidation = true;
$app->resetUploadedFiles = true;

// Reinitialize only request-scoped components each request
$app->requestScopedComponents = ['request', 'response', 'errorHandler', 'session', 'user', 'urlManager'];
```

- `useSession=false` disables bridge session lifecycle hooks, but your application may still open sessions through other components.
- `syncCookieValidation=false` disables request-to-response cookie validation synchronization and can break flows that expect matching cookie validation settings.
- `resetUploadedFiles=false` is an advanced option and may leak static uploaded-file state between requests in long-running workers.
- `requestScopedComponents` controls which component IDs are reinitialized per request. Components not listed preserve their loaded instances between requests.
- `container.definitions` and `container.singletons` are applied to `Yii::$container` once per worker lifecycle and are not reapplied on each request.

Do not disable request cookie or uploaded-file access globally. `Request::getCookies()` and `Request::getUploadedFiles()` are input adapters and are expected to remain available in PSR-7 mode.

## Next steps

- 💡 [Usage Examples](examples.md)
- 🧪 [Testing Guide](testing.md)
