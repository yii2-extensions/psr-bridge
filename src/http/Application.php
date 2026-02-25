<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use yii\base\{Event, InvalidConfigException};
use yii\di\{Container, NotInstantiableException};
use yii\web\IdentityInterface;

use function array_merge;
use function array_reverse;
use function gc_collect_cycles;
use function in_array;
use function ini_get;
use function is_array;
use function memory_get_usage;
use function method_exists;
use function sscanf;
use function strtoupper;

/**
 * Handles PSR-7 requests with a stateless Yii application lifecycle.
 *
 * @template TUserIdentity of IdentityInterface
 * @extends \yii\web\Application<TUserIdentity>
 *
 * {@see RequestHandlerInterface} PSR-7 request handler contract.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
class Application extends \yii\web\Application implements RequestHandlerInterface
{
    /**
     * Flushes the logger during {@see terminate()} when set to `true`.
     */
    public bool $flushLogger = true;

    /**
     * Lists component IDs that must be reinitialized for each request in long-running workers.
     *
     * Components not listed here keep their resolved instances across requests.
     *
     * @var array<int, string>
     */
    public array $requestScopedComponents = ['request', 'response', 'errorHandler', 'session', 'user', 'urlManager'];

    /**
     * Controls whether uploaded file static state is reset for each request.
     *
     * Set to `false` to retain static uploaded-file state across requests (advanced use only).
     */
    public bool $resetUploadedFiles = true;

    /**
     * Controls whether cookie validation settings are synchronized between request and response.
     *
     * Set to `false` to skip request-to-response cookie validation key synchronization.
     */
    public bool $syncCookieValidation = true;

    /**
     * Controls whether session lifecycle hooks run for each request.
     *
     * Set to `false` to skip bridge session open/finalize hooks for each request.
     */
    public bool $useSession = true;

    /**
     * Defines the application version string.
     */
    public string $version = '0.1.0';

    /**
     * Caches the dependency injection container instance.
     */
    private Container|null $container = null;

    /**
     * Stores the event handler used for global lifecycle tracking.
     *
     * @phpstan-var callable(Event $event): void
     */
    private $eventHandler;

    /**
     * Caches the memory limit in bytes.
     */
    private int|null $memoryLimit = null;

    /**
     * Stores events registered during request handling.
     *
     * @phpstan-var array<Event>
     */
    private array $registeredEvents = [];

    /**
     * Tracks whether the global Yii container was configured for this worker process.
     */
    private bool $shouldConfigureGlobalContainer = true;

    /**
     * Indicates whether to recalculate the memory limit.
     */
    private bool $shouldRecalculateMemoryLimit = false;

    /**
     * Initializes a new application instance.
     *
     * @param array $config Application configuration.
     *
     * @phpstan-param mixed[] $config
     * @phpstan-ignore constructor.missingParentCall
     */
    public function __construct(private array $config = [])
    {
        $this->initEventTracking();
    }

    /**
     * Runs garbage collection and checks whether memory usage reached '90%' of the current limit.
     *
     * Usage example:
     * ```php
     * $app = new \yii2\extensions\psrbridge\http\Application($config);
     *
     * if ($app->clean()) {
     *     // recycle worker
     * }
     * ```
     *
     * @return bool Returns `true` when memory usage is at least '90%' of the configured limit.
     */
    public function clean(): bool
    {
        gc_collect_cycles();

        $limit = $this->getMemoryLimit();

        $bound = $limit * 0.9;

        $usage = memory_get_usage(true);

        // @codeCoverageIgnoreStart
        return $usage >= $bound;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Returns the configured Yii dependency injection container.
     *
     * Usage example:
     * ```php
     * $app = new \yii2\extensions\psrbridge\http\Application($config);
     * $container = $app->container();
     * $service = $container->get(MyService::class);
     * ```
     *
     * @return Container Container configured from `container.definitions` and `container.singletons`.
     */
    public function container(): Container
    {
        $config = $this->config['container'] ?? [];

        return $this->container ??= new Container(
            [
                'definitions' => is_array($config) && isset($config['definitions']) ? $config['definitions'] : [],
                'singletons' => is_array($config) && isset($config['singletons']) ? $config['singletons'] : [],
            ],
        );
    }

    /**
     * Returns the core Yii component configuration with PSR bridge overrides.
     *
     * Usage example:
     * ```php
     * $app = new \yii2\extensions\psrbridge\http\Application($config);
     * $components = $app->coreComponents();
     * ```
     *
     * @return array Core component definitions.
     * @phpstan-return array<mixed, mixed>
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
     * Returns the memory limit in bytes.
     *
     * Usage example:
     * ```php
     * $app = new \yii2\extensions\psrbridge\http\Application($config);
     * $limit = $app->getMemoryLimit();
     * ```
     *
     * @return int Parsed memory limit in bytes.
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
     * Handles one PSR-7 request and returns a PSR-7 response.
     *
     * Usage example:
     * ```php
     * $app = new \yii2\extensions\psrbridge\http\Application($config);
     * $psrResponse = $app->handle($psrRequest);
     * ```
     *
     * @param ServerRequestInterface $request Request to process.
     * @throws InvalidConfigException When application configuration is invalid.
     * @return ResponseInterface Response produced by the Yii request lifecycle.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->prepareForRequest($request);

            $this->state = self::STATE_BEFORE_REQUEST;

            $this->trigger(self::EVENT_BEFORE_REQUEST);

            $this->state = self::STATE_HANDLING_REQUEST;

            /** @phpstan-var Response $response */
            $response = $this->handleRequest($this->request);

            $this->state = self::STATE_AFTER_REQUEST;

            $this->trigger(self::EVENT_AFTER_REQUEST);

            $this->state = self::STATE_END;

            $psrResponse = $this->terminate($response);
        } catch (Throwable $e) {
            $psrResponse = $this->terminate($this->handleError($e));
        }

        return $psrResponse;
    }

    /**
     * Sets the application state to `STATE_INIT`.
     */
    public function init(): void
    {
        $this->state = self::STATE_INIT;
    }

    /**
     * Sets the memory limit in bytes.
     *
     * Usage example:
     * ```php
     * $app = new \yii2\extensions\psrbridge\http\Application($config);
     * $app->setMemoryLimit(134217728);
     * $app->setMemoryLimit(0); // recalculate from system setting
     * ```
     *
     * @param int $limit Memory limit in bytes, or a value less than or equal to `0` to recalculate.
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
        // inject the PSR-7 request into the Yii Request adapter
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
     * Opens the session using the session ID from request cookies.
     */
    protected function openSessionFromRequestCookies(): void
    {
        // reset the session to ensure a clean state
        // @codeCoverageIgnoreStart
        $this->session->close();
        // @codeCoverageIgnoreEnd

        $sessionId = $this->request->getCookies()->get($this->session->getName())->value ?? '';
        $this->session->setId($sessionId);

        // start the session with the correct 'ID'
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
     * @throws InvalidConfigException When application configuration is invalid.
     */
    protected function prepareForRequest(ServerRequestInterface $request): void
    {
        $this->startEventTracking();
        $this->reinitializeApplication();

        if ($this->resetUploadedFiles) {
            $this->resetUploadedFilesState();
        }

        $this->resetRequestState();
        $this->prepareErrorHandler();
        $this->attachPsrRequest($request);

        if ($this->syncCookieValidation) {
            $this->syncCookieValidationState();
        }

        $this->bootstrap();

        if ($this->useSession) {
            $this->openSessionFromRequestCookies();
            $this->finalizeSessionState();
        }
    }

    /**
     * Reinitializes the application instance for the current request.
     *
     * @throws InvalidConfigException When application configuration is invalid.
     */
    protected function reinitializeApplication(): void
    {
        $config = $this->buildReinitializationConfig();

        // parent constructor is called because Application uses a custom initialization pattern
        // @phpstan-ignore-next-line
        parent::__construct($config);

        $this->shouldConfigureGlobalContainer = false;
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
        // synchronize cookie validation settings between request and response
        $this->response->cookieValidationKey = $this->request->cookieValidationKey;
        $this->response->enableCookieValidation = $this->request->enableCookieValidation;
    }

    /**
     * Finalizes request handling and returns a PSR-7 response.
     *
     * @param Response $response Yii response to finalize.
     * @throws InvalidConfigException When configuration is invalid.
     * @throws NotInstantiableException When a service cannot be instantiated.
     * @return ResponseInterface Final PSR-7 response.
     */
    protected function terminate(Response $response): ResponseInterface
    {
        $this->cleanupEvents();

        if ($this->flushLogger) {
            $this->getLog()->getLogger()->flush(true);
        }

        return $response->getPsr7Response();
    }

    /**
     * Builds the configuration used to reinitialize the Yii application for a new request.
     *
     * Reconfigures only request-scoped components after the first request and avoids reconfiguring
     * the global Yii container after worker initialization.
     *
     * @return array Reinitialization configuration for {@see parent::__construct()}.
     * @phpstan-return array<mixed, mixed>
     */
    private function buildReinitializationConfig(): array
    {
        $config = $this->config;

        if ($this->shouldConfigureGlobalContainer === false) {
            unset($config['container']);
        }

        if (isset($config['components']) === false || is_array($config['components']) === false) {
            return $config;
        }

        if ($this->shouldConfigureGlobalContainer === true) {
            return $config;
        }

        foreach ($config['components'] as $id => $_component) {
            if (in_array($id, $this->requestScopedComponents, true) === false) {
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
