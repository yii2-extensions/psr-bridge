<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, StreamFactoryInterface};
use Yii;
use yii\base\InvalidConfigException;
use yii\di\NotInstantiableException;
use yii\web\Cookie;
use yii2\extensions\psrbridge\adapter\ResponseAdapter;

/**
 * Extends Yii response handling with PSR-7 response conversion.
 *
 * {@see ResponseAdapter} Yii response to PSR-7 response adapter.
 *
 * Usage example:
 * ```php
 * $response = new \yii2\extensions\psrbridge\http\Response();
 * $response->data = ['ok' => true];
 * $psr7Response = $response->getPsr7Response();
 * ```
 */
class Response extends \yii\web\Response
{
    /**
     * @var string A secret key used for cookie validation.
     *
     * Set this property when {@see enableCookieValidation} is `true`.
     */
    public string $cookieValidationKey = '';
    /**
     * @var bool Whether to validate cookies with {@see cookieValidationKey}.
     */
    public bool $enableCookieValidation = false;

    /**
     * PSR-7 ResponseAdapter for bridging PSR-7 ResponseInterface with Yii Response component.
     */
    private ResponseAdapter|null $adapter = null;

    /**
     * Indicates whether PSR-7 conversion already prepared the Yii response.
     */
    private bool $isPreparedForPsr7 = false;

    /**
     * Resets response state, including PSR-7 preparation state.
     */
    public function clear(): void
    {
        parent::clear();

        $this->isPreparedForPsr7 = false;
    }

    /**
     * Ends the Yii response lifecycle after a PSR runtime has emitted the converted response.
     *
     * This method completes the part of {@see \yii\web\Response::send()} that can't run inside
     * {@see getPsr7Response()}, because only the consuming runtime knows when the PSR-7 response has actually been sent.
     */
    public function end(): void
    {
        if ($this->isSent) {
            return;
        }

        $this->trigger(self::EVENT_AFTER_SEND);

        $this->isSent = true;
    }

    /**
     * Converts the Yii Response component to a PSR-7 ResponseInterface instance.
     *
     * Delegates the conversion process to a {@see ResponseAdapter}, triggering Yii Response lifecycle events and
     * ensuring session cookies are attached when a session is active.
     *
     * The Yii after-send phase is intentionally not triggered during conversion because the PSR-7 response may be emitted
     * later by RoadRunner, FrankenPHP, or another PSR runtime. Call {@see end()} after that runtime finishes sending the
     * returned PSR-7 response.
     *
     * Usage example:
     * ```php
     * $response = new \yii2\extensions\psrbridge\http\Response();
     * $psrResponse = $response->getPsr7Response();
     * ```
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws NotInstantiableException if a class or service can't be instantiated.
     *
     * @return ResponseInterface PSR-7 ResponseInterface instance representing the current Yii Response.
     */
    public function getPsr7Response(): ResponseInterface
    {
        $this->prepareForPsr7();

        return $this->createAdapter()->toPsr7();
    }

    /**
     * Retrieves the PSR-7 ResponseAdapter instance for the current Response.
     *
     * Instantiates and returns a {@see ResponseAdapter} for bridging the Yii Response component with PSR-7
     * ResponseInterface.
     *
     * The adapter is created on first access and cached for subsequent calls, ensuring a single instance per Response
     * object.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws NotInstantiableException if a class or service can't be instantiated.
     *
     * @return ResponseAdapter PSR-7 ResponseAdapter instance for the current Response.
     */
    private function createAdapter(): ResponseAdapter
    {
        return $this->adapter ??= new ResponseAdapter(
            $this,
            Yii::$container->get(ResponseFactoryInterface::class),
            Yii::$container->get(StreamFactoryInterface::class),
            Yii::$app->getSecurity(),
        );
    }

    /**
     * Prepares the Yii response for PSR-7 conversion once.
     */
    private function prepareForPsr7(): void
    {
        if ($this->isPreparedForPsr7 || $this->isSent) {
            return;
        }

        $this->trigger(self::EVENT_BEFORE_SEND);
        $this->prepare();
        $this->trigger(self::EVENT_AFTER_PREPARE);

        if (Yii::$app->has('session') && ($session = Yii::$app->getSession())->getIsActive()) {
            $cookieParams = $session->getCookieParams();

            $cookieConfig = [
                'name' => $session->getName(),
                'value' => $session->getId(),
            ];

            $paramMapping = [
                'domain' => 'domain',
                'httponly' => 'httpOnly',
                'path' => 'path',
                'samesite' => 'sameSite',
                'secure' => 'secure',
            ];

            foreach ($paramMapping as $sessionKey => $configKey) {
                if (isset($cookieParams[$sessionKey])) {
                    $cookieConfig[$configKey] = $cookieParams[$sessionKey];
                }
            }

            $this->cookies->add(new Cookie($cookieConfig));
            $session->close();
        }

        $this->isPreparedForPsr7 = true;
    }
}
