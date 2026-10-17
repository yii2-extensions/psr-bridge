<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\scenario;

use Closure;
use HttpSoft\Message\{ResponseFactory, ServerRequest, ServerRequestFactory, StreamFactory, UploadedFileFactory};
use InvalidArgumentException;
use RuntimeException;
use WeakReference;
use Yii;
use yii\web\{Cookie, ForbiddenHttpException, JsonParser};
use yii2\extensions\psrbridge\adapter\ServerRequestAdapter;
use yii2\extensions\psrbridge\creator\ServerRequestCreator;
use yii2\extensions\psrbridge\http\{Application, Request, Response, UploadedFile};
use yii2\extensions\psrbridge\tests\support\stub\Identity;

use function array_filter;
use function count;
use function file_put_contents;
use function gc_collect_cycles;
use function implode;
use function json_decode;
use function proc_close;
use function proc_open;
use function rawurldecode;
use function str_repeat;
use function str_starts_with;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;
use const UPLOAD_ERR_OK;

/**
 * @param Closure(): mixed $callback
 * @param array<string, mixed> $config
 * @return Application<\yii\web\IdentityInterface>
 */
function application(Closure $callback, array $config = []): Application
{
    $base = [
        'id' => 'worker-regressions',
        'basePath' => sys_get_temp_dir(),
        'runtimePath' => sys_get_temp_dir() . '/psr-bridge-regressions',
        'container' => ['definitions' => [
            \Psr\Http\Message\ResponseFactoryInterface::class => ResponseFactory::class,
            \Psr\Http\Message\StreamFactoryInterface::class => StreamFactory::class,
        ]],
        'controllerMap' => ['probe' => ['class' => ProbeController::class, 'callback' => $callback]],
        'components' => [
            'request' => [
                'scriptFile' => __FILE__,
                'scriptUrl' => '',
                'cookieValidationKey' => 'worker-regression-key',
                'enableCsrfValidation' => false,
                'parsers' => ['application/json' => JsonParser::class],
            ],
            'response' => ['format' => Response::FORMAT_JSON],
            'user' => ['identityClass' => Identity::class],
            'session' => ['useStrictMode' => true],
            'urlManager' => ['enablePrettyUrl' => true, 'showScriptName' => false],
            'log' => ['targets' => []],
        ],
    ];

    return new Application(\yii\helpers\ArrayHelper::merge($base, $config));
}

/**
 * @param array<array-key, string> $cookies
 */
function request(array $cookies = []): ServerRequest
{
    return new ServerRequest(cookieParams: $cookies, method: 'GET', uri: 'http://example.test/probe/index');
}

/**
 * @return array<array-key, string>
 */
function cookies(\Psr\Http\Message\ResponseInterface $response): array
{
    $result = [];

    foreach ($response->getHeader('Set-Cookie') as $header) {
        [$pair] = explode(';', $header, 2);
        $parts = explode('=', $pair, 2);
        $name = $parts[0];
        $value = $parts[1] ?? '';
        $result[$name] = rawurldecode($value);
    }

    return $result;
}

/**
 * @return array<mixed>
 */
function run(string $scenario): array
{
    if ($scenario === 'yii-overrides') {
        $handler = new class extends \yii2\extensions\psrbridge\http\ErrorHandler {
            // @phpstan-ignore missingType.return (This regression deliberately preserves an untyped Yii override.)
            public function register()
            {
                parent::register();
            }
        };
        $bound = new class extends Request {
            /**
             * @return bool
             */
            public function getIsSecureConnection()
            {
                return parent::getIsSecureConnection();
            }
        };

        $bound->setPsr7Request(request());
        $handler->register();

        $registered = set_error_handler(static fn(): bool => false) === [$handler, 'handleError'];

        restore_error_handler();

        $handler->unregister();

        return ['handler' => $registered, 'secure' => $bound->getIsSecureConnection()];
    }

    if ($scenario === 'fatal-shutdown') {
        $app = application(
            static fn(): array => [],
            [
                'components' => [
                    'errorHandler' => [
                        'class' => \yii2\extensions\psrbridge\http\ErrorHandler::class,
                        'on shutdown' => static function (): void {
                            /** @var string $file */
                            $file = $GLOBALS['shutdown-file'] ?? throw new \RuntimeException('Missing shutdown marker.');
                            file_put_contents($file, "handled\n", FILE_APPEND);
                        },
                    ],
                ],
            ]
        );

        for ($i = 0; $i < 4; $i++) {
            $app->handle(request());
            $app->finalize();
        }

        require __DIR__ . '/fatal-fixture.php';
        require __DIR__ . '/fatal-fixture.php';
    }

    if (str_starts_with($scenario, 'auth-')) {
        $app = application(
            static function (): array {
                if (Yii::$app->request->getHeaders()->has('X-Login')) {
                    $identity = Identity::findIdentity('100');

                    if ($identity === null) {
                        throw new RuntimeException('Missing fixture identity.');
                    }

                    Yii::$app->user->login($identity);
                }

                return ['guest' => Yii::$app->user->getIsGuest()];
            },
            ['useSession' => $scenario === 'auth-eager']
        );

        $login = $app->handle(request()->withHeader('X-Login', '1'));

        $app->finalize();

        $returning = $app->handle(request(cookies($login)));

        $app->finalize();

        $missing = $app->handle(request());

        $app->finalize();

        $tampered = $app->handle(request([sessionName() => 'invalid-signature']));

        $app->finalize();

        return [
            'returning' => json_decode((string) $returning->getBody(), true),
            'missing' => json_decode((string) $missing->getBody(), true),
            'tampered' => json_decode((string) $tampered->getBody(), true),
        ];
    }

    if ($scenario === 'memory') {

        $app = application(static fn(): string => str_repeat('a', 262144));

        $responses = $handlers = $sessions = [];

        for ($i = 0; $i < 12; $i++) {
            $psr = $app->handle(request());
            $responses[] = WeakReference::create($app->response);
            $handlers[] = WeakReference::create($app->errorHandler);
            $sessions[] = WeakReference::create($app->session);
            $psr->getBody()->getContents();
            $psr->getBody()->close();
            $app->finalize();
        }

        gc_collect_cycles();

        return [
            'responses' => alive($responses),
            'handlers' => alive($handlers),
            'sessions' => alive($sessions),
        ];
    }

    if ($scenario === 'session-handover') {
        $app = application(
            static function (): array {
                $flash = Yii::$app->request->getHeaders()->get('X-Flash');

                if (is_string($flash)) {
                    Yii::$app->session->setFlash('notice', $flash, false);
                }

                return ['flash' => Yii::$app->session->getFlash('notice')];
            },
        );

        $a = $app->handle(request()->withHeader('X-Flash', 'A'));

        $app->finalize();

        $app->handle(request()->withHeader('X-Flash', 'B'));
        $app->finalize();
        $app->session->open();

        $next = $app->handle(request(cookies($a)));

        $app->finalize();

        $later = $app->handle(request(cookies($a)));

        $app->finalize();

        return [
            'next' => decode((string) $next->getBody()),
            'later' => decode((string) $later->getBody()),
        ];
    }

    if (str_starts_with($scenario, 'flash-')) {
        $app = application(
            static function (): array {
                if (Yii::$app->request->getHeaders()->has('X-Flash')) {
                    Yii::$app->session->setFlash('notice', 'Saved', false);
                }

                return ['flash' => Yii::$app->session->getFlash('notice')];
            },
            ['useSession' => $scenario === 'flash-eager']
        );

        $first = $app->handle(request()->withHeader('X-Flash', '1'));

        $app->finalize();

        $second = $app->handle(request(cookies($first)));

        $app->finalize();

        $third = $app->handle(request(cookies($second)));

        $app->finalize();

        return [
            'next' => json_decode((string) $second->getBody(), true),
            'later' => json_decode((string) $third->getBody(), true),
        ];
    }

    if ($scenario === 'bootstrap-session') {
        $app = application(
            static fn(): array => [
                'active' => Yii::$app->session->getIsActive()],
            ['bootstrap' => [SessionWriter::class],
            ],
        );

        $first = $app->handle(request());

        $app->finalize();

        $second = $app->handle(request(cookies($first)));

        $value = Yii::$app->session->get('bootstrap-count');

        $app->finalize();

        return [
            'hasCookie' => isset(cookies($first)[sessionName()]),
            'nextCount' => $value,
            'activeInAction' => (decode((string) $second->getBody())['active'] ?? null),
        ];
    }

    if ($scenario === 'cookie-lifetime') {
        $app = application(
            static function (): array {
                Yii::$app->session->set('value', 1);

                return [];
            },
            [
                'components' => ['session' => ['cookieParams' => ['lifetime' => 3600]]],
            ],
        );

        $psr = $app->handle(request());

        $app->finalize();

        return [
            'expires' => str_contains(implode(';', $psr->getHeader('Set-Cookie')), 'Expires='),
            'maxAge' => str_contains(implode(';', $psr->getHeader('Set-Cookie')), 'Max-Age=3600'),
        ];
    }

    if ($scenario === 'persistent-events') {
        $configured = $temporary = 0;

        $app = application(
            static function () use (&$temporary): array {
                $queue = Yii::$app->get('queue');

                if (!$queue instanceof ProbeComponent) {
                    throw new RuntimeException('Missing fixture queue.');
                }

                if (Yii::$app->request->getHeaders()->has('X-Temporary')) {
                    $queue->on(
                        'probe',
                        static function () use (&$temporary): void {
                            $temporary++;
                        }
                    );
                }

                $queue->fire();

                return [];
            },
            [
                'persistentComponents' => ['queue'],
                'components' => [
                    'queue' => ['class' => ProbeComponent::class,
                        'on probe' => static function () use (&$configured): void {
                            $configured++;
                        },
                    ],
                ],
            ],
        );

        $app->handle(request()->withHeader('X-Temporary', '1'));
        $app->finalize();
        $app->handle(request());
        $app->finalize();
        $app->handle(request());
        $app->finalize();

        return [
            'configured' => $configured,
            'temporary' => $temporary,
        ];
    }

    if ($scenario === 'event-senders') {
        $app = application(
            static function (): array {
                $refs = [];

                for ($i = 0; $i < 1000; $i++) {
                    $sender = new ProbeComponent();
                    $refs[] = WeakReference::create($sender);
                    $sender->fire();
                }

                unset($sender);
                gc_collect_cycles();

                return [
                    'alive' => count(array_filter($refs, static fn(WeakReference $ref): bool => $ref->get() !== null)),
                ];
            }
        );
        $psr = $app->handle(request());

        $app->finalize();

        return decode((string) $psr->getBody());
    }

    if ($scenario === 'error-headers') {
        $app = application(
            static function (): never {
                Yii::$app->response->headers->set('Access-Control-Allow-Origin', 'https://app.example');
                Yii::$app->response->cookies->add(new Cookie(['name' => 'before-error', 'value' => 'kept']));

                throw new ForbiddenHttpException('denied');
            }
        );
        $psr = $app->handle(request());

        $app->finalize();

        return [
            'status' => $psr->getStatusCode(),
            'cors' => $psr->getHeaderLine('Access-Control-Allow-Origin'),
            'cookie' => isset(cookies($psr)['before-error']),
        ];
    }

    if ($scenario === 'request-setters') {
        $bound = new Request(['scriptUrl' => '/index.php']);

        $bound->setPsr7Request(request());

        $bound->headers->set('X-Local', 'yes');

        $bound->setBodyParams(['override' => 'yes']);

        $same = [
            'scriptUrl' => $bound->getScriptUrl(),
            'header' => $bound->headers->get('X-Local'),
            'body' => $bound->getBodyParams(),
        ];

        $bound->setPsr7Request(request());

        return [
            'same' => $same,
            'nextHeader' => $bound->headers->get('X-Local'),
            'nextBody' => $bound->getBodyParams(),
        ];
    }

    if (str_starts_with($scenario, 'form-')) {
        $method = strtoupper(substr($scenario, 5));
        $psr = request()
            ->withMethod($method)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded');
        $psr
            ->getBody()
            ->write('a=1&b=2');
        $psr
            ->getBody()
            ->rewind();

        $bound = new Request();

        $bound->setPsr7Request($psr);

        $parsed = $bound->getBodyParams();

        $bound->setPsr7Request($psr->withParsedBody(['kept' => 'yes']));

        return ['parsed' => $parsed, 'preparsed' => $bound->getBodyParams()];
    }

    if ($scenario === 'creator-headers') {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '2',
        ];

        $creator = new ServerRequestCreator(new ServerRequestFactory(), new StreamFactory(), new UploadedFileFactory());

        $psr = $creator->createFromGlobals();

        return [
            'contentType' => $psr->getHeaderLine('Content-Type'),
            'contentLength' => $psr->getHeaderLine('Content-Length'),
            'parsedBody' => $psr->getParsedBody(),
        ];
    }

    if (str_starts_with($scenario, 'https-')) {
        $_SERVER = ['HTTPS' => $scenario === 'https-stale' ? 'on' : ''];
        $bound = new Request();

        $bound->setPsr7Request(
            request()
                ->withUri(
                    new \HttpSoft\Message\Uri(
                        $scenario === 'https-current' ? 'https://example.test/' : 'http://example.test/'
                    )
                )
        );

        return ['secure' => $bound->getIsSecureConnection()];
    }

    if ($scenario === 'empty-download' || $scenario === 'pipe-download') {
        $app = application(
            static function () use ($scenario): Response {
                $response = Yii::$app->response;
                $response->format = Response::FORMAT_RAW;

                if ($scenario === 'empty-download') {
                    $handle = tmpfile();

                    if ($handle === false) {
                        throw new RuntimeException('Cannot create empty fixture stream.');
                    }

                    return $response->sendStreamAsFile($handle, 'empty.txt');
                }

                $process = proc_open(
                    [PHP_BINARY, '-r', 'echo "abcdefEXTRA";'],
                    [1 => ['pipe', 'w']],
                    $pipes,
                );

                if (!is_resource($process) || !isset($pipes[1])) {
                    throw new RuntimeException('Cannot start fixture pipe.');
                }

                $GLOBALS['download-process'] = $process;

                return $response->sendStreamAsFile($pipes[1], 'pipe.txt', ['fileSize' => 6]);
            }
        );

        $psr = $app->handle(request());
        $body = (string) $psr->getBody();

        $psr->getBody()->close();
        $app->finalize();

        if (isset($GLOBALS['download-process']) && is_resource($GLOBALS['download-process'])) {
            proc_close($GLOBALS['download-process']);
        }

        return [
            'status' => $psr->getStatusCode(),
            'length' => $psr->getHeaderLine('Content-Length'),
            'body' => $body,
        ];
    }

    if ($scenario === 'upload-subclass') {
        $path = tempnam(sys_get_temp_dir(), 'bridge-upload-');

        if ($path === false) {
            throw new RuntimeException('Cannot create fixture upload.');
        }

        file_put_contents($path, 'x');

        $file = new \HttpSoft\Message\UploadedFile($path, 1, UPLOAD_ERR_OK, 'x.txt');

        UploadedFile::setPsr7Adapter(new ServerRequestAdapter(request()->withUploadedFiles(['file' => $file])));

        $subclass = new class extends UploadedFile {};

        $result = [
            'single' => $subclass::getInstanceByName('file') instanceof $subclass,
            'list' => ($subclass::getInstancesByName('file')[0] ?? null) instanceof $subclass,
        ];

        UploadedFile::reset();

        unlink($path);

        return $result;
    }

    throw new InvalidArgumentException("Unknown regression scenario: {$scenario}");
}

/**
 * @param list<WeakReference<object>> $references
 */
function alive(array $references): int
{
    $count = 0;

    foreach ($references as $reference) {
        if ($reference->get() !== null) {
            $count++;
        }
    }

    return $count;
}

function sessionName(): string
{
    $name = \session_name();

    if ($name === false) {
        throw new RuntimeException('Missing session name.');
    }

    return $name;
}

/**
 * @return array<mixed>
 */
function decode(string $json): array
{
    $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($value)) {
        throw new RuntimeException('Expected a fixture JSON object.');
    }

    return $value;
}
