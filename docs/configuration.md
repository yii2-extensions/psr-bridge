# Configuration

`http\Application` installs the bridge request, response, and error handler as core components. Keep the application's
other Yii settings, routes, access filters, and CSRF policy. The following fragment extends an existing web config; it
is not a complete application.

## Factories

Response conversion resolves two factories from `Yii::$container`:

```php
use HttpSoft\Message\{ResponseFactory, StreamFactory};
use Psr\Http\Message\{ResponseFactoryInterface, StreamFactoryInterface};

$config['container']['definitions'][ResponseFactoryInterface::class] = ResponseFactory::class;
$config['container']['definitions'][StreamFactoryInterface::class] = StreamFactory::class;
```

`ServerRequestCreator` receives server-request, stream, and uploaded-file factories directly. Container configuration is
applied once at bridge construction; application bootstrap runs for each request.

## Browser cookies and lazy sessions

Load `COOKIE_VALIDATION_KEY` through the application's secret configuration. Fail on a missing value rather than falling
back to a fixed key:

```php
$key = getenv('COOKIE_VALIDATION_KEY');

if (!is_string($key) || $key === '') {
    throw new RuntimeException('COOKIE_VALIDATION_KEY must be configured.');
}

$config['components']['request']['cookieValidationKey'] = $key;
$config['components']['request']['enableCookieValidation'] = true;
$config['components']['session']['useCookies'] = false;
$config['components']['session']['useStrictMode'] = true;
```

Disable PHP's automatic session cookie handling before sessions start (`session.use_cookies=0`, or the Yii session
setting above). The bridge queues an active session cookie before closing it after bootstrap or during response
conversion. Preserve request cookie validation and its response synchronization so those cookies are signed. An empty
key fails when validated cookies are accessed or a response with validation enabled is converted.

`useSession=true` opens the session before bootstrap and closes it afterward, including when bootstrap throws. Set
`useSession=false` when eager opening is unnecessary, including an application that opens its session lazily. This flag
does not disable sessions or make an application stateless. With either value, request preparation closes a previously
active session and applies the current session cookie ID, or clears the ID if the cookie is absent, fails signature
validation, or has an invalid value. It does not open the session itself in the lazy case.

The bridge accepts IDs of 1 to 256 characters from `A-Z`, `a-z`, `0-9`, `,`, and `-`. Syntax validation is distinct from
storage validation: a well-formed unknown ID is applied. `useStrictMode=true` asks the session handler to replace
unknown IDs; keep Yii's normal login-time ID regeneration. Do not bypass cookie validation to pass a raw session ID from
the client.

The default `http\Session` ages flash messages once per request even when bootstrap and an action reopen the session. It
and the bridge error handler use process-level shutdown dispatchers without retaining completed request instances.
Custom session classes keep their configured storage behavior; verify their shutdown and close/reopen behavior
separately. A positive session-cookie `lifetime` is carried into its PSR expiration.

## Worker state

Configure lifecycle settings in the constructor config or before handling requests:

```php
$config['useSession'] = false;
$config['syncCookieValidation'] = true;
$config['persistentComponents'] = ['db', 'cache'];
```

Only components whose loaded instances are safe to reuse belong in `persistentComponents`; its entries support shell
wildcard patterns as well as exact IDs. Exclude `request`, `response`, `errorHandler`, `session`, and `user`. Defaults
retain `db` and `cache`. Reinitialization uses the original constructor config for other components.

Declare worker-lifetime event listeners in the persistent component's `on eventName` configuration. Cleanup restores
these configured listeners after their events run. Avoid attaching request-capturing closures to persistent components:
cleanup tracks triggered events and cannot enumerate handlers that never ran through Yii's public API.

`resetUploadedFiles` remains a public compatibility property, but upload state is reset unconditionally during request
preparation even when it is `false`. Do not use it as a switch to retain files across requests.
`syncCookieValidation=false` skips copying both the request validation key and its enable flag to the response; it does
not disable request cookie access. `flushLogger` controls the logger flush during finalization.

## Request bodies

Configure a parser for the payloads the application accepts:

```php
$config['components']['request']['parsers']['application/json'] = \yii\web\JsonParser::class;
```

When the PSR request's parsed body is `null`, binding it selects an exact Content-Type parser (ignoring parameters after
`;`) or the `*` fallback. Parsers receive the full raw body as a string. Existing non-`null` parsed bodies, including
`[]`, are preserved. Scalar parser results are discarded; body parameters remain an array or object.

`ServerRequestCreator::createFromGlobals()` imports CGI `CONTENT_TYPE` and `CONTENT_LENGTH` as headers. It preserves
nonempty `$_POST` and native POST form bodies, while leaving unparsed bodies such as JSON as `null` for the configured
parser. Without a configured parser, urlencoded bodies are parsed for PUT, PATCH and DELETE as well. See the
[JSON example](examples.md#json-from-sapi). Avoid automatic multipart parsing in workers without enforced body/upload
size limits; PSR uploaded files already carry upload data.

For exact signatures and exceptions, consult the
[generated API](https://yii2-framework.github.io/docs/extensions/psr-bridge/reference/). Continue with
[worker and file examples](examples.md) and [migration checks](migration.md).

[Back to documentation](../README.md#documentation)
