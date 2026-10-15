# Configuration

`http\Application` installs the bridge request, response, and error handler as core components. Keep the application's
other Yii settings, routes, access filters, and CSRF policy. The following fragment extends an existing web config;
it is not a complete application.

## Factories

Response conversion resolves two factories from `Yii::$container`:

```php
use HttpSoft\Message\{ResponseFactory, StreamFactory};
use Psr\Http\Message\{ResponseFactoryInterface, StreamFactoryInterface};

$config['container']['definitions'][ResponseFactoryInterface::class] = ResponseFactory::class;
$config['container']['definitions'][StreamFactoryInterface::class] = StreamFactory::class;
```

`ServerRequestCreator` receives server-request, stream, and uploaded-file factories directly. Container configuration
is applied once at bridge construction; application bootstrap runs for each request.

## Browser cookies and lazy sessions

Load `COOKIE_VALIDATION_KEY` through the application's secret configuration. Fail on a missing value rather than
falling back to a fixed key:

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
setting above). The bridge writes session cookies into the PSR response when a session is active during conversion.
Preserve request cookie validation and its response synchronization so those cookies are signed. An empty key fails
when validated cookies are accessed or a response with validation enabled is converted.

`useSession=true` opens the session before bootstrap and closes it afterward, including when bootstrap throws.
Set `useSession=false` when eager opening is unnecessary, including an application that opens its session lazily.
This flag does not disable sessions or make an application stateless. With either value, request preparation closes
a previously active session and applies the current session cookie ID, or clears the ID if the cookie is absent,
fails signature validation, or has an invalid value. It does not open the session itself in the lazy case.

The bridge accepts IDs of 1 to 256 characters from `A-Z`, `a-z`, `0-9`, `,`, and `-`. Syntax validation is distinct
from storage validation: a well-formed unknown ID is applied. `useStrictMode=true` asks the session handler to
replace unknown IDs; keep Yii's normal login-time ID regeneration. Do not bypass cookie validation to pass a raw
session ID from the client.

## Worker state

Configure lifecycle settings in the constructor config or before handling requests:

```php
$config['useSession'] = false;
$config['syncCookieValidation'] = true;
$config['persistentComponents'] = ['db', 'cache'];
```

Only components whose loaded instances are safe to reuse belong in `persistentComponents`; its entries support
shell wildcard patterns as well as exact IDs. Exclude `request`, `response`, `errorHandler`, `session`, and `user`.
Defaults retain `db` and `cache`. Reinitialization uses the original constructor config for other components.

`resetUploadedFiles` remains a public compatibility property, but upload state is reset unconditionally during
request preparation even when it is `false`. Do not use it as a switch to retain files across requests.
`syncCookieValidation=false` skips copying both the request validation key and its enable flag to the response;
it does not disable request cookie access. `flushLogger` controls the logger flush during finalization.

## Request bodies

Configure a parser for the payloads the application accepts:

```php
$config['components']['request']['parsers']['application/json'] = \yii\web\JsonParser::class;
```

When the PSR request's parsed body is `null`, binding it selects an exact Content-Type parser (ignoring parameters
after `;`) or the `*` fallback. Parsers receive the full raw body as a string. Existing non-`null` parsed bodies,
including `[]`, are preserved. Scalar parser results are discarded; body parameters remain an array or object.

`ServerRequestCreator::createFromGlobals()` attaches `$_POST`, which can be `[]` for JSON. For such SAPI JSON requests,
set the PSR parsed body to `null` before `handle()` to enable the configured parser; see the
[JSON example](examples.md#json-from-sapi). Avoid automatic multipart parsing in workers without enforced body/upload
size limits; PSR uploaded files already carry upload data.

For exact signatures and exceptions, consult the
[generated API](https://yii2-framework.github.io/docs/extensions/psr-bridge/reference/). Continue with
[worker and file examples](examples.md) and [migration checks](migration.md).
