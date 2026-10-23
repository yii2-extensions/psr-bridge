---
name: yii2-psr-bridge-worker
description: Integrate yii2-extensions/psr-bridge in a Yii application or worker, including PSR-7 request binding, lazy session isolation, uploads, streaming responses, and post-emission finalization.
license: BSD-3-Clause
metadata:
    guide: https://yii2-framework.github.io/docs/extensions/psr-bridge/
---

# Integrate the Yii PSR bridge

Use `yii2\extensions\psrbridge` and match the installed revision. The bridge requires PHP 8.3+ and Yii
`~2.0.55 || ^22.0@dev`; the selected framework may require a newer PHP version. Preserve Composer advisory blocking and
audit the resolved dependencies. Verify the installed bridge's finalization and session-isolation contracts before
applying the lifecycle guidance below.

## Consult the relevant contracts

In a root package checkout, use `composer show --self --format=json` for metadata. For installed dependencies, resolve
the real path and read the configured vendor directory's `composer/installed.php` (`versions[<package>]`):
`install_path`, `pretty_version` and Composer's selected `reference`. Do not blindly prefer `source.reference` over
`dist.reference`. For any live package checkout, including a symlinked path install, inspect its current `HEAD` and
files even when clean; report metadata mismatches and let the actual files govern. Uncommitted changes have no matching
SHA. Otherwise use the selected reference or a verified matching tag only after establishing its source provenance.
Compare `composer.lock`; it may differ from the inspected installation. Identify a lock-only state when no package files
are installed, and resolve dependencies separately. Use hosted pages, catalogs or snapshots only when their source
revision matches.

Prefer matching task-supplied Markdown or checkout `docs/` and `UPGRADE.md`. Otherwise read these published pages after
checking their revision, or the corresponding files at the resolved reference in
<https://github.com/yii2-extensions/psr-bridge>. If guides are unavailable or offline, inspect local source/PHPDoc and
the changelog, and report the documentation gap.

- Factories, session/cookie configuration, parsing, and worker state:
  <https://yii2-framework.github.io/docs/extensions/psr-bridge/configuration/index.md>.
- Emission/finalization, JSON from SAPI, uploads, and downloads:
  <https://yii2-framework.github.io/docs/extensions/psr-bridge/examples/index.md>.
- Consumer migration routing: <https://yii2-framework.github.io/docs/extensions/psr-bridge/migration/index.md>.

For an exact signature, exception, or return type, inspect the relevant type and its PHPDoc in the resolved package
files. Read only the needed pages; use `llms.txt` when the relevant page is unknown. Do not substitute another PSR
bridge's API or copy a full API catalog into context.

## Bind requests and preserve isolation

`http\Application` supplies bridge core components. Register PSR response and stream factories in its container
configuration and supply a PSR-7/PSR-17 implementation. `Request::getPsr7Request()` requires an attached request; it
does not create a PSR request from Yii data. A runtime can create requests or use `creator\ServerRequestCreator`.

Reuse the application across requests, not request-sensitive component instances. `persistentComponents` defaults to
`db` and `cache` and supports wildcard patterns. Keep request, response, error handler, session, and user out. Request
preparation always clears upload state; `resetUploadedFiles=false` does not disable that reset.

`useSession=false` skips eager session open/close around bootstrap, not session ID synchronization. On every request the
bridge closes an active session and applies the current validated cookie ID, or clears it for missing or invalid input.
Keep this path when overriding lifecycle hooks. A lazily opened session still belongs to the current request.

Preserve request cookie validation, configure its secret key, and normally retain `syncCookieValidation=true` so
response cookies are signed too. Disable native PHP session cookie emission before opening sessions. Syntax checks
reject malformed IDs; `useStrictMode` handles well-formed unknown IDs through the chosen session handler. Lazy sessions
can serve browser applications; do not disable CSRF or cookie validation just because eager opening is off.

For JSON, configure a Yii parser. Parsing happens only when the PSR parsed body is `null`; SAPI creation supplies
`$_POST` (possibly `[]`), so JSON from globals may need `withParsedBody(null)` before binding. Scalar parser results are
discarded. Parsers read the full body; enforce deployment limits before accepting large payloads.

## Complete response delivery

The cycle is `handle(request)` -> runtime emission -> `finalize(success)`. `handle()` returns a prepared PSR response
before after-send. Run finalization exactly once after the emission attempt, passing `false` if emission throws. Set a
success flag only after emission returns and call finalize in `finally`; let finalization errors propagate without a
second call. A plain PSR-15 host must arrange this extra lifecycle step. Do not mix `run()` or Yii `Response::send()`
into the cycle.

Keep file streams alive through emission. `RangeStream` exposes relative, bounded reads, hides its source URI, and
returns `null` from `detach()`. `SapiEmitter` takes a positive buffer size, defaults to 8192, and rejects `null` on the
current branch. Pass `false` as its body argument for HEAD. Avoid whole-body casts for large downloads.

Use bridge `http\UploadedFile` for model uploads, validate content before saving, and choose server-controlled storage
names/paths. Successful conversion can detach the PSR upload stream; do not consume it twice.

## Verify the integration

Test the application's required paths: successive visitors, missing/tampered/malformed cookies, lazy session access, an
upload followed by no upload, and emission success/failure. Confirm after-send follows successful emission and does not
run on failure. Check partial-download bytes and HEAD suppression when relevant. Run applicable package and application
checks and report unavailable runtime tests. Skill discovery, activation, documentation reading, and functional success
are separate evidence.
