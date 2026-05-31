# Usage examples

This document provides comprehensive examples of how to use the PSR Bridge
extension in real-world Yii2 applications, from basic HTTP message handling to
advanced worker mode integration.

## Basic PSR-7 conversion

### Converting Yii2 Request to PSR-7

```php
<?php

declare(strict_types=1);

use yii2\extensions\psrbridge\http\Request;

// In your controller
public function actionApi()
{
    /** @var Request $request */
    $request = Yii::$app->request;

    // Get PSR-7 ServerRequestInterface
    $psr7Request = $request->getPsr7Request();

    // Access PSR-7 methods
    $method = $psr7Request->getMethod();
    $uri = $psr7Request->getUri();
    $headers = $psr7Request->getHeaders();
    $body = $psr7Request->getBody()->getContents();

    return [
        'method' => $method,
        'path' => $uri->getPath(),
        'headers' => $headers,
        'body' => $body,
    ];
}
```

### Converting Yii2 Response to PSR-7

```php
<?php

declare(strict_types=1);

use yii2\extensions\psrbridge\emitter\SapiEmitter;
use yii2\extensions\psrbridge\http\Response;

public function actionDownload()
{
    /** @var Response $response */
    $response = Yii::$app->response;

    // Set response data
    $response->setStatusCode(200);
    $response->headers->set('Content-Type', 'application/json');
    $response->content = json_encode(['message' => 'Hello World']);

    // Convert to PSR-7 ResponseInterface
    $psr7Response = $response->getPsr7Response();

    // Use with PSR-7 compatible tools
    $emitter = new SapiEmitter();
    $emitter->emit($psr7Response);
}
```

### Development & Debugging

For enhanced debugging capabilities and proper time display in RoadRunner,
install the worker debug extension.

```bash
composer require --dev yii2-extensions/worker-debug:^0.1
```

Add the following to your development configuration (`config/web.php`):

```php
<?php

declare(strict_types=1);

use yii2\extensions\debug\WorkerDebugModule;

if (YII_ENV_DEV) {
    // configuration adjustments for 'dev' environment
    $config['bootstrap'][] = 'debug';
    $config['modules']['debug'] = [
        'class' => WorkerDebugModule::class,
        // uncomment the following to add your IP if you are not connecting from localhost.
        //'allowedIPs' => ['127.0.0.1', '::1'],
    ];
}
```

### File Upload Handling

For enhanced file upload support in worker environments, use the PSR-7 bridge
UploadedFile class instead of the standard Yii2 implementation.

```php
<?php

declare(strict_types=1);

use yii2\extensions\psrbridge\http\{Response, UploadedFile};

final class FileController extends \yii\web\Controller
{
    public function actionUpload(): Response
    {
        $file = UploadedFile::getInstanceByName('avatar');

        if ($file !== null && $file->error === UPLOAD_ERR_OK) {
            $safeName = sprintf('%s_%s', Yii::$app->security->generateRandomString(8), basename((string) $file->name));
            $file->saveAs('@webroot/uploads/' . $safeName);
        }

        return $this->asJson(['status' => 'uploaded']);
    }
}
```

## Worker mode integration

### FrankenPHP

```php
<?php

declare(strict_types=1);

// disable PHP automatic session cookie handling
ini_set('session.use_cookies', '0');

require_once dirname(__DIR__) . '/vendor/autoload.php';

use yii2\extensions\frankenphp\FrankenPHP;
use yii2\extensions\psrbridge\http\Application;

// Load environment variables from .env file
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

// production default (change to 'true' for development)
define('YII_DEBUG', filter_var($_ENV['YII_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN));
// production default (change to 'dev' for development)
define('YII_ENV', $_ENV['YII_ENV'] ?? 'prod');

require_once dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

$config = require_once dirname(__DIR__) . '/config/web/app.php';

$runner = new FrankenPHP(new Application($config));

$runner->run();
```

### RoadRunner

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use yii2\extensions\psrbridge\http\Application;
use yii2\extensions\roadrunner\RoadRunner;

define('YII_DEBUG', filter_var(getenv('YII_DEBUG'), FILTER_VALIDATE_BOOLEAN));
define('YII_ENV', getenv('YII_ENV') ?? 'prod');

require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

$config = require dirname(__DIR__) . '/config/web/app.php';

$runner = new RoadRunner(new Application($config));

$runner->run();
```

### Lifecycle flags for long-running workers

```php
<?php

declare(strict_types=1);

use yii2\extensions\psrbridge\http\Application;

$app = new Application($config);

// Keep defaults unless you have a specific need.
$app->useSession = true;
$app->syncCookieValidation = true;
$app->resetUploadedFiles = true;
```

Use non-default values only for advanced scenarios:

- `useSession=false` skips bridge session hooks only.
- `syncCookieValidation=false` skips cookie validation synchronization between request and response.
- Keep `resetUploadedFiles=true` to preserve per-request uploaded-file isolation in worker deployments.

## Response lifecycle finalization

`Application::handle()` runs the request and returns a PSR-7 response, but it intentionally stops at the **pre-send**
phase: it triggers `EVENT_BEFORE_SEND`, prepares the body, and triggers `EVENT_AFTER_PREPARE`. It does **not** trigger
`EVENT_AFTER_SEND` or mark the Yii response as sent, because the runtime emits the body afterward and lazy file streams
(`sendFile()`, `sendStreamAsFile()`) must still be readable at that point.

After the response has been emitted, call `Application::finalize()` to run the **post-send** phase: it triggers
`EVENT_AFTER_SEND`, marks the response sent, and cleans up per-request event handlers so long-running workers stay
isolated across requests. Pass `false` when emission failed so after-send is skipped (the response was not delivered)
while worker cleanup still runs.

The official runners (FrankenPHP, RoadRunner) call this for you. When you drive the bridge yourself, you **must** call
it after emitting; otherwise after-send handlers (temporary file cleanup, audit/metrics logging, per-request state
reset) never run and worker state leaks across requests.

```php
<?php

declare(strict_types=1);

use yii2\extensions\psrbridge\emitter\SapiEmitter;
use yii2\extensions\psrbridge\http\Application;

$app = new Application($config);

$psr7Response = $app->handle($psr7Request);

try {
    (new SapiEmitter())->emit($psr7Response);

    // finalize: triggers EVENT_AFTER_SEND, marks the response sent, cleans up worker event state
    $app->finalize();
} catch (\Throwable $e) {
    // emission failed: skip after-send, but still clean up worker state
    $app->finalize(false);

    throw $e;
}
```

## Next steps

- 📚 [Installation Guide](installation.md)
- ⚙️ [Configuration Guide](configuration.md)
- 🧪 [Testing Guide](testing.md)
