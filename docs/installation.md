# Installation guide

## System requirements

- [`PHP`](https://www.php.net/downloads) 8.1 or higher
- [`Composer`](https://getcomposer.org/download/) for dependency management
- [`Yii2`](https://github.com/yiisoft/yii2) 2.0.53+ or 22.x

### PSR-7/PSR-17 HTTP Message Factories

Install exactly one of the following PSR-7/PSR-17 HTTP message implementations.

- [`guzzlehttp/psr7`](https://github.com/guzzle/psr7)
- [`httpsoft/http-message`](https://github.com/httpsoft/http-message)
- [`laminas/laminas-diactoros`](https://github.com/laminas/laminas-diactoros)
- [`nyholm/psr7`](https://github.com/Nyholm/psr7)

### Worker mode implementation (optional)

- [`yii2-extensions/franken-php`](https://github.com/yii2-extensions/franken-php)
- [`yii2-extensions/road-runner`](https://github.com/yii2-extensions/road-runner)

## Installation

### Method 1: Using [Composer](https://getcomposer.org/download/) (recommended)

Install the extension.

```bash
composer require yii2-extensions/psr-bridge:^0.3
```

### Method 2: Manual installation

Add to your `composer.json`.

```json
{
    "require": {
        "yii2-extensions/psr-bridge": "^0.3"
    }
}
```

Then run.

```bash
composer update
```

## Next steps

Once the installation is complete.

- ⚙️ [Configuration Reference](configuration.md)
- 💡 [Usage Examples](examples.md)
- 🧪 [Testing Guide](testing.md)
