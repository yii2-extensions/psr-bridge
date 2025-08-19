<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

use Throwable;
use Yii;
use yii\base\{Exception, InvalidRouteException, UserException};
use yii\helpers\VarDumper;

use function array_diff_key;
use function array_flip;
use function htmlspecialchars;
use function ini_set;

/**
 * Error handler extension with PSR-7 bridge support for Yii2 applications.
 *
 * Provides a drop-in replacement for {@see \yii\web\ErrorHandler} that integrates PSR-7 ResponseInterface handling,
 * enabling seamless interoperability with PSR-7 compatible HTTP stacks and modern PHP runtimes.
 *
 * This class overrides exception handling to produce PSR-7 ResponseInterface objects, supporting custom error views,
 * fallback rendering, and Yii2 error action integration.
 *
 * All exception handling is performed in a type-safe, immutable manner, ensuring compatibility with legacy Yii2
 * workflows and modern middleware stacks.
 *
 * Key features.
 * - Custom error view and error action support for HTML responses.
 * - Exception-safe conversion to PSR-7 ResponseInterface objects.
 * - Fallback rendering for nested exceptions and debug output.
 * - Integration with Yii2 error action and view rendering.
 * - Type-safe, immutable error handling for modern runtimes.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class ErrorHandler extends \yii\web\ErrorHandler
{
    /**
     * Default configuration for creating fallback Response instances.
     *
     * @phpstan-var array<string, mixed>
     */
    public array $defaultResponseConfig = [
        'charset' => 'UTF-8',
    ];

    /**
     * Template Response instance for error handling.
     *
     * When set, this Response instance will be used as a base for error responses, preserving configured format,
     * formatters, and other settings.
     */
    private Response|null $templateResponse = null;

    /**
     * Clears all output buffers above the minimum required level.
     *
     * Iterates through all active output buffers and cleans them, ensuring that only the minimum buffer level remains.
     *
     * This method is used to discard any existing output before rendering an error response, maintaining a clean output
     * state while preserving compatibility with the testing framework.
     *
     * **PHPUnit Compatibility.**
     *
     * PHPUnit manages its own output buffer (typically at level '1') to capture and verify test output. Clearing this
     * buffer causes PHPUnit to mark tests as "risky" because it detects unexpected manipulation of the output buffering
     * system.
     *
     * By preserving level '1' in test environments, we ensure compatibility with PHPUnit testing infrastructure.
     *
     * @see https://github.com/sebastianbergmann/phpunit/issues/risky-tests PHPUnit risky test detection
     *
     * Usage example:
     * ```php
     * $this->clearOutput(); // Clears buffers while respecting testing environment
     * ```
     */
    public function clearOutput(): void
    {
        $currentLevel = ob_get_level();

        $minLevel = YII_ENV_TEST ? 1 : 0;

        while ($currentLevel > $minLevel) {
            if (@ob_end_clean() === false) {
                // @codeCoverageIgnoreStart
                ob_clean();
                // @codeCoverageIgnoreEnd
            }

            $currentLevel = ob_get_level();
        }
    }

    /**
     * Handles exceptions and produces a PSR-7 ResponseInterface object.
     *
     * Overrides the default Yii2 exception handling to generate a PSR-7 ResponseInterface instance, supporting custom
     * error views, fallback rendering, and integration with Yii2 error actions.
     *
     * Ensures type-safe, immutable error handling for modern runtimes.
     *
     * This method guarantees that all exceptions are converted to PSR-7 ResponseInterface, maintaining compatibility
     * with both legacy Yii2 workflows and modern middleware stacks.
     *
     * @param Throwable $exception Exception to handle and convert to a PSR-7 ResponseInterface object.
     *
     * @return Response PSR-7 ResponseInterface representing the handled exception.
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
     * Sets the template Response for error handling.
     *
     * The provided Response will be used as a template for error responses, preserving configuration such as format,
     * charset, and formatters. The Response will be cleared of any existing data before use.
     *
     * @param Response $response Template response with desired configuration.
     */
    public function setResponse(Response $response): void
    {
        $this->templateResponse = $response;
    }

    /**
     * Handles fallback exception rendering when an error occurs during exception processing.
     *
     * Produces a {@see Response} object with a generic error message and, in debug mode, includes detailed exception
     * information and a sanitized snapshot of server variables, excluding sensitive keys.
     *
     * This method ensures that nested or secondary exceptions do not expose sensitive data and provides a minimal
     * diagnostic output for debugging purposes.
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
            $response->data .= "\n\$_SERVER = " . VarDumper::export($safeServerVars);
        }

        return $response;
    }

    /**
     * Renders the exception and produces a {@see Response} object with appropriate error content.
     *
     * Handles exception rendering for HTML, raw, and array formats, supporting custom error views and error actions.
     *
     * This method ensures type-safe, immutable error handling and maintains compatibility with Yii2 error actions and
     * view rendering.
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

        $useErrorView = $response->format === Response::FORMAT_HTML &&
            (YII_DEBUG === false || $exception instanceof UserException);

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
            $response->data = self::convertExceptionToString($exception);
        } else {
            $response->data = $this->convertExceptionToArray($exception);
        }

        return $response;
    }

    /**
     * Creates a Response instance for error handling.
     *
     * Uses the template Response if available, otherwise creates a new instance with default configuration.
     *
     * @return Response Clean Response instance ready for error content.
     */
    private function createErrorResponse(): Response
    {
        $response = $this->templateResponse ?? new Response($this->defaultResponseConfig);

        $response->clear();

        return $response;
    }
}
