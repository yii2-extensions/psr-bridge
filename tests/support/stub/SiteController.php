<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

use Yii;
use yii\base\Exception;
use yii\web\{Controller, Cookie, CookieCollection, HttpException, Response};

final class SiteController extends Controller
{
    public function action404(): never
    {
        throw new HttpException(404);
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

    public function actionFile()
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

    public function actionGeneralException(): never
    {
        throw new Exception('General Exception');
    }

    public function actionGet()
    {
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_JSON;
        return Yii::$app->request->get();
    }

    public function actionGetcookies(): CookieCollection
    {
        $this->response->format = Response::FORMAT_JSON;

        return $this->request->getCookies();
    }

    /**
     * @phpstan-return string[]
     */
    public function actionIndex(): array
    {
        $this->response->format = Response::FORMAT_JSON;

        return ['hello' => 'world'];
    }

    public function actionPost(): mixed
    {
        $this->response->format = Response::FORMAT_JSON;

        return $this->request->post();
    }

    public function actionQuery($test)
    {
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_JSON;
        return [
            'test' => $test,
            'q' => Yii::$app->request->get('q'),
            'queryParams' => Yii::$app->request->getQueryParams(),
        ];
    }

    public function actionRedirect(): void
    {
        $this->response->redirect('/site/index');
    }

    public function actionRefresh(): void
    {
        $this->response->refresh('#stateless');
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
