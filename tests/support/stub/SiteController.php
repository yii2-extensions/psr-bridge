<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

use Yii;
use yii\web\{Controller, Cookie, CookieCollection, HttpException, Response};

final class SiteController extends Controller
{
    public function action404()
    {
        throw new HttpException(404);
    }

    public function actionAuth()
    {
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_JSON;
        return [
            'username' => Yii::$app->request->getAuthUser(),
            'password' => Yii::$app->request->getAuthPassword(),
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
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        return $response->sendFile(__DIR__ . '/../.rr.yaml', '.rr.yaml', [
            'mimeType' => 'text/yaml',
        ]);
    }

    public function actionGeneralException()
    {
        throw new \Exception('General Exception');
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

    public function actionStream()
    {
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        if ($stream = fopen(__DIR__ . '/../.rr.yaml', 'r')) {
            return $response->sendStreamAsFile($stream, '.rr.yaml', [
                'mimeType' => 'text/yaml',
            ]);
        }
    }
}
