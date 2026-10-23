# Upgrade Guide

## 0.4.0

- Upgrade PHP to 8.3 or later and Yii to `~2.0.55 || ^22.0@dev`.
- `handle()` no longer completes after-send. Call `$app->finalize($emitted)` once after emitting each response, passing
  `false` when emission fails, and let finalization exceptions propagate.
- The session ID from the validated request cookie is applied on every request, whatever `useSession` is. Remove
  raw-cookie `session_id()` workarounds and code that opens the session before checking `user`; keep cookie validation
  with a secret key and disable native session cookies, as shown in
  [configuration](docs/configuration.md#browser-cookies-and-lazy-sessions).
- Subclasses that override `openSessionFromRequestCookies()` must account for the new
  `syncSessionIdFromRequestCookies()` hook, which runs first; preserve its parent lifecycle when overriding it.
- Declare worker-lifetime event listeners in the persistent component `on eventName` configuration.
- `resetUploadedFiles=false` no longer keeps uploads from the previous request, and scalar body parser results are
  discarded; remove code that relies on either.
- `SapiEmitter` no longer applies `Content-Range` offsets; direct callers must pass a body already bounded to the
  declared range.
- `new SapiEmitter(null)` raises `TypeError`. Remove the argument to use `8192`-byte chunks, or pass a positive integer,
  including in DI definitions.

## 0.2.0

- Rename `yii2\extensions\psrbridge\http\StatelessApplication` to `yii2\extensions\psrbridge\http\Application` in
  imports, type hints, PHPDoc and instantiations; no alias is provided.
- Rename an overridden `reset()` to `prepareForRequest()` with the same signature, and update its calls.
- Remove `workerMode` from the `request` component configuration.
