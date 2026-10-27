<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

use Throwable;
use WeakReference;
use Yii;
use yii\base\{Exception, InvalidRouteException, UserException};
use yii\helpers\VarDumper;

use function array_diff_key;
use function array_flip;
use function htmlspecialchars;
use function ini_set;

/**
 * Handles exceptions with Yii error rendering and PSR-7 bridge support.
 */
class ErrorHandler extends \yii\web\ErrorHandler
{
    /**
     * Default configuration for creating fallback Response instances.
     *
     * @var array<string, mixed>
     */
    public array $defaultResponseConfig = [
        'charset' => 'UTF-8',
    ];
    /**
     * @var int Size of the memory reserve for handling fatal errors.
     */
    public $memoryReserveSize = 1024 * 1024; // 1 MB

    /**
     * Memory reserve for handling fatal errors.
     */
    private string|null $memoryReserve = null;
    /**
     * Indicates whether this handler has been registered.
     */
    private bool $registered = false;
    /**
     * Response instance for error handling.
     *
     * When set, this Response instance will be used as a base for error responses, preserving configured format,
     * formatters, and other settings.
     */
    private Response|null $response = null;
    /**
     * @var WeakReference<self> Current handler without retaining completed request state.
     */
    private static WeakReference $shutdownHandler;
    /**
     * Indicates whether the shutdown handler has been registered.
     */
    private static bool $shutdownRegistered = false;
    /**
     * Current working directory before handling a fatal error.
     */
    private string|false|null $workingDirectory = null;

    /**
     * Discards active output buffers, retaining level 1 when `YII_ENV_TEST` is `true`.
     */
    public function clearOutput(): void
    {
        $minLevel = YII_ENV_TEST ? 1 : 0;

        for ($level = ob_get_level(); $level > $minLevel; --$level) {
            if (@ob_end_clean() === false) {
                ob_clean();
            }
        }
    }

    /**
     * Logs an exception and renders a bridge Yii response without sending it.
     *
     * Unregisters the current error handler while rendering. If logging or rendering fails, returns a fallback
     * response. PSR conversion remains the caller's responsibility.
     *
     * @param Throwable $exception Exception to render.
     * @return Response Yii response containing the rendered error.
     */
    public function handleException($exception): Response
    {
        $this->exception = $exception;

        $this->unregister();

        try {
            $this->logException($exception);

            if ($this->discardExistingOutput) {
                $this->clearOutput();
            }

            $response = $this->renderException($exception);
        } catch (Throwable $e) {
            return $this->handleFallbackExceptionMessage($e, $exception);
        }

        $this->exception = null;

        return $response;
    }

    /**
     * Releases reserved memory before delegating fatal-error rendering to Yii.
     */
    // @phpstan-ignore missingType.return (Keep Yii's untyped extension point compatible with existing subclasses.)
    public function handleFatalError()
    {
        if ($this->memoryReserve !== null) {
            $this->memoryReserve = null;
        }

        if ($this->workingDirectory !== null && $this->workingDirectory !== false) {
            chdir($this->workingDirectory);
            $this->workingDirectory = null;
        }

        parent::handleFatalError();
    }

    /**
     * Registers PHP handlers with one weak shutdown dispatcher per process.
     */
    // @phpstan-ignore missingType.return (Keep Yii's untyped extension point compatible with existing subclasses.)
    public function register()
    {
        if ($this->registered) {
            return;
        }

        ini_set('display_errors', '0');
        set_exception_handler(
            function (Throwable $exception): void {
                $this->handleException($exception);
            }
        );
        set_error_handler([$this, 'handleError']);

        $this->memoryReserve = str_pad('', $this->memoryReserveSize, 'x');
        $this->workingDirectory = PHP_SAPI !== 'cli' ? getcwd() : null;
        self::$shutdownHandler = WeakReference::create($this);

        if (!self::$shutdownRegistered) {
            register_shutdown_function(
                static function (): void {
                    self::$shutdownHandler->get()?->handleFatalError();
                }
            );

            self::$shutdownRegistered = true;
        }

        $this->registered = true;
    }

    /**
     * Releases request references after emission and finalization.
     */
    public function releaseResponse(): void
    {
        $this->response = null;
        $this->exception = null;
    }

    /**
     * Sets the Response for error handling.
     *
     * The provided Response will be used for error responses, preserving configuration such as format, charset, and
     * formatters. The Response will be cleared of any existing data before use.
     *
     * @param Response $response Response instance with desired configuration.
     */
    public function setResponse(Response $response): void
    {
        $this->response = $response;
    }

    /**
     * Restores PHP handlers and releases the current registration's reserve.
     */
    // @phpstan-ignore missingType.return (Keep Yii's untyped extension point compatible with existing subclasses.)
    public function unregister()
    {
        if (!$this->registered) {
            return;
        }

        $this->memoryReserve = null;
        $this->workingDirectory = null;

        restore_error_handler();
        restore_exception_handler();

        $this->registered = false;
    }

    /**
     * Handles fallback exception rendering when an error occurs during exception processing.
     *
     * Produces a {@see Response} object with a generic error message and, in debug mode, includes detailed exception
     * information and a sanitized snapshot of server variables, excluding sensitive keys.
     *
     * @param Throwable $exception Exception thrown during error handling.
     * @param Throwable $previousException Original exception that triggered error handling.
     *
     * @return Response Object containing the fallback error message and debug output if enabled.
     */
    protected function handleFallbackExceptionMessage($exception, $previousException): Response
    {
        $response = $this->createErrorResponse()->setStatusCode(500);

        $msg = "An Error occurred while handling another error:\n";
        $msg .= $exception;
        $msg .= "\nPrevious exception:\n";
        $msg .= $previousException;

        $response->data = 'An internal server error occurred.';

        if (YII_DEBUG) {
            $message = htmlspecialchars($msg, ENT_QUOTES, Yii::$app->charset);

            $response->data = "<pre>{$message}</pre>";

            $safeServerVars = array_diff_key(
                $_SERVER,
                array_flip(
                    [
                        'API_KEY',
                        'AUTH_TOKEN',
                        'DB_PASSWORD',
                        'SECRET_KEY',
                    ],
                ),
            );
            $serverVars = htmlspecialchars(
                VarDumper::export($safeServerVars),
                ENT_QUOTES | ENT_SUBSTITUTE,
                $response->charset,
            );

            $response->data .= "\n\$_SERVER = {$serverVars}";
        }

        return $response;
    }

    /**
     * Renders the exception and produces a {@see Response} object with appropriate error content.
     *
     * Handles exception rendering for HTML, raw, and array formats, supporting custom error views and error actions.
     *
     * @param Throwable $exception Exception to render and convert to a {@see Response} object.
     *
     * @throws Exception if an error occurs during error action execution.
     * @throws InvalidRouteException if the error action route is invalid or cannot be resolved.
     *
     * @return Response Object containing the rendered exception output.
     */
    protected function renderException($exception): Response
    {
        $response = $this->createErrorResponse();

        $response->setStatusCodeByException($exception);

        $useErrorView = $response->format === Response::FORMAT_HTML
            && (!YII_DEBUG || $exception instanceof UserException);

        if ($useErrorView && $this->errorAction !== null) {
            $result = Yii::$app->runAction($this->errorAction);

            if ($result instanceof Response) {
                $response = $result;
            } else {
                $response->data = $result;
            }
        } elseif ($response->format === Response::FORMAT_HTML) {
            if ($this->shouldRenderSimpleHtml()) {
                $response->data = '<pre>' . $this->htmlEncode(self::convertExceptionToString($exception)) . '</pre>';
            } else {
                if (YII_DEBUG) {
                    ini_set('display_errors', '1');
                }

                $file = $useErrorView ? $this->errorView : $this->exceptionView;

                $response->data = $this->renderFile($file, ['exception' => $exception]);
            }
        } elseif ($response->format === Response::FORMAT_RAW) {
            $response->data = $this->convertExceptionToRawString($exception);
        } else {
            $response->data = $this->convertExceptionToArray($exception);
        }

        return $response;
    }

    /**
     * Converts an exception to a RAW response string without exposing sensitive details in production.
     *
     * @param Throwable $exception Exception to convert.
     *
     * @return string Safe RAW response body.
     */
    private function convertExceptionToRawString(Throwable $exception): string
    {
        if (YII_DEBUG) {
            return self::convertExceptionToString($exception);
        }

        if ($exception instanceof UserException) {
            return $exception->getMessage();
        }

        return 'An internal server error occurred.';
    }

    /**
     * Creates a Response instance for error handling.
     *
     * Uses the Response if available, otherwise create a new instance with default configuration.
     *
     * @return Response Clean Response instance ready for error content.
     */
    private function createErrorResponse(): Response
    {
        $response = $this->response ?? new Response($this->defaultResponseConfig);

        $response->isSent = false;
        $response->stream = null;
        $response->data = null;
        $response->content = null;

        $headers = [
            'Content-Length',
            'Content-Range',
            'Content-Encoding',
            'Content-Type',
            'Content-Disposition',
            'Accept-Ranges',
            'ETag',
            'Last-Modified',
            'Expires',
            'Pragma',
            'Location',
            'Refresh',
            'X-Sendfile',
            'X-Accel-Redirect',
            'X-Accel-Buffering',
            'X-Accel-Charset',
            'X-Accel-Expires',
            'X-Accel-Limit-Rate',
            'X-Lighttpd-Send-File',
        ];

        foreach ($headers as $header) {
            $response->headers->remove($header);
        }

        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
