<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Yii;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\web\Response as WebResponse;
use yii\web\{Session, UploadedFile, User};
use yii2\extensions\psrbridge\errorhandler\ErrorHandler;

use function array_merge;
use function gc_collect_cycles;
use function ini_get;
use function memory_get_usage;
use function sscanf;
use function strtoupper;

final class StatelessApplication extends \yii\web\Application implements RequestHandlerInterface
{
    public string $version = '0.1.0';

    /**
     * @phpstan-var array<string, mixed>
     */
    private array $config = [];

    /**
     * @phpstan-var callable(Event $event): void
     */
    private $eventHandler;

    private int|null $memoryLimit = null;

    /**
     * @phpstan-var array<Event>
     */
    private array $registeredEvents = [];

    /**
     * @phpstan-param array<string, mixed> $config
     *
     * @phpstan-ignore constructor.missingParentCall
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;

        // this is necessary to get \yii\web\Session to work properly.
        ini_set('use_cookies', 'false');
        ini_set('use_only_cookies', 'true');

        $this->memoryLimit = $this->getMemoryLimit();
        $this->initEventTracking();
    }

    public function clean(): bool
    {
        gc_collect_cycles();

        $limit = (int) $this->memoryLimit;
        $bound = $limit * .90;

        $usage = memory_get_usage(true);

        return $usage >= $bound;
    }

    /**
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
                'session' => [
                    'class' => Session::class,
                ],
                'user' => [
                    'class' => User::class,
                ],
            ],
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->reset($request);

            $this->state = self::STATE_BEFORE_REQUEST;

            $this->trigger(self::EVENT_BEFORE_REQUEST);

            $this->state = self::STATE_HANDLING_REQUEST;

            $response = $this->handleRequest($this->getRequest());

            $this->state = self::STATE_AFTER_REQUEST;

            $this->trigger(self::EVENT_AFTER_REQUEST);

            $this->state = self::STATE_END;

            return $this->terminate($response);
        } catch (Throwable $e) {
            return $this->terminate($this->handleError($e));
        }
    }

    public function init(): void
    {
        $this->state = self::STATE_INIT;
    }

    protected function bootstrap(): void
    {
        $request = $this->getRequest();

        Yii::setAlias('@webroot', dirname($request->getScriptFile()));
        Yii::setAlias('@web', $request->getBaseUrl());

        parent::bootstrap();
    }

    protected function reset(ServerRequestInterface $request): void
    {
        // override YII_BEGIN_TIME if possible for yii2-debug and other modules that depend on it
        if (\function_exists('uopz_redefine')) {
            \uopz_redefine('YII_BEGIN_TIME', microtime(true));
        }

        $this->startEventTracking();

        $config = $this->config;

        if ($this->has('errorHandler')) {
            $this->errorHandler->unregister();
        }

        // @phpstan-ignore-next-line
        parent::__construct($config);

        $this->requestedRoute = '';
        $this->requestedAction = null;
        $this->requestedParams = [];

        if ($this->getRequest() instanceof Request) {
            $this->getRequest()->setPsr7Request($request);
        }

        $this->session->close();
        $sessionId = $request->getCookieParams()[$this->session->getName()] ?? null;

        if ($sessionId !== null && is_string($sessionId)) {
            $this->session->setId($sessionId);
        }

        $this->ensureBehaviors();

        $this->session->open();

        $this->bootstrap();

        $this->session->close();
    }

    protected function terminate(WebResponse $response): ResponseInterface
    {
        $this->cleanupEvents();

        UploadedFile::reset();

        Yii::getLogger()->flush(true);

        if ($this->getRequest() instanceof Request) {
            $this->getRequest()->reset();
        }

        if ($response instanceof Response === false) {
            throw new InvalidConfigException('Response must be an instance of: ' . Response::class);
        }

        return $response->getPsr7Response();
    }

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

    private function getMemoryLimit(): int
    {
        if ($this->memoryLimit === null || $this->memoryLimit <= 0) {
            $limit = ini_get('memory_limit');

            if ($limit === '-1') {
                $this->memoryLimit = PHP_INT_MAX;

                return $this->memoryLimit;
            }

            sscanf($limit, '%u%c', $number, $suffix);

            if (isset($suffix)) {
                $multipliers = [' ' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824];

                $suffix = strtoupper((string) $suffix);

                $number = (int) $number * ($multipliers[$suffix] ?? 1);
            }

            $this->memoryLimit = (int) $number;
        }

        return $this->memoryLimit;
    }

    private function handleError(Throwable $exception): Response
    {
        $errorHandler = $this->getErrorHandler();

        if ($errorHandler instanceof ErrorHandler === false) {
            throw new InvalidConfigException('Error handler must be an instance of: ' . ErrorHandler::class);
        }

        $response = $errorHandler->handleException($exception);

        $this->trigger(self::EVENT_AFTER_REQUEST);

        $this->state = self::STATE_END;

        return $response;
    }

    private function initEventTracking(): void
    {
        $this->eventHandler = function (Event $event): void {
            $this->registeredEvents[] = $event;
        };
    }

    private function startEventTracking(): void
    {
        Event::on('*', '*', $this->eventHandler);
    }
}
