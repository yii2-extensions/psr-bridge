<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group, PreserveGlobalState, RunTestsInSeparateProcesses, TestWith};
use RuntimeException;
use Xepozz\InternalMocker\MockerState;
use Yii;
use yii\base\UserException;
use yii2\extensions\psrbridge\http\{ErrorHandler, Response};
use yii2\extensions\psrbridge\tests\provider\ErrorHandlerProvider;
use yii2\extensions\psrbridge\tests\support\{ApplicationFactory, TestCase};

/**
 * Checks output cleanup and error-response contracts without redefining global Yii constants.
 */
#[Group('http')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ErrorHandlerBoundaryTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function testErrorActionPreservesItsStringOrResponseResult(bool $returnsResponse): void
    {
        define('yii2\\extensions\\psrbridge\\http\\YII_DEBUG', false);

        ApplicationFactory::web(
            [
                'components' => [
                    'errorHandler' => [
                        'class' => ErrorHandler::class,
                        'discardExistingOutput' => false,
                        'errorAction' => $returnsResponse ? 'site/error-with-response' : 'site/error',
                    ],
                    'response' => ['class' => Response::class, 'charset' => 'UTF-8'],
                ],
            ]
        );

        $handler = Yii::$app->errorHandler;

        self::assertInstanceOf(
            ErrorHandler::class,
            $handler,
            'Application must use the bridge error handler.',
        );

        $response = $handler->handleException(new RuntimeException('original exception'));

        self::assertSame(
            500,
            $response->getStatusCode(),
            'Error action must retain the error status.',
        );
        self::assertIsString(
            $response->data,
            'Error action must provide its response body.',
        );
        self::assertStringContainsString(
            'original exception',
            $response->data,
            'Error action must receive the exception.',
        );
        self::assertSame(
            $returnsResponse,
            $response === Yii::$app->response,
            'A returned response must be used directly; a string must populate the handler response.',
        );
    }

    #[DataProviderExternal(ErrorHandlerProvider::class, 'obsoleteResponseHeaders')]
    public function testErrorResponseDropsRedirectAndOffloadHeaders(string $header): void
    {
        $handler = new ErrorHandler(['discardExistingOutput' => false]);
        $original = new Response(['charset' => 'UTF-8', 'format' => Response::FORMAT_JSON]);

        $original->headers->set($header, '/private/download');
        $original->headers->set('Access-Control-Allow-Origin', 'https://app.example');
        $original->headers->set('X-Content-Type-Options', 'nosniff');
        $handler->setResponse($original);

        $response = $handler->handleException(new RuntimeException('action failed'));

        self::assertFalse(
            $response->headers->has($header),
            'Error response must discard the previous delivery directive.',
        );
        self::assertSame(
            'https://app.example',
            $response->headers->get('Access-Control-Allow-Origin'),
            'Error response must preserve CORS headers.',
        );
        self::assertSame(
            'nosniff',
            $response->headers->get('X-Content-Type-Options'),
            'Security headers must survive.',
        );
        self::assertSame(
            'no-store',
            $response->headers->get('Cache-Control'),
            'Error response must disable caching.',
        );
    }

    #[DataProviderExternal(ErrorHandlerProvider::class, 'renderingViews')]
    public function testHtmlRenderingChoosesTheViewForItsDebugAndExceptionMode(
        bool|int $debug,
        bool $userException,
        string $expected,
    ): void {
        define('yii2\\extensions\\psrbridge\\http\\YII_DEBUG', $debug);
        ini_set('display_errors', '0');

        $handler = new class (['discardExistingOutput' => false]) extends ErrorHandler {
            public string|false $displayErrorsDuringRender = false;
            /**
             * @var array<mixed> Parameters passed to the selected error view.
             */
            public array $parameters = [];

            /**
             * @param array<mixed> $_params_
             */
            public function renderFile($_file_, $_params_): string
            {
                $this->displayErrorsDuringRender = ini_get('display_errors');
                $this->parameters = $_params_;

                return $_file_;
            }

            protected function shouldRenderSimpleHtml(): bool
            {
                return false;
            }
        };

        $handler->errorView = 'error-view';
        $handler->exceptionView = 'exception-view';

        $exception = $userException ? new UserException('visible message') : new RuntimeException('internal message');
        $response = $handler->handleException($exception);

        self::assertSame(
            $expected,
            $response->data,
            'HTML rendering must choose the appropriate error view.',
        );
        self::assertSame(
            (bool) $debug ? '1' : '0',
            $handler->displayErrorsDuringRender,
            'View rendering must enable display_errors only in debug mode.',
        );
        self::assertSame(
            $exception,
            $handler->parameters['exception'] ?? null,
            'The selected error view must receive the original exception.',
        );
    }

    #[TestWith([Response::FORMAT_JSON])]
    #[TestWith([Response::FORMAT_RAW])]
    public function testNonHtmlErrorsDoNotRunTheHtmlErrorAction(string $format): void
    {
        define('yii2\\extensions\\psrbridge\\http\\YII_DEBUG', false);

        ApplicationFactory::web(
            [
                'components' => [
                    'errorHandler' => [
                        'class' => ErrorHandler::class,
                        'discardExistingOutput' => false,
                        'errorAction' => 'site/error-with-response',
                    ],
                    'response' => ['class' => Response::class, 'charset' => 'UTF-8'],
                ],
            ],
        );

        $handler = Yii::$app->errorHandler;

        self::assertInstanceOf(
            ErrorHandler::class,
            $handler,
            'Application must use the bridge error handler.',
        );

        $original = new Response(['charset' => 'UTF-8', 'format' => $format]);

        $handler->setResponse($original);

        $response = $handler->handleException(new RuntimeException('internal message'));

        self::assertSame(
            $original,
            $response,
            'Non-HTML formats must keep the handler response.',
        );
        self::assertSame(
            $format,
            $response->format,
            'Non-HTML errors must retain their requested format.',
        );
    }

    #[TestWith([false, false, 2])]
    #[TestWith([false, true, 2])]
    #[TestWith([true, false, 1])]
    #[TestWith([true, true, 1])]
    public function testOutputCleanupCleansOnlyFailedBufferRemovals(
        bool $testEnvironment,
        bool $removed,
        int $expectedAttempts,
    ): void {
        $namespace = 'yii2\\extensions\\psrbridge\\http';

        define($namespace . '\\YII_ENV_TEST', $testEnvironment);

        MockerState::addCondition(
            $namespace,
            'ob_get_level',
            [],
            2,
        );

        $attempts = 0;

        MockerState::addCondition(
            $namespace,
            'ob_end_clean',
            [],
            static function () use (&$attempts, $removed): bool {
                self::assertLessThanOrEqual(
                    2,
                    ++$attempts,
                    'Output cleanup must remain bounded to the initial levels.',
                );

                return $removed;
            },
        );
        MockerState::addCondition(
            $namespace,
            'ob_clean',
            [],
            true,
        );

        (new ErrorHandler())->clearOutput();

        self::assertSame(
            $expectedAttempts,
            $attempts,
            'Cleanup must respect the environment minimum buffer level.',
        );
        self::assertCount(
            $removed ? 0 : $expectedAttempts,
            MockerState::getTraces($namespace, 'ob_clean'),
            'Only a failed buffer removal must trigger in-place cleaning.',
        );
    }

    #[TestWith([false, 'An internal server error occurred.'])]
    #[TestWith([true, 'visible message'])]
    public function testProductionRawErrorsExposeOnlyUserMessages(bool $userException, string $expected): void
    {
        define('yii2\\extensions\\psrbridge\\http\\YII_DEBUG', false);

        $handler = new ErrorHandler(['discardExistingOutput' => false]);

        $handler->setResponse(new Response(['charset' => 'UTF-8', 'format' => Response::FORMAT_RAW]));

        $exception = $userException ? new UserException('visible message') : new RuntimeException('secret message');

        $response = $handler->handleException($exception);

        self::assertSame(
            $expected,
            $response->data,
            'Production RAW errors must expose only user-safe messages.',
        );
    }
}
