<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Yii;
use yii\base\{Event, InvalidConfigException};
use yii\di\{Container, NotInstantiableException};
use yii\web\{Application, UploadedFile};

use function array_merge;
use function array_reverse;
use function function_exists;
use function gc_collect_cycles;
use function ini_get;
use function is_array;
use function memory_get_usage;
use function method_exists;
use function microtime;
use function runkit_constant_redefine;
use function sscanf;
use function strtoupper;

/**
 * Stateless Yii2 Application with PSR-7 RequestHandler integration for worker and SAPI environments.
 *
 * Provides a Yii2 application implementation designed for stateless operation and seamless interoperability with PSR-7
 * compatible HTTP stacks and modern PHP runtimes.
 *
 * This class implements {@see RequestHandlerInterface} to support direct handling of PSR-7 ServerRequestInterface
 * instances, enabling integration with worker-based environments and SAPI.
 *
 * It manages the full application lifecycle, including request/response handling, event tracking, session management,
 * and error handling, while maintaining strict type safety and immutability throughout the process.
 *
 * Key features.
 * - Event tracking and cleanup for robust lifecycle management.
 * - Exception-safe error handling and response conversion.
 * - Immutable, type-safe application state management.
 * - PSR-7 RequestHandlerInterface implementation for direct PSR-7 integration.
 * - Session and user management from PSR-7 cookies.
 * - Stateless, repeatable request handling for worker and SAPI runtimes.
 *
 * @see RequestHandlerInterface for PSR-7 request handling contract.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class StatelessApplication extends Application implements RequestHandlerInterface
{
    /**
     * Version of the StatelessApplication.
     */
    public string $version = '0.1.0';

    /**
     * Configuration for the StatelessApplication.
     *
     * @phpstan-var array<string, mixed>
     */
    private array $config = [];

    /**
     * Container for dependency injection.
     */
    private Container|null $container = null;

    /**
     * Event handler for tracking events.
     *
     * @phpstan-var callable(Event $event): void
     */
    private $eventHandler;

    /**
     * Memory limit for the StatelessApplication.
     */
    private int|null $memoryLimit = null;

    /**
     * Registered events during the application lifecycle.
     *
     * @phpstan-var array<Event>
     */
    private array $registeredEvents = [];

    /**
     * Flag to indicate if memory limit should be recalculated.
     */
    private bool $shouldRecalculateMemoryLimit = false;

    /**
     * Creates a new instance of the {@see StatelessApplication} class.
     *
     * @param array $config Configuration for the StatelessApplication.
     *
     * @phpstan-param array<string, mixed> $config
     *
     * @phpstan-ignore constructor.missingParentCall
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;

        $this->initEventTracking();
    }

    /**
     * Performs memory cleanup and checks if memory usage exceeds the configured threshold.
     *
     * Invokes garbage collection cycles and compares the current memory usage against 90% of the configured memory
     * limit.
     *
     * This method is used to determine if the application should be recycled or restarted based on memory consumption,
     * supporting stateless operation in worker and SAPI environments.
     *
     * @return bool `true` if memory usage is greater than or equal to 90% of the memory limit, `false` otherwise.
     *
     * Usage example:
     * ```php
     * if ($app->clean()) {
     *     // trigger worker recycle or restart
     * }
     * ```
     */
    public function clean(): bool
    {
        gc_collect_cycles();

        $limit = $this->getMemoryLimit();

        $bound = $limit * 0.9;

        $usage = memory_get_usage(true);

        return $usage >= $bound;
    }

    /**
     * Returns the dependency injection container for the StatelessApplication.
     *
     * Provides access to the Yii2 {@see Container} instance, configured with definitions and singletons from the
     * application configuration. If the container is not already initialized, it is created using the provided
     * configuration and cached for subsequent calls.
     *
     * This method enables type-safe dependency resolution and service management within the application lifecycle,
     * supporting stateless operation and PSR-7 integration.
     *
     * @return Container Yii2 dependency injection container instance for the application.
     *
     * Usage example:
     * ```php
     * $container = $app->container();
     * $service = $container->get(MyService::class);
     * ```
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
     * Returns the core components configuration for the StatelessApplication.
     *
     * Provides the array of core Yii2 components required for stateless operation, including error handler, request,
     * response, session, and user components.
     *
     * This configuration ensures that the application is initialized with PSR-7 bridge support and compatible with
     * worker and SAPI environments.
     *
     * @return array Array of core component configurations for the application.
     *
     * @phpstan-return array<mixed, mixed>
     *
     * Usage example:
     * ```php
     * $components = $app->coreComponents();
     * ```
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
     * Returns the memory limit for the StatelessApplication in bytes.
     *
     * If the memory limit is not set or recalculation is required, this method retrieves the system memory limit
     * using {@see getSystemMemoryLimit()}, parses it to an integer value in bytes, and stores it for future access.
     *
     * This ensures that the application always operates with an up-to-date memory limit, supporting dynamic
     * recalculation when needed.
     *
     * @return int Memory limit in bytes for the StatelessApplication.
     *
     * Usage example:
     * ```php
     * $limit = $app->getMemoryLimit();
     * ```
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
     * Handles a PSR-7 ServerRequestInterface and returns a PSR-7 ResponseInterface.
     *
     * Processes the full Yii2 application lifecycle for a stateless request, including event triggering, request
     * handling, and error management.
     *
     * This method resets the application state, triggers lifecycle events, executes the request, and converts the
     * result to a PSR-7 ResponseInterface.
     *
     * If an exception occurs during processing, it is handled and converted to a PSR-7 response.
     *
     * @param ServerRequestInterface $request PSR-7 ServerRequestInterface instance to handle.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return ResponseInterface PSR-7 ResponseInterface instance representing the result of the handled request.
     *
     * Usage example:
     * ```php
     * $psrResponse = $app->handle($psrRequest);
     * ```
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->reset($request);

            $this->state = self::STATE_BEFORE_REQUEST;

            $this->trigger(self::EVENT_BEFORE_REQUEST);

            $this->state = self::STATE_HANDLING_REQUEST;

            /** @phpstan-var Response $response */
            $response = $this->handleRequest($this->request);

            $this->state = self::STATE_AFTER_REQUEST;

            $this->trigger(self::EVENT_AFTER_REQUEST);

            $this->state = self::STATE_END;

            return $this->terminate($response);
        } catch (Throwable $e) {
            return $this->terminate($this->handleError($e));
        }
    }

    /**
     * Initializes the StatelessApplication state to 'STATE_INIT'.
     *
     * Sets the internal application state to {@see self::STATE_INIT}, preparing the application for initialization and
     * lifecycle event tracking.
     *
     * This method is called during the application bootstrap process to ensure the application state is initialized
     * before handling requests or triggering events.
     *
     * Usage example:
     * ```php
     * $app->init();
     * ```
     */
    public function init(): void
    {
        $this->state = self::STATE_INIT;
    }

    /**
     * Sets the memory limit for the StatelessApplication.
     *
     * - If the provided limit is less than or equal to zero, the memory limit will be recalculated from the system
     *   configuration on the next access.
     * - Otherwise, sets the memory limit to the specified value in bytes and disables recalculation.
     *
     * @param int $limit Memory limit in bytes. Use a value less than or equal to zero to trigger recalculation from
     * system settings.
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
     * Retrieves the current memory limit from the PHP configuration.
     *
     * @return string Memory limit value from ini_get('memory_limit').
     */
    protected function getSystemMemoryLimit(): string
    {
        return ini_get('memory_limit');
    }

    /**
     * Parses a PHP memory limit string and converts it to bytes.
     *
     * Supports the following formats.
     * - '-1' for unlimited (returns 'PHP_INT_MAX').
     * - Numeric values with suffix: K (kilobytes), M (megabytes), G (gigabytes).
     * - Plain numeric values (bytes).
     *
     * @param string $limit Memory limit string to parse.
     *
     * @return int Memory limit in bytes, or 'PHP_INT_MAX' if unlimited.
     */
    protected static function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $number = 0;
        $suffix = null;

        sscanf($limit, '%u%c', $number, $suffix);

        if ($suffix !== null) {
            $multipliers = [
                ' ' => 1,
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
     * Resets the StatelessApplication state and prepares the Yii2 environment for handling a PSR-7 request.
     *
     * Performs a full reinitialization of the application state, including event tracking, error handler cleanup,
     * session management, and PSR-7 request injection.
     *
     * This method ensures that the application is ready to process a new stateless request in worker or SAPI
     * environments, maintaining strict type safety and compatibility with Yii2 core components.
     *
     * This method is called internally before each request is handled to guarantee stateless, repeatable operation.
     *
     * @param ServerRequestInterface $request PSR-7 ServerRequestInterface instance to inject into the application.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    protected function reset(ServerRequestInterface $request): void
    {
        // override 'YII_BEGIN_TIME' if possible for yii2-debug and other modules that depend on it
        if (function_exists('runkit_constant_redefine')) {
            @runkit_constant_redefine('YII_BEGIN_TIME', microtime(true));
        }

        $this->startEventTracking();

        if ($this->has('errorHandler')) {
            $this->errorHandler->unregister();
        }

        // parent constructor is called because StatelessApplication uses a custom initialization pattern
        // @phpstan-ignore-next-line
        parent::__construct($this->config);

        $this->requestedRoute = '';
        $this->requestedAction = null;
        $this->requestedParams = [];

        $this->request->setPsr7Request($request);

        $this->session->close();
        $sessionId = $this->request->getCookies()->get($this->session->getName())->value ?? '';
        $this->session->setId($sessionId);

        // start the session with the correct 'ID'
        $this->session->open();

        $this->bootstrap();

        $this->session->close();
    }

    /**
     * Finalizes the application lifecycle and converts the Yii2 Response to a PSR-7 ResponseInterface.
     *
     * Cleans up registered events, resets uploaded files, flushes the logger, and resets the request state.
     *
     * This method ensures that all application resources are released and the response is converted to a PSR-7
     * ResponseInterface for interoperability with PSR-7 compatible HTTP stacks.
     *
     * @param Response $response Response instance to convert and finalize.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws NotInstantiableException if a class or service can't be instantiated.
     *
     * @return ResponseInterface PSR-7 ResponseInterface instance representing the finalized response.
     */
    protected function terminate(Response $response): ResponseInterface
    {
        $this->cleanupEvents();

        UploadedFile::reset();

        Yii::getLogger()->flush(true);

        $this->request->reset();

        return $response->getPsr7Response();
    }

    /**
     * Cleans up all registered events and resets event tracking for the application lifecycle.
     *
     * Removes all event handlers registered during the application lifecycle, including global and sender-specific
     * events, ensuring that no lingering event listeners remain after request processing.
     *
     * This method iterates over all registered events in reverse order, detaching each from its sender if possible, and
     * clears the internal event registry.
     *
     * This cleanup is essential for stateless operation in worker and SAPI environments, preventing memory leaks and
     * ensuring repeatable request handling.
     *
     * After all events are removed, global event tracking is reset to maintain a clean application state.
     */
    private function cleanupEvents(): void
    {
        Event::off('*', '*', $this->eventHandler);

        foreach (array_reverse($this->registeredEvents) as $event) {
            if ($event->sender !== null && method_exists($event->sender, 'off')) {
                $event->sender->off($event->name);
            }
        }

        $this->registeredEvents = [];

        Event::offAll();
    }

    /**
     * Handles application errors and returns a Yii2 Response instance.
     *
     * Invokes the configured error handler to process the exception and generate a response, then triggers the
     * {@see self::EVENT_AFTER_REQUEST} event and sets the application state to {@see self::STATE_END}.
     *
     * This method ensures that all errors are handled consistently and the application lifecycle is finalized after
     * an exception occurs.
     *
     * @param Throwable $exception Exception instance to handle.
     *
     * @return Response Response instance generated by the error handler.
     */
    private function handleError(Throwable $exception): Response
    {
        $response = $this->errorHandler->handleException($exception);

        $this->trigger(self::EVENT_AFTER_REQUEST);

        $this->state = self::STATE_END;

        return $response;
    }

    /**
     * Initializes the event tracking handler for the application lifecycle.
     *
     * Sets up the internal event handler used to register events during the application lifecycle.
     *
     * The handler appends each triggered {@see Event} instance to the internal registry for later cleanup.
     *
     * This method ensures that all events are tracked and can be detached after request processing, supporting
     * stateless operation and preventing memory leaks in worker and SAPI environments.
     */
    private function initEventTracking(): void
    {
        $this->eventHandler = function (Event $event): void {
            $this->registeredEvents[] = $event;
        };
    }

    /**
     * Registers the global event handler for application lifecycle event tracking.
     *
     * Attaches the internal event handler to all events and senders using Yii2 global event registration, enabling the
     * application to track every triggered event during the request lifecycle.
     *
     * This method ensures that all events are captured and appended to the internal registry for later cleanup,
     * supporting stateless operation and preventing memory leaks in worker and SAPI environments.
     */
    private function startEventTracking(): void
    {
        Event::on('*', '*', $this->eventHandler);
    }
}
