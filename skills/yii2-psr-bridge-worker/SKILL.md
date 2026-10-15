---
name: yii2-psr-bridge-worker
description: Integrate yii2-extensions/psr-bridge in a Yii application or worker, including PSR-7 request binding, lazy session isolation, uploads, streaming responses, and post-emission finalization.
license: BSD-3-Clause
metadata:
    guide: https://yii2-framework.github.io/docs/extensions/psr-bridge/
---

# Integrate the Yii PSR bridge

Use `yii2\extensions\psrbridge` and match the installed revision. The bridge requires PHP 8.3+ and Yii
`~2.0.55 || ^22.0@dev`; the selected framework may require a newer PHP version. Preserve Composer advisory
blocking and audit the resolved dependencies. The default branch includes unreleased 0.4 changes, including
session isolation correction #288; do not assume a stable install has them.

## Consult the relevant contracts

Prefer task-supplied local Markdown. In a package checkout, read `docs/` and `UPGRADE.md`. Otherwise use direct
published Markdown, after confirming the relevant package changes have been published:

- Factories, session/cookie configuration, parsing, and worker state:
  <https://yii2-framework.github.io/docs/extensions/psr-bridge/configuration/index.md>.
- Emission/finalization, JSON from SAPI, uploads, and downloads:
  <https://yii2-framework.github.io/docs/extensions/psr-bridge/examples/index.md>.
- Consumer migration routing:
  <https://yii2-framework.github.io/docs/extensions/psr-bridge/migration/index.md>.

For an exact signature, exception, or return type, read the relevant source PHPDoc or generated type page under
<https://yii2-framework.github.io/docs/extensions/psr-bridge/reference/>. The machine-readable catalog is
<https://yii2-framework.github.io/docs/reference/psr-bridge.json>. Read only the needed pages; use `llms.txt` when
the relevant page is unknown. Do not substitute another PSR bridge's API or copy a full API catalog into context.

## Bind requests and preserve isolation

`http\Application` supplies bridge core components. Register PSR response and stream factories in its container
configuration and supply a PSR-7/PSR-17 implementation. `Request::getPsr7Request()` requires an attached request;
it does not create a PSR request from Yii data. A runtime can create requests or use `creator\ServerRequestCreator`.

Reuse the application across requests, not request-sensitive component instances. `persistentComponents` defaults
to `db` and `cache` and supports wildcard patterns. Keep request, response, error handler, session, and user out.
Request preparation always clears upload state; `resetUploadedFiles=false` does not disable that reset.

`useSession=false` skips eager session open/close around bootstrap, not session ID synchronization. On every request
the bridge closes an active session and applies the current validated cookie ID, or clears it for missing or invalid
input. Keep this path when overriding lifecycle hooks. A lazily opened session still belongs to the current request.

Preserve request cookie validation, configure its secret key, and normally retain `syncCookieValidation=true` so
response cookies are signed too. Disable native PHP session cookie emission before opening sessions. Syntax checks
reject malformed IDs; `useStrictMode` handles well-formed unknown IDs through the chosen session handler. Lazy
sessions can serve browser applications; do not disable CSRF or cookie validation just because eager opening is off.

For JSON, configure a Yii parser. Parsing happens only when the PSR parsed body is `null`; SAPI creation supplies
`$_POST` (possibly `[]`), so JSON from globals may need `withParsedBody(null)` before binding. Scalar parser results
are discarded. Parsers read the full body; enforce deployment limits before accepting large payloads.

## Complete response delivery

The cycle is `handle(request)` -> runtime emission -> `finalize(success)`. `handle()` returns a prepared PSR response
before after-send. Run finalization exactly once after the emission attempt, passing `false` if emission throws.
Set a success flag only after emission returns and call finalize in `finally`; let finalization errors propagate
without a second call. A plain PSR-15 host must arrange this extra lifecycle step. Do not mix `run()` or Yii
`Response::send()` into the cycle.

Keep file streams alive through emission. `RangeStream` exposes relative, bounded reads, hides its source URI, and
returns `null` from `detach()`. `SapiEmitter` takes a positive buffer size, defaults to 8192, and rejects `null` on
the current branch. Pass `false` as its body argument for HEAD. Avoid whole-body casts for large downloads.

Use bridge `http\UploadedFile` for model uploads, validate content before saving, and choose server-controlled
storage names/paths. Successful conversion can detach the PSR upload stream; do not consume it twice.

## Verify the integration

Test the application's required paths: successive visitors, missing/tampered/malformed cookies, lazy session access,
an upload followed by no upload, and emission success/failure. Confirm after-send follows successful emission and
does not run on failure. Check partial-download bytes and HEAD suppression when relevant. Run applicable package
and application checks and report unavailable runtime tests. Skill discovery, activation, documentation reading,
and functional success are separate evidence.
