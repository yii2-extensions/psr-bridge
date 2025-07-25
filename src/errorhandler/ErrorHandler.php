<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\errorhandler;

use Throwable;
use Yii;
use yii\base\UserException;
use yii\helpers\VarDumper;
use yii2\extensions\psrbridge\http\Response;

final class ErrorHandler extends \yii\web\ErrorHandler
{
    public function handleException($exception): Response
    {
        $this->exception = $exception;

        $this->unregister();

        if (PHP_SAPI !== 'cli') {
            http_response_code(500);
        }

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

    protected function handleFallbackExceptionMessage($exception, $previousException): Response
    {
        $response = new Response();

        $msg = "An Error occurred while handling another error:\n";

        $msg .= (string) $exception;
        $msg .= "\nPrevious exception:\n";
        $msg .= (string) $previousException;

        $response->data = 'An internal server error occurred.';

        if (YII_DEBUG) {
            $response->data = '<pre>' . htmlspecialchars($msg, ENT_QUOTES, Yii::$app->charset) . '</pre>';
            $response->data .= "\n\$_SERVER = " . VarDumper::export($_SERVER);
        }

        error_log($response->data);

        return $response;
    }

    protected function renderException($exception): Response
    {
        $response = new Response();

        $response->setStatusCodeByException($exception);

        $useErrorView = $response->format === Response::FORMAT_HTML && (!YII_DEBUG || $exception instanceof UserException);

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
                    ini_set('display_errors', 'true');
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
}
