# Upgrade Guide

## 0.4.0

- `yii2\extensions\psrbridge\emitter\SapiEmitter::__construct()` no longer accepts `null` for `$bufferLength`.
- The default buffer length is now `8192` bytes and response bodies are emitted with bounded chunked reads by default.

### Replace explicit `null` buffer configuration

If your application explicitly passes `null`, remove the argument or pass a positive integer:

```php
use yii2\extensions\psrbridge\emitter\SapiEmitter;

$emitter = new SapiEmitter();
```

```php
$emitter = new SapiEmitter(8192);
```

Update DI/container definitions in the same way by removing an explicit `null` constructor argument or replacing it with
a positive integer.

- Passing `null` now raises a PHP `TypeError`.
- The previous full-body string emission path was removed to avoid memory exhaustion for large PSR-7 response bodies.

## 0.2.0

- `yii2\extensions\psrbridge\http\StatelessApplication` was renamed to `yii2\extensions\psrbridge\http\Application`.
- No compatibility alias is provided for `StatelessApplication`; all imports and type hints must be updated.
- `yii2\extensions\psrbridge\http\StatelessApplication::reset()` was renamed to `yii2\extensions\psrbridge\http\Application::prepareForRequest()`.
- `yii2\extensions\psrbridge\http\Request::$workerMode` was removed.

### 1) Update custom `StatelessApplication` subclasses

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

- Classes changed from `final` to non-final are extensibility improvements and do not require migration.
