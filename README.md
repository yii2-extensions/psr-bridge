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

<svg fill="none" viewBox="0 0 1200 600" width="100%" height="600" xmlns="http://www.w3.org/2000/svg">
<foreignObject width="100%" height="100%">
<div xmlns="http://www.w3.org/1999/xhtml">
<style>
.container {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  padding: 20px;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
.alert-box {
  border-left: 4px solid #1f883d;
  padding: 16px;
  border-radius: 8px;
  background: rgba(31, 136, 61, 0.08);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}
.alert-title {
  margin: 0 0 10px 0;
  font-weight: 600;
  font-size: 16px;
  color: #000000;
}
.alert-content {
  margin: 0;
  color: #333333;
  line-height: 1.6;
  font-size: 14px;
}
@media (prefers-color-scheme: dark) {
  .alert-title { color: #f0f6fc; }
  .alert-content { color: #8b949e; }
  .alert-box { background: rgba(31, 136, 61, 0.15); }
}
</style>
<div class="container">
  <div class="alert-box">
    <p class="alert-title"><strong>Advanced Error Handling</strong></p>
    <p class="alert-content">Custom views • Debug mode • PSR-7 compatible responses</p>
  </div>
  <div class="alert-box">
    <p class="alert-title"><strong>Cookie & Session Management</strong></p>
    <p class="alert-content">Encrypted cookies • SameSite support • Session isolation</p>
  </div>
  <div class="alert-box">
    <p class="alert-title"><strong>PSR-7 Request/Response Bridge</strong></p>
    <p class="alert-content">Auto-conversion • Content-Range • Type safe responses</p>
  </div>  
  <div class="alert-box">
    <p class="alert-title"><strong>Smart File Upload Processing</strong></p>
    <p class="alert-content">Memory efficient • Multiple files • PSR-7 UploadedFileInterface</p>
  </div>
  <div class="alert-box">
    <p class="alert-title"><strong>Stateless Application Support</strong></p>
    <p class="alert-content">Memory cleanup • Event tracking • Request-scoped lifecycle</p>
  </div>
  <div class="alert-box">
    <p class="alert-title"><strong>Worker Mode Compatibility</strong></p>
    <p class="alert-content">RoadRunner • FrankenPHP • Zero state contamination</p>
  </div>
</div>
</div>
</foreignObject>
</svg>

## Available deployment options

### High-Performance Worker Mode

Long-running PHP workers for higher throughput and lower latency.

[![FrankenPHP](https://img.shields.io/badge/FrankenPHP-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://github.com/yii2-extensions/franken-php)
[![RoadRunner](https://img.shields.io/badge/RoadRunner-%23FF6B35.svg?style=for-the-badge&logo=data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZD0iTTEyIDJMMjIgMTJMMTIgMjJMMiAxMkwxMiAyWiIgZmlsbD0iI0ZGRkZGRiIvPgo8cGF0aCBkPSJNOCAyTDE2IDEwTDggMThaIiBmaWxsPSIjRkY2QjM1Ii8+CjxwYXRoIGQ9Ik0xNiA2TDIwIDEwTDE2IDE0WiIgZmlsbD0iI0ZGNkIzNSIvPgo8L3N2Zz4K&logoColor=white)](https://github.com/yii2-extensions/road-runner)

### Installation

```bash
composer require yii2-extensions/psr-bridge:^0.1
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

[![Latest Stable Version](https://img.shields.io/packagist/v/yii2-extensions/psr-bridge.svg?style=for-the-badge&logo=packagist&logoColor=white&label=Stable)](https://packagist.org/packages/yii2-extensions/psr-bridge)
[![Total Downloads](https://img.shields.io/packagist/dt/yii2-extensions/psr-bridge.svg?style=for-the-badge&logo=packagist&logoColor=white&label=Downloads)](https://packagist.org/packages/yii2-extensions/psr-bridge)

## Quality code

[![Codecov](https://img.shields.io/codecov/c/github/yii2-extensions/psr-bridge.svg?style=for-the-badge&logo=codecov&logoColor=white&label=Coverage)](https://codecov.io/github/yii2-extensions/psr-bridge)
[![PHPStan Level Max](https://img.shields.io/badge/PHPStan-Level%20Max-4F5D95.svg?style=for-the-badge&logo=php&logoColor=white)](https://github.com/yii2-extensions/psr-bridge/actions/workflows/static.yml)
[![StyleCI](https://img.shields.io/badge/StyleCI-Passed-44CC11.svg?style=for-the-badge&logo=styleci&logoColor=white)](https://github.styleci.io/repos/1019044094?branch=main)

## Our social networks

[![Follow on X](https://img.shields.io/badge/-Follow%20on%20X-1DA1F2.svg?style=for-the-badge&logo=x&logoColor=white&labelColor=000000)](https://x.com/Terabytesoftw)

## License

[![License](https://img.shields.io/badge/License-BSD--3--Clause-brightgreen.svg?style=for-the-badge&logo=opensourceinitiative&logoColor=white&labelColor=555555)](LICENSE)
