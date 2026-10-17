# Upgrade Guide

## 0.4.0

### Minimum PHP version

Upgrade web, CLI and worker runtimes to PHP 8.3 or later, then run `composer check-platform-reqs`.

### Minimum Yii version

Before: `yiisoft/yii2` accepted `2.0.*@dev || ^22.0@dev`.
After: it requires `~2.0.55 || ^22.0@dev`. Applications on Yii 2.0 must update to 2.0.55 or a later 2.0 patch;
Yii 22 support is retained. Resolve the application's complete dependency graph and review its lock file:

```bash
composer update yiisoft/yii2 --with-all-dependencies
composer audit
```

Run the application and package tests against the selected framework version. Do not bypass Composer's advisory
blocking or add advisory exceptions to keep an older Yii release installed.

### Session isolation with eager and lazy opening

Before: `useSession=false` skipped the code that closed a prior PHP session and applied the incoming session ID.
A later lazy open could reuse stale worker state. Session cookie values also lacked the new syntax check.

After: `syncSessionIdFromRequestCookies()` closes any active session and applies the validated current cookie ID
for every request, before bootstrap. Missing, invalidly signed, or malformed values clear the ID; a later open
creates a new session. `useSession` controls only eager opening and closing around bootstrap. Cookie validation
settings are synchronized before this step, including lazy sessions.

Keep lazy opening when needed; do not add a custom raw-cookie `session_id()` workaround. For applications using
browser sessions, preserve cookie validation and configure a secret key, disable native cookie emission, and use
strict session mode when unknown IDs must be replaced. See [configuration](docs/configuration.md#browser-cookies-and-lazy-sessions).

If a subclass overrides `openSessionFromRequestCookies()`, ID synchronization now happens in the separate
`syncSessionIdFromRequestCookies()` hook first. If overriding request preparation, preserve its parent lifecycle.
Overriding that new hook without equivalent isolation bypasses the correction.

### Custom sessions and persistent event listeners

Keep the default `yii2\extensions\psrbridge\http\Session` when using native session storage. Custom session
classes remain configured explicitly; test their shutdown registration and eager close/reopen behavior, especially
flash messages. Returning-user identity restoration now follows the validated PSR session cookie with native PHP
cookies disabled; remove workarounds that force the session open before checking `user`.

Declare worker-lifetime listeners in persistent component `on eventName` configuration. Avoid adding
request-capturing closures to those components; cleanup observes triggered events rather than all registrations.

### Finish the response after emission

Before:

```php
$response = $app->handle($request);
$emit($response);
```

After:

```php
$response = $app->handle($request);
$emitted = false;

try {
    $emit($response);
    $emitted = true;
} finally {
    $app->finalize($emitted);
}
```

`handle()` now leaves after-send deferred so file streams stay readable during emission. `finalize(true)` completes
after-send and cleanup; `false` skips after-send while cleaning up. Check custom runners and middleware adapters.
An exception from finalization should propagate without retrying it.

### Upload state and body parser contracts

`resetUploadedFiles=false` no longer retains upload state; request preparation always resets bridge and Yii upload
caches. Remove code that relies on previous-request files. Scalar parser results are discarded instead of passed
to PSR-7 `withParsedBody()`; consume arrays/objects or validate the payload explicitly.

### Validate the migration

For humans and agents, search application code and DI definitions for `SapiEmitter(null)`, `handle(`, `finalize(`,
`useSession`, `resetUploadedFiles`, lifecycle overrides, and request-sensitive `persistentComponents` entries.
Change only affected integration points, then verify:

1. Two visitors with separate signed session cookies retain separate data with eager and lazy sessions.
2. Missing, malformed, and tampered cookies cannot resume the previous visitor's session. A well-formed unknown ID is distinct from an invalid ID: verify the chosen session handler's strict-mode behavior separately.
3. Uploads disappear on the following no-upload request, including when the compatibility flag is `false`.
4. Emission succeeds before after-send; emission failure skips after-send; either path finalizes exactly once.
5. Partial downloads expose only the selected bytes and HEAD emits no body. Run package/application tests and static analysis; record any untested runtime integration.

### Direct `SapiEmitter` callers

`SapiEmitter` emits the PSR-7 body as provided and no longer applies
`Content-Range` offsets. Direct callers must provide a body already bounded to the
declared range and consistent with `Content-Length` when present.
`ResponseAdapter` already prepares bounded range bodies.

### Replace explicit `null` buffer configuration

Before: `new SapiEmitter(null)` selected full-body string emission.
After: remove that argument to use the default `8192`-byte chunks, or pass a positive integer:

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
