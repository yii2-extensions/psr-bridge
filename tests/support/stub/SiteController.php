<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

use Yii;
use yii\base\Exception;
use yii\captcha\CaptchaAction;
use yii\web\{Controller, Cookie, CookieCollection, Response};
use yii\web\IdentityInterface;

final class SiteController extends Controller
{
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

    public function actionCookie(): void
    {
        $this->response->cookies->add(
            new Cookie(
                [
                    'name' => 'test',
                    'value' => 'test',
                    'httpOnly' => false,
                ],
            ),
        );

        $this->response->cookies->add(
            new Cookie(
                [
                    'name' => 'test2',
                    'value' => 'test2',
                ],
            ),
        );
    }

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

    public function actionGetcookies(): CookieCollection
    {
        $this->response->format = Response::FORMAT_JSON;

        return $this->request->getCookies();
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

            if ($identity instanceof IdentityInterface === false || $identity->validatePassword($password) === false) {
                return ['status' => 'error'];
            }

            Yii::$app->user->login($identity);

            return ['status' => 'ok', 'username' => $username];
        }

        return ['status' => 'error'];
    }

    public function actionPost(): mixed
    {
        $this->response->format = Response::FORMAT_JSON;

        return $this->request->post();
    }

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

    public function actionSetsession(): void
    {
        $this->response->format = Response::FORMAT_JSON;

        Yii::$app->session->set('testValue', 'test-value');

        $this->response->data = ['status' => 'ok'];
    }

    public function actionStatuscode(): void
    {
        $this->response->statusCode = 201;
    }

    public function actionStream(): Response
    {
        $this->response->format = Response::FORMAT_RAW;

        $tmpFile = tmpfile();

        if ($tmpFile === false) {
            throw new Exception('Failed to create temporary file');
        }

        fwrite($tmpFile, 'This is a test file content.');
        rewind($tmpFile);

        $tmpFilePath = stream_get_meta_data($tmpFile)['uri'];

        return $this->response->sendStreamAsFile($tmpFile, $tmpFilePath, ['mimeType' => 'text/plain']);
    }
}
