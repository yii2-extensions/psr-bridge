<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use PHPUnit\Framework\Attributes\{Group, PreserveGlobalState, RunTestsInSeparateProcesses, TestWith};
use RuntimeException;
use WeakReference;
use Yii;
use yii\base\Component;
use yii\log\Logger;
use yii\web\{Cookie, ForbiddenHttpException};
use yii2\extensions\psrbridge\http\{Application, ErrorHandler, Response, Session};
use yii2\extensions\psrbridge\tests\support\stub\Identity;
use yii2\extensions\psrbridge\tests\support\TestCase;

use function yii2\extensions\psrbridge\tests\support\scenario\{application, cookies, request};

require_once __DIR__ . '/../support/WorkerScenarios.php';

/**
 * Exercises session ownership and cleanup at the instrumented worker boundary.
 */
#[Group('http')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WorkerBoundaryTest extends TestCase
{
    private string $directory = '';

    public function testAlreadyActiveSessionAgesFlashOnlyOnceAcrossReopening(): void
    {
        $nativeSession = new \yii\web\Session(['useCookies' => false]);

        $nativeSession->open();
        $nativeSession->setFlash('message', 'already active');

        $logger = Yii::getLogger();

        $originalMessages = $logger->messages;
        $messageOffset = count($originalMessages);

        $session = new Session(['useCookies' => false]);

        try {
            $messages = array_slice($logger->messages, $messageOffset);
            $warning = array_search('Session is already started', array_column($messages, 0), true);

            self::assertIsInt(
                $warning,
                'Adopting an active session must report the existing session.',
            );
            self::assertSame(
                Logger::LEVEL_WARNING,
                $messages[$warning][1] ?? null,
                'An already-active session must be logged as a warning.',
            );
            self::assertTrue($session->getIsActive(), 'Bridge session must adopt the active native session.');

            $session->close();
            $session->open();

            self::assertSame(
                'already active',
                $session->getFlash('message'),
                'Reopening must not age flash twice.',
            );

            $nextSession = new Session(['useCookies' => false]);

            self::assertFalse(
                $nextSession->hasFlash('message'),
                'The next request must expire the aged flash.',
            );
        } finally {
            $session->close();
            $nativeSession->close();

            $logger->messages = $originalMessages;
        }
    }

    public function testAnInactiveStandaloneSessionIsNotEmittedAfterAWebApplicationEnds(): void
    {
        application(static fn(): array => []);

        \yii2\extensions\psrbridge\tests\support\ApplicationFactory::web();

        $app = Yii::$app;
        $app->state = Application::STATE_END;

        $app->session->setId('not-opened');

        self::assertFalse(
            $app->session->getIsActive(),
            'Standalone session must stay inactive.',
        );

        $response = (new Response(['charset' => 'UTF-8']))->getPsr7Response();

        self::assertArrayNotHasKey(
            $app->session->getName(),
            cookies($response),
            'Inactive session cookie must not be emitted.',
        );
    }

    #[TestWith([false, false])]
    #[TestWith([true, false])]
    #[TestWith([true, true])]
    public function testBootstrapCookieFollowsTheOutgoingResponse(bool $alternate, bool $expire): void
    {
        $app = application(
            static function () use ($alternate, $expire): array|Response {
                if ($expire) {
                    Yii::$app->response->cookies->remove(Yii::$app->session->getName());
                }

                return $alternate
                    ? new Response(
                        [
                            'format' => Response::FORMAT_JSON,
                            'data' => ['active' => Yii::$app->session->getIsActive()],
                        ],
                    )
                    : ['active' => Yii::$app->session->getIsActive()];
            },
            [
                'bootstrap' => [
                    static function (Application $app): void {
                        $count = $app->session->get('counter', 0);

                        self::assertIsInt(
                            $count,
                            'Session counter must be an integer.',
                        );

                        $app->params['count'] = $count + 1;
                        $app->session->set('counter', $app->params['count']);
                    },
                ],
            ],
        );

        $first = $app->handle(request());

        self::assertFalse(
            self::decode((string) $first->getBody())['active'] ?? null,
            'Session must be inactive when handling starts.',
        );

        $jar = cookies($first);

        self::assertArrayHasKey(
            $app->session->getName(),
            $jar,
            'Bootstrap session cookie must be emitted.',
        );

        if ($expire) {
            self::assertStringContainsString(
                'Expires=',
                $first->getHeaderLine('Set-Cookie'),
                'Expired cookie must carry an `Expires` attribute.',
            );
            self::assertSame(
                '',
                $jar[$app->session->getName()] ?? throw new \RuntimeException('Missing session cookie.'),
                'Expired cookie value must be empty.',
            );
        }

        $app->finalize();

        $second = $app->handle(request($jar));

        self::assertArrayHasKey(
            $app->session->getName(),
            cookies($second),
            'Session cookie must follow the second response.',
        );
        self::assertSame(
            $expire ? 1 : 2,
            $app->params['count'] ?? null,
            'Bootstrap counter must reflect session persistence.',
        );

        $app->finalize();
    }

    public function testCookieSynchronizationClosesSessionOpenedByCustomInitialization(): void
    {
        $storage = new Session();

        $storage->setId('visitor-a');
        $storage->open();

        $visitorA = $storage->getId();

        $storage->set('visitor', 'A');
        $storage->close();
        $storage->setId('visitor-b');
        $storage->open();

        $visitorB = $storage->getId();

        $storage->set('visitor', 'B');
        $storage->close();
        $storage->setId($visitorA);

        $custom = new class extends Session {
            public function init(): void
            {
                parent::init();
                $this->open();
            }
        };

        $custom->close();

        $app = application(
            static fn(): array => [
                'visitor' => Yii::$app->session->get('visitor')],
            [
                'useSession' => false,
                'components' => [
                    'request' => ['enableCookieValidation' => false],
                    'session' => ['class' => $custom::class],
                ],
            ],
        );

        $response = $app->handle(request(['PHPSESSID' => $visitorB]));

        self::assertSame(
            'B',
            self::decode((string) $response->getBody())['visitor'] ?? null,
            'Session must match the incoming cookie.',
        );

        $app->finalize();
    }

    /**
     * @param array<string, int|null> $params
     */
    #[TestWith([[], false])]
    #[TestWith([['lifetime' => null], false])]
    #[TestWith([['lifetime' => 0], false])]
    #[TestWith([['lifetime' => 120], true])]
    public function testCustomSessionCookieParametersKeepMissingLifetimeTransient(array $params, bool $persistent): void
    {
        $sessionClass = new class extends Session {
            /**
             * @var array<string, int|null>
             */
            public array $params = [];

            /**
             * @return array<string, int|null>
             */
            public function getCookieParams(): array
            {
                return $this->params;
            }
        };

        $app = application(
            static fn(): array => [],
            [
                'components' => [
                    'session' => [
                        'class' => $sessionClass::class,
                        'params' => $params,
                    ],
                ],
            ],
        );

        $response = $app->handle(request());

        self::assertSame(
            $persistent,
            str_contains($response->getHeaderLine('Set-Cookie'), 'Max-Age='),
            'Persistence must depend on a positive lifetime.',
        );

        $app->finalize();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testDestroyAndExplicitCookieExpirationArePreserved(bool $alternate): void
    {
        $app = application(
            static function () use ($alternate): Response {
                Yii::$app->session->open();
                Yii::$app->session->set('secret', 'old');
                Yii::$app->session->destroy();

                $response = $alternate ? new Response(['format' => Response::FORMAT_RAW]) : Yii::$app->response;
                $response->cookies->remove(Yii::$app->session->getName());

                return $response;
            }
        );

        $response = $app->handle(request());

        self::assertSame(
            '',
            cookies($response)[$app->session->getName()] ?? throw new \RuntimeException('Missing session cookie.'),
            'Explicit expiration must be preserved.',
        );
        self::assertFalse(
            $app->session->getIsActive(),
            'Destroyed session must be inactive.',
        );

        $app->finalize();
    }

    public function testDistinctPersistentSendersRestoreTheirOwnConfiguredHandlers(): void
    {
        $counts = [0, 0];
        $class = \yii2\extensions\psrbridge\tests\support\scenario\ProbeComponent::class;

        $app = application(
            static function (): array {
                foreach (['alpha', 'beta'] as $id) {
                    $sender = Yii::$app->get($id);

                    self::assertInstanceOf(
                        \yii2\extensions\psrbridge\tests\support\scenario\ProbeComponent::class,
                        $sender,
                        'Component must be a probe.',
                    );

                    $sender->fire();
                }
                return [];
            },
            [
                'useSession' => false,
                'persistentComponents' => ['alpha', 'beta'],
                'components' => [
                    'alpha' => [
                        'class' => $class, 'on probe' => static function () use (&$counts): void {
                            ++$counts[0];
                        },
                    ],
                    'beta' => [
                        'class' => $class, 'on probe' => static function () use (&$counts): void {
                            ++$counts[1];
                        },
                    ],
                ],
            ]
        );

        for ($i = 0; $i < 2; ++$i) {
            $app->handle(request());
            $app->finalize();
        }

        self::assertSame(
            [2, 2],
            $counts,
            'Each sender must restore its own handler.',
        );
    }

    public function testEmptyContainerConfigurationDoesNotInvokeItsExtensionHook(): void
    {
        $app = new class (['container' => []]) extends Application {
            public int $configured = 0;

            /**
             * @param array<mixed> $config
             */
            public function setContainer($config): void
            {
                ++$this->configured;
                parent::setContainer($config);
            }
        };

        self::assertSame(
            0,
            $app->configured,
            'Empty container configuration must skip the hook.',
        );
    }

    #[TestWith([true, false])]
    #[TestWith([false, false])]
    #[TestWith([true, true])]
    public function testErrorsDiscardDownloadMetadataButKeepCurrentHeaders(bool $success, bool $fallback): void
    {
        $afterSend = 0;
        $handler = $fallback
            ? new class extends ErrorHandler {
                protected function renderException($exception): Response
                {
                    throw new RuntimeException('Rendering failed');
                }
            }
        : new ErrorHandler();

        $handler->discardExistingOutput = false;

        $app = application(
            static function (): string {
                if (!Yii::$app->request->headers->has('X-Fail')) {
                    return 'clean';
                }

                $handle = fopen('php://temp', 'w+');

                self::assertIsResource(
                    $handle,
                    'Temporary stream must be open.',
                );

                fwrite($handle, 'abcdef');
                rewind($handle);

                Yii::$app->response->sendStreamAsFile($handle, 'old.txt', ['fileSize' => 6]);

                foreach (['Content-Encoding', 'ETag', 'Last-Modified'] as $name) {
                    Yii::$app->response->headers->set($name, 'stale');
                }

                Yii::$app->response->headers->set('Access-Control-Allow-Origin', 'https://client.example');
                Yii::$app->response->headers->set('X-Content-Type-Options', 'nosniff');
                Yii::$app->response->cookies->add(new Cookie(['name' => 'current', 'value' => 'kept']));

                throw new ForbiddenHttpException('Access denied');
            },
            [
                'useSession' => false,
                'components' => [
                    'errorHandler' => [
                        'class' => $handler::class,
                        'discardExistingOutput' => false,
                    ],
                ],
            ]
        );

        $response = $app->handle(request()->withHeader('Range', 'bytes=0-2')->withHeader('X-Fail', '1'));

        $app->response->on(
            Response::EVENT_AFTER_SEND,
            static function () use (&$afterSend): void {
                ++$afterSend;
            }
        );

        self::assertSame(
            $fallback ? 500 : 403,
            $response->getStatusCode(),
            'Status must match the error outcome.',
        );

        foreach (['Content-Length', 'Content-Range', 'Content-Encoding', 'Content-Disposition', 'Accept-Ranges',
            'ETag', 'Last-Modified', 'Expires', 'Pragma', 'Content-Type'] as $name) {
            self::assertFalse(
                $response->hasHeader($name),
                "Download header `{$name}` must be discarded.",
            );
        }

        self::assertSame(
            'no-store',
            $response->getHeaderLine('Cache-Control'),
            'Cache policy must be `no-store`.',
        );
        self::assertSame(
            'https://client.example',
            $response->getHeaderLine('Access-Control-Allow-Origin'),
            'CORS header must be kept.',
        );
        self::assertSame(
            'nosniff',
            $response->getHeaderLine('X-Content-Type-Options'),
            'Content type options header must be kept.',
        );
        self::assertArrayHasKey(
            'current',
            cookies($response),
            'Current cookie must be kept.',
        );
        self::assertStringContainsString(
            $fallback ? 'An Error occurred while handling another error:' : 'Access denied',
            (string) $response->getBody(),
            'Body must describe the error.',
        );

        $app->finalize($success);

        self::assertSame(
            $success ? 1 : 0,
            $afterSend,
            'After-send event must fire only on success.',
        );

        $next = $app->handle(request());

        self::assertSame(
            '"clean"',
            (string) $next->getBody(),
            'Next request must succeed normally.',
        );
        self::assertFalse(
            $next->hasHeader('Access-Control-Allow-Origin'),
            'Previous headers must not leak.',
        );
        self::assertArrayNotHasKey(
            'current',
            cookies($next),
            'Previous cookies must not leak.',
        );

        $app->finalize();
    }

    public function testFinalizeDoesNotInstantiateAnUnusedErrorHandler(): void
    {
        $created = 0;

        $app = application(static fn(): array => []);

        $app->set(
            'errorHandler',
            static function () use (&$created): ErrorHandler {
                ++$created;
                return new ErrorHandler();
            }
        );

        $app->finalize();

        self::assertSame(
            0,
            $created,
            'Unused error handler must stay uninstantiated.',
        );
    }

    public function testFinalizeReleasesAResponseFromARetainedHandler(): void
    {
        $app = application(static fn(): array => [], ['useSession' => false]);

        $app->handle(request());

        $handler = $app->errorHandler;

        $extra = new Response();
        $weak = WeakReference::create($extra);

        $handler->setResponse($extra);

        unset($extra);

        $app->finalize();

        self::assertNull(
            $weak->get(),
            'Retained handler must not hold the response.',
        );
    }

    public function testLazySessionIsConstructedOnlyAfterReinitialization(): void
    {
        $created = 0;
        $app = application(
            static fn(): array => [],
            [
                'useSession' => false,
                'components' => [
                    'session' => static function () use (&$created): Session {
                        ++$created;
                        return new Session();
                    },
                ],
            ],
        );

        $app->handle(request());

        self::assertSame(
            1,
            $created,
            'Session must be built on first use.',
        );

        $app->finalize();

        $app->set(
            'session',
            static function () use (&$created): Session {
                ++$created;
                return new Session();
            }
        );

        $app->handle(request());

        self::assertSame(
            2,
            $created,
            'Session must be rebuilt after reinitialization.',
        );

        $app->finalize();
    }

    public function testOversizedUnsignedMemoryValuesAreClampedBeforeMultiplication(): void
    {
        $original = ini_get('memory_limit');

        try {
            @ini_set('memory_limit', '9223372036854775808B');

            $app = new Application();

            self::assertSame(
                PHP_INT_MAX,
                $app->getMemoryLimit(),
                'Oversized limit must clamp to `PHP_INT_MAX`.',
            );
        } finally {
            ini_set('memory_limit', $original);
        }
    }

    public function testPersistentComponentsWithoutConfiguredHandlersLoseTemporaryListeners(): void
    {
        $calls = 0;
        $class = \yii2\extensions\psrbridge\tests\support\scenario\ProbeComponent::class;

        $app = application(
            static function () use (&$calls): array {
                $sender = Yii::$app->get('probe');

                self::assertInstanceOf(
                    \yii2\extensions\psrbridge\tests\support\scenario\ProbeComponent::class,
                    $sender,
                    'Component must be a probe.',
                );

                $sender->on(
                    'probe',
                    static function () use (&$calls): void {
                        ++$calls;
                    }
                );

                $sender->fire();

                return [];
            },
            [
                'useSession' => false,
                'persistentComponents' => ['probe'],
                'components' => ['probe' => ['class' => $class]],
            ]
        );
        $app->handle(request());
        $app->finalize();

        $sender = $app->get('probe');

        self::assertInstanceOf(
            \yii2\extensions\psrbridge\tests\support\scenario\ProbeComponent::class,
            $sender,
            'Component must be a probe.',
        );

        $sender->fire();

        self::assertSame(
            1,
            $calls,
            'Temporary listener must be removed after finalize.',
        );
    }

    public function testReplacementResponseDoesNotConstructAnUnloadedSessionDefinition(): void
    {
        $created = 0;

        $app = application(
            static function () use (&$created): Response {
                Yii::$app->set(
                    'session',
                    static function () use (&$created): Session {
                        ++$created;
                        return new Session();
                    }
                );

                return new Response(['format' => Response::FORMAT_RAW]);
            }
        );

        $app->handle(request());

        self::assertSame(
            0,
            $created,
            'Unloaded session must not be constructed.',
        );

        $app->finalize();
    }

    public function testReplacementResponseKeepsExplicitValidationPolicyWhenSynchronizationIsDisabled(): void
    {
        $app = application(
            static function (): Response {
                Yii::$app->session->open();

                return new Response(['format' => Response::FORMAT_RAW, 'enableCookieValidation' => false]);
            },
            ['syncCookieValidation' => false]
        );

        $response = $app->handle(request());

        self::assertSame(
            $app->session->getId(),
            cookies($response)[$app->session->getName()] ?? null,
            'Explicit validation policy must yield an unsigned cookie.',
        );

        $app->finalize();
    }

    #[TestWith([true, true, Session::class])]
    #[TestWith([true, false, Session::class])]
    #[TestWith([false, true, Session::class])]
    #[TestWith([false, false, Session::class])]
    #[TestWith([true, true, \yii\web\Session::class])]
    #[TestWith([true, false, \yii\web\Session::class])]
    public function testRotatedIdentitySurvivesExplicitClose(bool $eager, bool $close, string $sessionClass): void
    {
        $app = application(
            static function () use ($close): array {
                if (Yii::$app->request->headers->has('X-Login')) {
                    Yii::$app->session->open();
                    Yii::$app->user->login(Identity::findIdentity('100')
                        ?? throw new \RuntimeException('Missing identity.'));

                    if ($close) {
                        Yii::$app->session->close();
                    }
                }

                return ['guest' => Yii::$app->user->getIsGuest(), 'id' => Yii::$app->session->getId()];
            },
            [
                'useSession' => $eager,
                'components' => ['session' => ['class' => $sessionClass]],
            ],
        );

        $login = $app->handle(request()->withHeader('X-Login', '1'));

        $data = self::decode((string) $login->getBody());

        $jar = cookies($login);

        $signed = $app->security->validateData(
            $jar[$app->session->getName()] ?? throw new RuntimeException('Missing session cookie.'),
            'worker-regression-key',
        );

        self::assertIsString(
            $signed,
            'Cookie signature must validate.',
        );
        self::assertSame(
            [$app->session->getName(), $data['id'] ?? null],
            unserialize($signed, ['allowed_classes' => false]),
            'Cookie must carry the rotated session ID.',
        );

        $app->finalize();

        $returning = $app->handle(request($jar));

        self::assertFalse(
            self::decode((string) $returning->getBody())['guest'] ?? null,
            'Identity must survive across requests.',
        );

        $app->finalize();
    }

    public function testSharedPersistentAliasesRestoreOneConfiguredHandler(): void
    {
        $counts = [0, 0];
        $class = \yii2\extensions\psrbridge\tests\support\scenario\ProbeComponent::class;

        $app = application(
            static function (): array {
                $component = Yii::$app->get('alpha');

                self::assertInstanceOf(
                    \yii2\extensions\psrbridge\tests\support\scenario\ProbeComponent::class,
                    $component,
                    'Component must be a probe.',
                );

                $component->fire();

                return [];
            },
            [
                'useSession' => false,
                'container' => ['singletons' => [$class => $class]],
                'persistentComponents' => ['alpha', 'beta'],
                'components' => [
                    'alpha' => ['class' => $class, 'on probe' => static function () use (&$counts): void {
                        ++$counts[0];
                    }],
                    'beta' => ['class' => $class, 'on probe' => static function () use (&$counts): void {
                        ++$counts[1];
                    }],
                ],
            ],
        );

        $app->handle(request());

        self::assertSame(
            $app->get('alpha'),
            $app->get('beta'),
            'Aliases must share one instance.',
        );

        $app->finalize();

        $component = $app->get('alpha');

        self::assertInstanceOf(
            \yii2\extensions\psrbridge\tests\support\scenario\ProbeComponent::class,
            $component,
            'Component must be a probe.',
        );

        $component->fire();

        self::assertSame(
            [2, 0],
            $counts,
            'Only one configured handler must be restored.',
        );
    }

    public function testStandaloneConversionDoesNotInstantiateUnusedSession(): void
    {
        $app = application(static fn(): array => [], ['useSession' => false]);

        $app->handle(request());
        $app->finalize();

        $created = 0;

        $app->set(
            'session',
            static function () use (&$created): Session {
                ++$created;

                return new Session();
            }
        );

        (new Response(['charset' => 'UTF-8']))->getPsr7Response();

        self::assertSame(
            0,
            $created,
            'Unused session must not be instantiated.',
        );
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testTemporaryEventCleanupKeepsDistinctSenders(bool $collidingSuffix): void
    {
        $app = application(static fn(): array => [], ['useSession' => false]);

        $app->handle(request());

        $pool = [];
        $first = new Component();
        $prefix = (string) spl_object_id($first);
        $second = null;

        for ($i = 0; $i < 100000; ++$i) {
            $candidate = new Component();
            $id = (string) spl_object_id($candidate);
            $pool[] = $candidate;

            if (strlen($id) > strlen($prefix) && str_starts_with($id, $prefix)) {
                $second = $candidate;

                break;
            }
        }

        self::assertNotEmpty(
            $pool,
            'Candidate pool must not be empty.',
        );
        self::assertInstanceOf(
            Component::class,
            $second,
            'The bounded pool must provide prefix-related live IDs.',
        );

        $a = $collidingSuffix ? substr((string) spl_object_id($second), strlen($prefix)) . 'probe' : 'probe';
        $b = 'probe';
        $counts = [0, 0];

        $first->on(
            $a,
            static function () use (&$counts): void {
                ++$counts[0];
            }
        );
        $second->on(
            $b,
            static function () use (&$counts): void {
                ++$counts[1];
            }
        );
        $first->trigger($a);
        $second->trigger($b);
        $app->finalize();
        $first->trigger($a);
        $second->trigger($b);

        self::assertSame(
            [1, 1],
            $counts,
            'Cleanup must remove only temporary listeners.',
        );
    }

    public function testUnusedLazySessionDoesNotRefreshAnIncomingCookie(): void
    {
        $app = application(static fn(): array => [], ['useSession' => false]);

        $cookie = (new \yii\base\Security())
            ->hashData(serialize(['PHPSESSID', 'valid-but-unused']), 'worker-regression-key');
        $response = $app->handle(request(['PHPSESSID' => $cookie]));

        self::assertArrayNotHasKey(
            'PHPSESSID',
            cookies($response),
            'Unused session must not refresh the cookie.',
        );
        self::assertFalse(
            $app->session->getIsActive(),
            'Unused session must stay inactive.',
        );

        $app->finalize();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/bridge-boundary-' . uniqid();

        mkdir($this->directory, 0o700);
        ini_set('session.use_cookies', '0');
        ini_set('session.save_path', $this->directory);
    }

    protected function tearDown(): void
    {
        if (Yii::$app !== null && Yii::$app->has('errorHandler', true)) {
            Yii::$app->errorHandler->unregister();
        }

        parent::tearDown();

        $files = glob($this->directory . '/*');

        self::assertIsArray(
            $files,
            'Session directory listing must succeed.',
        );

        foreach ($files as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $body): array
    {
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray(
            $data,
            'Decoded body must be an array.',
        );

        return $data;
    }
}
