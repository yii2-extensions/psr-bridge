<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

use Yii;
use yii\base\{Exception, InvalidRouteException, UserException};
use yii\captcha\CaptchaAction;
use yii\web\{Controller, Cookie, RangeNotSatisfiableHttpException, Response};

use function fwrite;
use function htmlspecialchars;
use function is_string;
use function rewind;
use function stream_get_meta_data;
use function tmpfile;

final class SiteController extends Controller
{
    /**
     * Mock time for cookies.
     */
    private const MOCK_TIME = 1755867797;

    public function actionAddResponseForCookie(): void
    {
        $this->response->cookies->add(
            new Cookie(
                [
                    'name' => 'test',
                    'value' => 'test',
                    'secure' => false,
                    'httpOnly' => true,
                ],
            ),
        );

        $this->response->cookies->add(
            new Cookie(
                [
                    'name' => 'test2',
                    'value' => 'test2',
                    'secure' => false,
                    'httpOnly' => true,
                ],
            ),
        );
    }

    /**
     * @phpstan-return array{password: string|null, username: string|null}
     */
    public function actionAuth(): array
    {
        $this->response->format = Response::FORMAT_JSON;

        return [
            'username' => $this->request->getAuthUser(),
            'password' => $this->request->getAuthPassword(),
        ];
    }

    /**
     * @phpstan-return  array{isGuest: bool, Identity?: string|null}
     */
    public function actionCheckauth(): array
    {
        $this->response->format = Response::FORMAT_JSON;

        $user = Yii::$app->user;
        $username = $user->identity instanceof Identity ? $user->identity->username : null;

        return [
            'isGuest' => $user->isGuest,
            'identity' => $username,
        ];
    }

    public function actionDeletecookie(): Response
    {
        MockerFunctions::setMockedTime(self::MOCK_TIME);

        $deletionCookie = new Cookie(
            [
                'name' => 'user_preference',
                'value' => '', // empty value for deletion
                'expire' => time() - 1, // just expired
                'path' => '/app',
                'httpOnly' => true,
                'secure' => true,
            ],
        );

        $this->response->cookies->add($deletionCookie);

        $this->response->format = Response::FORMAT_JSON;

        return $this->response;
    }

    public function actionError(): string
    {
        $exception = Yii::$app->errorHandler->exception;

        if ($exception !== null) {
            $exceptionType = $exception::class;
            $exceptionMessage = htmlspecialchars($exception->getMessage());

            return <<< HTML
            <div id="custom-error-action">
            Custom error page from errorAction.
            <span class="exception-type">
            $exceptionType
            </span>
            <span class="exception-message">
            $exceptionMessage
            </span>
            </div>
            HTML;
        }

        return <<<HTML
        <div id="custom-error-action">Custom error page from errorAction</div>
        HTML;
    }

    public function actionErrorWithResponse(): Response
    {
        $exception = Yii::$app->errorHandler->exception;

        $this->response->format = Response::FORMAT_HTML;
        $this->response->statusCode = 500;

        if ($exception !== null) {
            $message = htmlspecialchars($exception->getMessage());

            $this->response->data = <<<HTML
            <div id="custom-response-error">
            Custom Response object from error action: $message
            </div>
            HTML;
        } else {
            $this->response->data = <<<HTML
            <div id="custom-response-error">
            Custom Response object from error action.
            </div>
            HTML;
        }

        return $this->response;
    }

    /**
     * @throws Exception if an unexpected error occurs during execution.
     */
    public function actionFile(): Response
    {
        $this->response->format = Response::FORMAT_RAW;

        $tmpFile = tmpfile();

        if ($tmpFile === false) {
            throw new Exception('Failed to create temporary file');
        }

        fwrite($tmpFile, 'This is a test file content.');
        rewind($tmpFile);

        $tmpFilePath = stream_get_meta_data($tmpFile)['uri'];

        return $this->response->sendFile($tmpFilePath, 'testfile.txt', ['mimeType' => 'text/plain']);
    }

    public function actionGet(): mixed
    {
        $this->response->format = Response::FORMAT_JSON;

        return $this->request->get();
    }

    /**
     * @phpstan-return Cookie[]
     */
    public function actionGetcookies(): array
    {
        $this->response->format = Response::FORMAT_JSON;

        return $this->request->getCookies()->toArray();
    }

    /**
     * @phpstan-return array{flash: mixed[]}
     */
    public function actionGetflash(): array
    {
        $this->response->format = Response::FORMAT_JSON;

        return ['flash' => Yii::$app->session->getAllFlashes()];
    }

    /**
     * @phpstan-return array<string, mixed>
     */
    public function actionGetsession(): array
    {
        $this->response->format = Response::FORMAT_JSON;

        return ['testValue' => Yii::$app->session->get('testValue')];
    }

    /**
     * @phpstan-return array{data: mixed}
     */
    public function actionGetsessiondata(): array
    {
        $this->response->format = Response::FORMAT_JSON;

        return ['data' => Yii::$app->session->get('userData')];
    }

    /**
     * @phpstan-return string[]
     */
    public function actionIndex(): array
    {
        $this->response->format = Response::FORMAT_JSON;

        return ['hello' => 'world'];
    }

    /**
     * @phpstan-return array{status: string, username?: string}
     */
    public function actionLogin(): array
    {
        $this->response->format = Response::FORMAT_JSON;

        $username = $this->request->post('username');
        $password = $this->request->post('password');

        if (is_string($username) && is_string($password)) {
            $identity = Identity::findByUsername($username);

            if ($identity === null || $identity->validatePassword($password) === false) {
                return ['status' => 'error'];
            }

            Yii::$app->user->login($identity);

            return ['status' => 'ok', 'username' => $username];
        }

        return ['status' => 'error'];
    }

    public function actionMultiplecookies(): Response
    {
        $regularCookie = new Cookie(
            [
                'name' => 'theme',
                'value' => 'dark',
                'expire' => time() + 3600,
            ],
        );

        // add a deletion cookie (empty value)
        $deletionCookie = new Cookie(
            [
                'name' => 'old_session',
                'value' => '',
                'expire' => time() - 3600,
            ],
        );

        // add another deletion cookie ('null' value)
        $nullDeletionCookie = new Cookie(
            [
                'name' => 'temp_data',
                'value' => null,
                'expire' => time() - 3600,
            ],
        );

        $this->response->cookies->add($regularCookie);
        $this->response->cookies->add($deletionCookie);
        $this->response->cookies->add($nullDeletionCookie);

        $this->response->format = Response::FORMAT_JSON;

        return $this->response;
    }

    public function actionPost(): mixed
    {
        $this->response->format = Response::FORMAT_JSON;

        return $this->request->post();
    }

    /**
     * @phpstan-return array<array-key, mixed>
     */
    public function actionQuery(string $test): array
    {
        $this->response->format = Response::FORMAT_JSON;

        return [
            'test' => $test,
            'q' => $this->request->get('q'),
            'queryParams' => $this->request->getQueryParams(),
        ];
    }

    /**
     * @throws InvalidRouteException
     */
    public function actionRedirect(): void
    {
        $this->response->redirect('/site/index');
    }

    public function actionRefresh(): void
    {
        $this->response->refresh('#stateless');
    }

    public function actions(): array
    {
        return [
            'captcha' => [
                'class' => CaptchaAction::class,
                'minLength' => 4,
                'maxLength' => 6,
            ],
        ];
    }

    public function actionSetflash(): void
    {
        $this->response->format = Response::FORMAT_JSON;

        Yii::$app->session->setFlash('success', 'Test flash message');

        $this->response->data = ['status' => 'ok'];
    }

    public function actionSetsession(): void
    {
        $this->response->format = Response::FORMAT_JSON;

        Yii::$app->session->set('testValue', 'test-value');

        $this->response->data = ['status' => 'ok'];
    }

    public function actionSetsessiondata(): void
    {
        $this->response->format = Response::FORMAT_JSON;

        $data = $this->request->post('data');

        Yii::$app->session->set('userData', $data);

        $this->response->data = ['status' => 'ok'];
    }

    public function actionStatuscode(): void
    {
        $this->response->statusCode = 201;
    }

    /**
     * @throws Exception if an unexpected error occurs during execution.
     * @throws RangeNotSatisfiableHttpException if the requested range is not satisfiable.
     */
    public function actionStream(): Response
    {
        $this->response->format = Response::FORMAT_RAW;

        $tmpFile = tmpfile();

        if ($tmpFile === false) {
            throw new Exception('Failed to create temporary file');
        }

        fwrite($tmpFile, 'This is a test file content.');
        rewind($tmpFile);

        return $this->response->sendStreamAsFile($tmpFile, 'stream.txt', ['mimeType' => 'text/plain']);
    }

    /**
     * @throws Exception if an unexpected error occurs during execution.
     */
    public function actionTriggerException(): never
    {
        throw new Exception('Exception error message.');
    }

    /**
     * @throws UserException if user-friendly error is triggered.
     */
    public function actionTriggerUserException(): never
    {
        throw new UserException('User-friendly error message.');
    }

    /**
     * @phpstan-return array<array-key, string|null>
     */
    public function actionUpdate(string|null $id = null): array
    {
        $this->response->format = Response::FORMAT_JSON;

        return ['site/update' => $id];
    }
}
