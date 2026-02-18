# Upgrade Guide

## 0.2.0

### Breaking changes

- `yii2\extensions\psrbridge\http\StatelessApplication` was renamed to `yii2\extensions\psrbridge\http\Application`.
- No compatibility alias is provided for `StatelessApplication`; all imports and type hints must be updated.
- `yii2\extensions\psrbridge\http\Application::reset()` was renamed to `prepareForRequest()`.
- `yii2\extensions\psrbridge\http\Request::$workerMode` was removed.

### Migration steps

#### 1) Update custom `StatelessApplication` subclasses

Replace imports and instantiation sites:

```php
use yii2\extensions\psrbridge\http\Application;
```

```php
$app = new Application($config);
```

Then update lifecycle overrides and calls:

If you override `reset()` in your project, rename it to `prepareForRequest()` and keep the same signature:

```php
protected function prepareForRequest(\Psr\Http\Message\ServerRequestInterface $request): void
```

Also update any internal calls from:

```php
$this->reset($request);
```

to:

```php
$this->prepareForRequest($request);
```

#### 2) Remove `workerMode` from request configuration

If your application config sets `workerMode`, remove it:

```php
'components' => [
    'request' => [
        'class' => \yii2\extensions\psrbridge\http\Request::class,
        // 'workerMode' => true, // removed
    ],
],
```

### Notes

- Classes changed from `final` to non-final are extensibility improvements and do not require migration.
