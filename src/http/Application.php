<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use yii\base\{Event, InvalidConfigException};
use yii\di\NotInstantiableException;
use yii\web\IdentityInterface;

use function array_merge;
use function array_reverse;
use function fnmatch;
use function gc_collect_cycles;
use function ini_get;
use function is_array;
use function is_string;
use function memory_get_usage;
use function method_exists;
use function preg_match;
use function sscanf;
use function strtoupper;

/**
 * Handles PSR-7 requests with a stateless Yii application lifecycle.
 *
 * `RequestHandlerInterface` PSR-7 request handler contract.
 *
 * This handler requires post-emission finalization when used by a PSR-15 host. The request lifecycle
 * spans two calls: {@see handle()} runs the request and returns the PSR-7 response (pre-send), then the runtime emits
 * it and MUST call {@see finalize()} to complete Yii's after-send lifecycle and release per-request worker state.
 *
 * Calling {@see handle()} without {@see finalize()} leaves event tracking attached across requests in workers.
 *
 * @template TUserIdentity of IdentityInterface
 * @extends \yii\web\Application<TUserIdentity>
 */
class Application extends \yii\web\Application implements RequestHandlerInterface
{
    /**
     * Flushes the logger during {@see finalize()} when set to `true`.
     */
    public bool $flushLogger = true;

    /**
     * Lists component IDs that should stay alive across requests in long-running workers.
     *
     * Keep request-sensitive components (such as `request` or `response`) out of this list.
     *
     * @var array<int, string>
     */
    public array $persistentComponents = ['db', 'cache'];

    /**
     * Retained for configuration compatibility; request preparation always resets uploaded file state.
     *
     * Setting this property to `false` does not disable the reset.
     */
    public bool $resetUploadedFiles = true;

    /**
     * Controls whether cookie validation settings are synchronized between request and response.
     *
     * Set to `false` to skip request-to-response cookie validation key synchronization.
     */
    public bool $syncCookieValidation = true;

    /**
     * Controls whether the session is opened before bootstrap and closed after it for each request.
     *
     * The bridge always isolates PHP session state between requests through {@see syncSessionIdFromRequestCookies()}:
     * it closes a session left active and applies the session `ID` from the request cookie (or an empty `ID` when the
     * cookie is missing or malformed), so a lazily opened session belongs to the current visitor. Set to `false` to
     * skip only the eager {@see openSessionFromRequestCookies()} and {@see finalizeSessionState()} hooks.
     */
    public bool $useSession = true;

    /**
     * Defines the application version string.
     */
    public string $version = '0.3.0';

    /**
     * Stores the event handler used for global lifecycle tracking.
     *
     * @var callable(Event): void
     */
    private $eventHandler;

    /**
     * Stores the converted Yii response awaiting after-send finalization.
     */
    private Response|null $lastResponse = null;

    /**
     * Caches the memory limit in bytes.
     */
    private int|null $memoryLimit = null;

    /**
     * Stores events registered during request handling.
     *
     * @var array<Event>
     */
    private array $registeredEvents = [];

    /**
     * Indicates whether to recalculate the memory limit.
     */
    private bool $shouldRecalculateMemoryLimit = false;

    /**
     * Initializes a new application instance.
     *
     * @param array<mixed> $config Application configuration.
     *
     * @phpstan-ignore constructor.missingParentCall
     */
    public function __construct(private readonly array $config = [])
    {
        $this->initEventTracking();
        $this->bootstrapContainer();
    }

    /**
     * Runs garbage collection and reports whether allocated memory reached 90% of the configured limit.
     *
     * The runtime decides whether to recycle the worker. An unlimited PHP memory setting maps to `PHP_INT_MAX`.
     *
     * @return bool Whether allocated memory is at least 90% of the limit.
     */
    public function clean(): bool
    {
        gc_collect_cycles();

        $limit = $this->getMemoryLimit();

        // @codeCoverageIgnoreStart
        return memory_get_usage(true) >= $limit * 0.9;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Returns Yii core components with bridge request, response, and error handler definitions.
     *
     * @return array<mixed> Core component definitions.
     */
    public function coreComponents(): array
    {
        return array_merge(
            parent::coreComponents(),
            [
                'errorHandler' => [
                    'class' => ErrorHandler::class,
                ],
                'request' => [
                    'class' => Request::class,
                ],
                'response' => [
                    'class' => Response::class,
                ],
            ],
        );
    }

    /**
     * Completes after-send processing and worker cleanup following the runtime's emission attempt.
     *
     * On success, calls {@see Response::completeSend()} on the response returned by {@see handle()}. Pass `false`
     * when emission failed to skip after-send. Tracked event cleanup runs even if an after-send handler throws;
     * logger flushing runs when enabled and a converted response is available.
     *
     * Call exactly once per emission attempt. Keep this call outside an emitter catch block so an after-send
     * exception is not treated as an emission failure and does not cause a second call. See the class example.
     *
     * @param bool $success Whether emission completed successfully.
     *
     * @throws Throwable when an after-send handler, event cleanup, or logger flush fails.
     */
    public function finalize(bool $success = true): void
    {
        try {
            if ($success) {
                $this->lastResponse?->completeSend();
            }
        } finally {
            $this->cleanupEvents();

            if ($this->flushLogger && $this->lastResponse !== null) {
                $this->getLog()->getLogger()->flush(true);
            }

            $this->lastResponse = null;
        }
    }

    /**
     * Returns the cached memory limit, parsing PHP's setting when needed.
     *
     * @return int Limit in bytes, or `PHP_INT_MAX` for an unlimited PHP setting.
     */
    public function getMemoryLimit(): int
    {
        if ($this->memoryLimit === null || $this->shouldRecalculateMemoryLimit) {
            $this->memoryLimit = self::parseMemoryLimit($this->getSystemMemoryLimit());

            $this->shouldRecalculateMemoryLimit = false;
        }

        return $this->memoryLimit;
    }

    /**
     * Handles a PSR-7 request and returns the prepared response before emission.
     *
     * The runtime must emit this response and call {@see finalize()}. Application and request state are
     * reinitialized before handling; bootstrap observes the current request's session state.
     *
     * @param ServerRequestInterface $request Request to process.
     *
     * @throws Throwable When initialization, error handling, or response conversion cannot produce a response.
     *
     * @return ResponseInterface Prepared response; after-send processing remains deferred.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->prepareForRequest($request);

            $this->state = self::STATE_BEFORE_REQUEST;

            $this->trigger(self::EVENT_BEFORE_REQUEST);

            $this->state = self::STATE_HANDLING_REQUEST;

            /** @var Response $response */
            $response = $this->handleRequest($this->request);

            $this->state = self::STATE_AFTER_REQUEST;

            $this->trigger(self::EVENT_AFTER_REQUEST);

            $this->state = self::STATE_END;

            return $this->terminate($response);
        } catch (Throwable $e) {
            if (!$this->has('errorHandler')) {
                $this->cleanupEvents();

                throw $e;
            }

            return $this->terminate($this->handleError($e));
        }
    }

    /**
     * Sets the per-request state to `STATE_INIT`; bootstrap runs after the PSR request is attached.
     */
    public function init(): void
    {
        $this->state = self::STATE_INIT;
    }

    /**
     * Overrides the cached worker memory threshold or requests recalculation from PHP's setting.
     *
     * @param int $limit Limit in bytes; a non-positive value recalculates on the next {@see getMemoryLimit()} call.
     */
    public function setMemoryLimit(int $limit): void
    {
        if ($limit <= 0) {
            $this->shouldRecalculateMemoryLimit = true;
            $this->memoryLimit = null;
        } else {
            $this->memoryLimit = $limit;
            $this->shouldRecalculateMemoryLimit = false;
        }
    }

    /**
     * Attaches the PSR-7 request to the Yii request adapter.
     *
     * @param ServerRequestInterface $request Request to attach.
     */
    protected function attachPsrRequest(ServerRequestInterface $request): void
    {
        $this->request->setPsr7Request($request);
    }

    /**
     * Closes the session after bootstrap completes.
     */
    protected function finalizeSessionState(): void
    {
        // @codeCoverageIgnoreStart
        $this->session->close();
        // @codeCoverageIgnoreEnd
    }

    /**
     * Opens the session before bootstrap when {@see useSession} is `true`.
     *
     * Runs after {@see syncSessionIdFromRequestCookies()}, so the session resumes the `ID` from the request cookie or
     * starts with a new one.
     */
    protected function openSessionFromRequestCookies(): void
    {
        // @codeCoverageIgnoreStart
        $this->session->open();
        // @codeCoverageIgnoreEnd
    }

    /**
     * Resets the error handler response target for the current request.
     */
    protected function prepareErrorHandler(): void
    {
        // re-register error handler to reset its state
        $this->errorHandler->setResponse($this->response);
    }

    /**
     * Prepares the application state for a new PSR-7 request.
     *
     * @param ServerRequestInterface $request Request to bind to the Yii adapter.
     *
     * @throws InvalidConfigException when application configuration is invalid.
     */
    protected function prepareForRequest(ServerRequestInterface $request): void
    {
        $this->startEventTracking();
        $this->reinitializeApplication();

        $this->resetUploadedFilesState();

        $this->resetRequestState();
        $this->prepareErrorHandler();
        $this->attachPsrRequest($request);

        if ($this->syncCookieValidation) {
            $this->syncCookieValidationState();
        }

        try {
            $this->syncSessionIdFromRequestCookies();

            if ($this->useSession) {
                $this->openSessionFromRequestCookies();
            }

            $this->bootstrap();
        } finally {
            if ($this->useSession) {
                $this->finalizeSessionState();
            }
        }
    }

    /**
     * Reinitializes the application instance for the current request.
     *
     * @throws InvalidConfigException when application configuration is invalid.
     */
    protected function reinitializeApplication(): void
    {
        if ($this->has('errorHandler')) {
            $this->errorHandler->unregister();
        }

        // parent constructor is called because Application uses a custom initialization pattern
        // @phpstan-ignore-next-line
        parent::__construct($this->buildReinitializationConfig());
    }

    /**
     * Resets route and action resolution state.
     */
    protected function resetRequestState(): void
    {
        $this->requestedRoute = '';
        $this->requestedAction = null;
        $this->requestedParams = [];
    }

    /**
     * Resets uploaded file static state for the current request.
     */
    protected function resetUploadedFilesState(): void
    {
        UploadedFile::reset();
    }

    /**
     * Synchronizes cookie validation settings between request and response.
     */
    protected function syncCookieValidationState(): void
    {
        $this->response->cookieValidationKey = $this->request->cookieValidationKey;
        $this->response->enableCookieValidation = $this->request->enableCookieValidation;
    }

    /**
     * Isolates PHP session state for the current request without opening the session.
     *
     * Runs for every request, regardless of {@see useSession}: closes a session left active by a previous request and
     * applies the session `ID` from the request cookie, or an empty `ID` when the request carries no session cookie, so
     * a later `session_start()` resumes the current visitor's session or creates a new one. Request cookies are read
     * only when the session cookie is present. A malformed cookie value (not a `string`, or not accepted by PHP as a
     * session `ID`) is discarded and an empty `ID` is applied instead, so the session starts with a new `ID`.
     */
    protected function syncSessionIdFromRequestCookies(): void
    {
        $this->session->close();

        $sessionName = $this->session->getName();
        $sessionId = '';

        if (isset($this->request->getPsr7Request()->getCookieParams()[$sessionName])) {
            $cookieValue = $this->request->getCookies()->get($sessionName)->value ?? '';

            if ($this->isValidSessionId($cookieValue)) {
                $sessionId = $cookieValue;
            }
        }

        $this->session->setId($sessionId);
    }

    /**
     * Converts the Yii response to PSR-7 and stores it for deferred after-send finalization.
     *
     * Runs the pre-send conversion only. After-send finalization, event cleanup, and logger flushing are deferred to
     * {@see finalize()}, which the runtime calls once the PSR-7 response has been emitted.
     *
     * @param Response $response Yii response to convert.
     *
     * @throws InvalidConfigException when configuration is invalid.
     * @throws NotInstantiableException when a service cannot be instantiated.
     *
     * @return ResponseInterface PSR-7 response produced by the conversion.
     */
    protected function terminate(Response $response): ResponseInterface
    {
        $this->lastResponse = $response;

        return $response->getPsr7Response();
    }

    /**
     * Applies `container` configuration to `Yii::$container` once per worker lifecycle.
     */
    private function bootstrapContainer(): void
    {
        $container = $this->config['container'] ?? [];

        if (is_array($container) && $container !== []) {
            $this->setContainer($container);
        }
    }

    /**
     * Builds the configuration used to reinitialize the Yii application for a new request.
     *
     * Reconfigures only request-scoped components after the first request and avoids reconfiguring
     * the global Yii container after worker initialization.
     *
     * @return array<mixed> Reinitialization configuration for {@see parent::__construct()}.
     */
    private function buildReinitializationConfig(): array
    {
        $config = $this->config;

        unset($config['container']);

        if (!isset($config['components']) || !is_array($config['components'])) {
            return $config;
        }

        foreach ($config['components'] as $id => $_) {
            if (is_string($id) && $this->isPersistentComponent($id) && $this->has($id, true)) {
                unset($config['components'][$id]);
            }
        }

        return $config;
    }

    /**
     * Detaches tracked event handlers and clears lifecycle event state.
     */
    private function cleanupEvents(): void
    {
        // @codeCoverageIgnoreStart
        Event::off('*', '*', $this->eventHandler);
        // @codeCoverageIgnoreEnd

        foreach (array_reverse($this->registeredEvents) as $event) {
            if ($event->sender !== null && method_exists($event->sender, 'off')) {
                $event->sender->off($event->name);
            }
        }

        $this->registeredEvents = [];

        Event::offAll();
    }

    /**
     * Returns the current PHP memory limit value.
     *
     * @return string Value returned by `ini_get('memory_limit')`.
     */
    private function getSystemMemoryLimit(): string
    {
        return ini_get('memory_limit');
    }

    /**
     * Handles an exception and returns the generated Yii response.
     *
     * @param Throwable $exception Exception to process.
     * @return Response Response generated by the error handler.
     */
    private function handleError(Throwable $exception): Response
    {
        $response = $this->errorHandler->handleException($exception);

        $this->trigger(self::EVENT_AFTER_REQUEST);

        $this->state = self::STATE_END;

        return $response;
    }

    /**
     * Initializes the handler that records triggered events.
     */
    private function initEventTracking(): void
    {
        $this->eventHandler = function (Event $event): void {
            $this->registeredEvents[] = $event;
        };
    }

    /**
     * Checks whether a component ID matches any persistent component pattern.
     *
     * Supports exact matches and shell wildcard patterns (e.g. `redis*`, `cache?`).
     *
     * @param string $id Component ID to check.
     *
     * @return bool Returns `true` when the ID matches a persistent component entry.
     */
    private function isPersistentComponent(string $id): bool
    {
        foreach ($this->persistentComponents as $pattern) {
            if (fnmatch($pattern, $id, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks the bridge's session ID syntax: 1 to 256 letters, digits, commas, or hyphens.
     *
     * This does not establish that the ID exists in session storage; strict-mode handling happens when opened.
     *
     * @param mixed $id Cookie value after optional Yii signature validation.
     * @return bool Whether the value is a `string` matching the accepted syntax.
     */
    private function isValidSessionId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9,-]{1,256}$/D', $id) === 1;
    }

    /**
     * Parses a PHP memory limit string into bytes.
     *
     * @param string $limit Memory limit value such as '-1', '256M', or '1024'.
     * @return int Limit in bytes, or PHP_INT_MAX for '-1'.
     */
    private static function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            // @codeCoverageIgnoreStart
            return PHP_INT_MAX;
            // @codeCoverageIgnoreEnd
        }

        $number = 0;
        $suffix = null;

        sscanf($limit, '%u%c', $number, $suffix);

        if ($suffix !== null) {
            $multipliers = [
                'K' => 1024,
                'M' => 1048576,
                'G' => 1073741824,
            ];

            $suffix = strtoupper((string) $suffix);
            $number = (int) $number * ($multipliers[$suffix] ?? 1);
        }

        return (int) $number;
    }

    /**
     * Registers global lifecycle event tracking.
     */
    private function startEventTracking(): void
    {
        Event::on('*', '*', $this->eventHandler);
    }
}
