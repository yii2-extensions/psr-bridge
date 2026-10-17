# Installation

## Dependencies

The package requires PHP 8.3+, the `filter` and `mbstring` extensions, PSR HTTP interfaces, and Yii
`~2.0.55 || ^22.0@dev`: Yii 2.0.55 or later within the 2.0 line, or Yii 22. Older Yii 2.0 releases are no longer
supported. The selected framework release may require a newer PHP version; check its resolved requirements.
Keep Composer's security advisory blocking enabled and run `composer audit` after resolving dependencies.

```bash
composer require "yii2-extensions/psr-bridge:^0.4" httpsoft/http-message
```

HttpSoft is the implementation used in these examples and in package tests. An existing compatible PSR-7/PSR-17
implementation can be reused. Register its factories as described in [configuration](configuration.md#factories).
The bridge depends on interfaces and does not select a production message implementation for you.

Check the installed version with `composer show yii2-extensions/psr-bridge` and follow [UPGRADE](../UPGRADE.md)
when upgrading.

## Choose the runtime

For a SAPI entry script, start with the [README example](../README.md#quick-start). For long-running runtimes,
the companion packages [FrankenPHP](https://github.com/yii2-extensions/franken-php) and
[RoadRunner](https://github.com/yii2-extensions/road-runner) supply the server integration. Install and configure the
chosen runner separately and check its lifecycle implementation against the bridge version in use.

A custom runtime supplies a PSR-7 server request, emits the returned response, and calls `Application::finalize()`.
The bridge does not start an HTTP server or implement the PSR-15 middleware pipeline itself.

Continue with [configuration](configuration.md), [practical examples](examples.md), or the
[agent skill installation](agents.md).
