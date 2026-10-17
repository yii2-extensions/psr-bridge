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
     * Whether to sign outgoing cookie values with {@see cookieValidationKey}.
     */
    public bool $enableCookieValidation = false;

    /**
     * PSR-7 ResponseAdapter for bridging PSR-7 ResponseInterface with Yii Response component.
     */
    private ResponseAdapter|null $adapter = null;

    /**
     * Queues the current session ID, including rotation followed by an explicit close.
     */
    public function captureSessionCookie(): void
    {
        if (!Yii::$app->has('session', true)) {
            return;
        }

        $session = Yii::$app->getSession();
        $name = $session->getName();
        $id = $session->getId();
        $cookie = $this->cookies->get($name);

        // Preserve an explicit expiration instead of reviving a deleted session cookie.
        if ($cookie !== null && !$this->cookies->has($name)) {
            return;
        }

        if (
            $id === ''
            || (!$session->getIsActive() && (
                !Yii::$app instanceof Application
                || Yii::$app->state !== Application::STATE_END
                || ($cookie === null && $id === Yii::$app->request->getCookies()->getValue($name))
            ))
        ) {
            return;
        }

        $cookieParams = $session->getCookieParams();
        $cookieConfig = ['name' => $name, 'value' => $id];
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

        /** @var int|null $lifetime */
        $lifetime = $cookieParams['lifetime'] ?? null;

        if ($lifetime > 0) {
            $cookieConfig['expire'] = time() + $lifetime;
        }

        $this->cookies->add(new Cookie($cookieConfig));
    }

    /**
     * Completes the Yii response send lifecycle after the runtime emits the PSR-7 body.
     *
     * Triggers {@see EVENT_AFTER_SEND} and marks the response as sent, running the back half of {@see send()} that
     * {@see getPsr7Response()} intentionally defers. Returns immediately when the response is already sent.
     *
     * Call this after the PSR-7 response has been emitted so lazy body streams (such as {@see sendFile()} or
     * {@see sendStreamAsFile()}) are consumed before after-send handlers run.
     */
    public function completeSend(): void
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
     * Triggers before-send and after-prepare, closes an active session after queuing its cookie, and delegates
     * conversion to {@see ResponseAdapter}. Does not emit bytes, trigger after-send, or mark the response sent.
     * Use {@see Application::finalize()} after emission for application-managed responses; for standalone use,
     * call {@see completeSend()} after successful emission.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws NotInstantiableException if a class or service can't be instantiated.
     *
     * @return ResponseInterface PSR-7 ResponseInterface instance representing the current Yii Response.
     */
    public function getPsr7Response(): ResponseInterface
    {
        $this->trigger(self::EVENT_BEFORE_SEND);
        $this->prepare();
        $this->trigger(self::EVENT_AFTER_PREPARE);

        $this->captureSessionCookie();

        if (Yii::$app->has('session', true)) {
            Yii::$app->getSession()->close();
        }

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
}
