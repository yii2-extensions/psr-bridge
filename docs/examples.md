# Practical examples

These recipes extend the [README entry script](../README.md#quick-start) and the application's existing Yii
configuration. Keep application authorization, verb filters, and input validation in place.

## Custom worker lifecycle

Construct the bridge application once outside the worker loop. This function handles one request using the
runtime's emission callback:

```php
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use yii2\extensions\psrbridge\http\Application;

/**
 * @param callable(ResponseInterface): void $emit
 */
function serve(Application $app, ServerRequestInterface $request, callable $emit): void
{
    $response = $app->handle($request);
    $emitted = false;

    try {
        $emit($response);
        $emitted = true;
    } finally {
        $app->finalize($emitted);
    }
}
```

Successful finalization triggers `EVENT_AFTER_SEND` and marks the Yii response sent, then cleans up tracked events
and optionally flushes the logger. On emission failure, `finalize(false)` skips after-send and still performs
cleanup. Keep finalization outside the emitter's catch path: an after-send exception must not cause a second
finalize call or be misreported as an emission failure.

`handle()` prepares the response and triggers before-send/after-prepare; it does not emit bytes or trigger after-send.
Do not call `run()` or `Response::send()` in this worker cycle. A `clean()` result of `true` indicates memory usage
has reached 90% of the configured limit and lets the runtime decide to recycle the worker.

## JSON from SAPI

After creating `$request` with `ServerRequestCreator`, clear the empty SAPI parsed body for JSON before binding:

```php
$contentType = $_SERVER['CONTENT_TYPE'] ?? $request->getHeaderLine('Content-Type');

if (is_string($contentType) && $contentType !== '') {
    $request = $request->withHeader('Content-Type', $contentType);
}

$contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));

if ($contentType === 'application/json') {
    $request = $request->withParsedBody(null);
}

$response = $app->handle($request);
```

The creator copies `HTTP_*` headers; standard SAPI `CONTENT_TYPE` is attached explicitly above.
Use the emission/finalization block from the README afterward. The request parser must be configured before
`handle()`. The PSR request passed by a worker may already contain parsed data and need no such adjustment.

## Uploads

Use `yii2\extensions\psrbridge\http\UploadedFile` with the application's existing model validator:

```php
use yii2\extensions\psrbridge\http\UploadedFile;

$model->avatar = UploadedFile::getInstance($model, 'avatar');

if ($model->validate() && $model->avatar !== null) {
    $path = Yii::getAlias('@runtime/uploads/') . Yii::$app->security->generateRandomString() . '.png';

    if (!$model->avatar->saveAs($path)) {
        throw new RuntimeException('Unable to store the validated upload.');
    }
}
```

This fragment assumes the model requires and verifies PNG content and the target directory exists. Choose the
extension from the validated content policy; do not trust the client filename or MIME type. Store private uploads
outside the web root. Failed PSR uploads retain their PHP error code without opening their stream. Successful
conversion detaches the PSR stream resource for Yii's upload handling; do not read that PSR upload afterward.

## Downloads and ranges

In an existing controller action, return the bridge response from `sendFile()` or `sendStreamAsFile()`:

```php
return Yii::$app->response->sendFile($authorizedAbsolutePath, 'report.pdf');
```

Emit the returned PSR response before finalization or temporary-file cleanup. Ranged bodies use a bounded,
read-only `RangeStream`; its offsets are relative to the selected range, `detach()` returns `null`, and metadata
omits the underlying URI. Read through that stream instead of reopening its source. Avoid casting large bodies
to strings: `getContents()` and a cast still allocate the returned content even though transport uses chunked reads.

`SapiEmitter` reads at most its positive buffer length per chunk (8192 bytes by default). For HEAD, pass `false`
as its second argument; it sees a response rather than the originating request. It also suppresses bodies for its
listed no-body status codes. Any output before emission can cause `HeadersAlreadySentException` or
`OutputAlreadySentException`.

## Low-level conversion

Use `Request::setPsr7Request()` before `getPsr7Request()`. The getter returns the attached request; it does not
construct one from Yii globals. `Response::getPsr7Response()` performs pre-send conversion and needs configured
response/stream factories. For a standalone response outside `Application`, call `completeSend()` after successful
emission. For an application-managed response, call `Application::finalize()` so worker cleanup runs as well.
