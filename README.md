<!-- markdownlint-disable MD041 -->
<p align="center">
    <a href="https://github.com/yii2-extensions/psr-bridge" target="_blank">
        <img src="https://www.yiiframework.com/image/yii_logo_light.svg" alt="Yii Framework">
    </a>
    <h1 align="center">PSR bridge</h1>
    <br>
</p>
<!-- markdownlint-enable MD041 -->

<p align="center">
    <a href="https://www.php.net/releases/8.1/en.php" target="_blank">
        <img src="https://img.shields.io/badge/%3E%3D8.1-777BB4.svg?style=for-the-badge&logo=php&logoColor=white" alt="PHP version">
    </a>
    <a href="https://github.com/yiisoft/yii2/tree/2.0.53" target="_blank">
        <img src="https://img.shields.io/badge/2.0.x-0073AA.svg?style=for-the-badge&logo=yii&logoColor=white" alt="Yii 2.0.x">
    </a>
    <a href="https://github.com/yiisoft/yii2/tree/22.0" target="_blank">
        <img src="https://img.shields.io/badge/22.0.x-0073AA.svg?style=for-the-badge&logo=yii&logoColor=white" alt="Yii 22.0.x">
    </a>
    <a href="https://github.com/yii2-extensions/psr-bridge/actions/workflows/build.yml" target="_blank">
        <img src="https://img.shields.io/github/actions/workflow/status/yii2-extensions/psr-bridge/build.yml?style=for-the-badge&label=PHPUnit" alt="PHPUnit">
    </a>
    <a href="https://github.com/yii2-extensions/psr-bridge/actions/workflows/static.yml" target="_blank">
        <img src="https://img.shields.io/github/actions/workflow/status/yii2-extensions/psr-bridge/static.yml?style=for-the-badge&label=PHPStan" alt="PHPStan">
    </a>
</p>

A comprehensive PSR bridge that enables seamless integration between Yii2
applications and modern PHP runtimes, supporting both traditional SAPI and
high-performance worker modes.

## Features

✅ **Cookie & Session Management**

- Cookie encryption and validation key support.
- Per-request session isolation.
- SameSite cookie attribute support.
- Secure cookie validation with Yii2 compatibility.
- Session cookie injection and management.

✅ **Error Handling**

- Custom error views and actions.
- Debug mode with detailed error information.
- Exception conversion to ResponseInterface.
- Fallback error handling for nested exceptions.
- PSR-7 compatible error responses.

✅ **File Upload Processing**

- Memory-efficient large file handling.
- Multiple file upload support.
- Nested file array handling.
- PSR-7 UploadedFileInterface support.
- Stream-based file processing.

✅ **PSR-7 Request/Response Bridge**

- Automatic conversion between Yii2 and PSR-7 HTTP messages.
- Content-Range support for partial responses.
- Full compatibility with PSR-7 ServerRequestInterface.
- Stream handling for large file downloads.
- Type-safe response adaptation with proper status codes.

✅ **Stateless Application Support**

- Automatic memory cleanup and garbage collection.
- Event tracking and cleanup per request.
- Request-scoped lifecycle management.
- StatelessApplication class for worker environments.

✅ **Worker Mode Compatibility**

- Efficient memory management with configurable limits.
- File upload handling without `$_FILES` manipulation.
- Native support for RoadRunner, FrankenPHP, and similar runtimes.
- Session isolation per request.
- Zero global state contamination between requests.

### Installation

```bash
composer require yii2-extensions/psr-bridge:^0.1@dev
```

### Quick start

#### Worker mode (FrankenPHP)

```php
<?php

declare(strict_types=1);

// disable PHP automatic session cookie handling
ini_set('session.use_cookies', '0');

require_once dirname(__DIR__) . '/vendor/autoload.php';

use yii2\extensions\frankenphp\FrankenPHP;
use yii2\extensions\psrbridge\http\StatelessApplication;

// Load environment variables from .env file
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

// production default (change to 'true' for development)
define('YII_DEBUG', $_ENV['YII_DEBUG'] ?? false);
// production default (change to 'dev' for development)
define('YII_ENV', $_ENV['YII_ENV'] ?? 'prod');

require_once dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

$config = require_once dirname(__DIR__) . '/config/web/app.php';

$runner = new FrankenPHP(new StatelessApplication($config));

$runner->run();
```

#### Worker mode (RoadRunner)

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use yii2\extensions\psrbridge\http\StatelessApplication;
use yii2\extensions\roadrunner\RoadRunner;

define('YII_DEBUG', getenv('YII_DEBUG') ?? false);
define('YII_ENV', getenv('YII_ENV') ?? 'prod');

require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

$config = require dirname(__DIR__) . '/config/web/app.php';

$runner = new RoadRunner(new StatelessApplication($config));

$runner->run();
```

#### PSR-7 Conversion

```php
// Convert Yii2 request to PSR-7
$request = Yii::$app->request;
$psr7Request = $request->getPsr7Request();

// Convert Yii2 response to PSR-7
$response = Yii::$app->response;
$psr7Response = $response->getPsr7Response();

// Emit PSR-7 response
$emitter = new yii2\extensions\psrbridge\emitter\SapiEmitter();
$emitter->emit($psr7Response);
```

## Documentation

For detailed configuration options and advanced usage.

- 📚 [Installation Guide](docs/installation.md)
- ⚙️ [Configuration Reference](docs/configuration.md)
- 💡 [Usage Examples](docs/examples.md)
- 🧪 [Testing Guide](docs/testing.md)

## Package information

[![Development Status](https://img.shields.io/badge/Status-Dev-orange.svg?style=for-the-badge&logo=packagist&logoColor=white)](https://packagist.org/packages/yii2-extensions/psr-bridge)
[![Total Downloads](https://img.shields.io/packagist/dt/yii2-extensions/psr-bridge.svg?style=for-the-badge&logo=packagist&logoColor=white&label=Downloads)](https://packagist.org/packages/yii2-extensions/psr-bridge)

## Quality code

[![Codecov](https://img.shields.io/codecov/c/github/yii2-extensions/psr-bridge.svg?style=for-the-badge&logo=codecov&logoColor=white&label=Coverage)](https://codecov.io/github/yii2-extensions/psr-bridge)
[![PHPStan Level Max](https://img.shields.io/badge/PHPStan-Level%20Max-4F5D95.svg?style=for-the-badge&logo=php&logoColor=white)](https://github.com/yii2-extensions/psr-bridge/actions/workflows/static.yml)
[![StyleCI](https://img.shields.io/badge/StyleCI-Passed-44CC11.svg?style=for-the-badge&logo=styleci&logoColor=white)](https://github.styleci.io/repos/1019044094?branch=main)

## Our social networks

[![Follow on X](https://img.shields.io/badge/-Follow%20on%20X-1DA1F2.svg?style=for-the-badge&logo=x&logoColor=white&labelColor=000000)](https://x.com/Terabytesoftw)

## License

[![License](https://img.shields.io/badge/License-BSD--3--Clause-brightgreen.svg?style=for-the-badge&logo=opensourceinitiative&logoColor=white&labelColor=555555)](LICENSE)
