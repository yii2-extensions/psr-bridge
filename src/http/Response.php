<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, StreamFactoryInterface};
use Yii;
use yii\base\InvalidConfigException;
use yii\di\NotInstantiableException;
use yii\web\Cookie;
use yii2\extensions\psrbridge\adapter\ResponseAdapter;

use function filter_var;

/**
 * HTTP Response extension with PSR-7 bridge support.
 *
 * Provides a drop-in replacement for {@see \yii\web\Response} that integrates PSR-7 ResponseInterface handling,
 * enabling seamless interoperability with PSR-7 compatible HTTP stacks and modern PHP runtimes.
 *
 * This class delegates response conversion to a {@see ResponseAdapter}, allowing the Yii2 Response component to be
 * transformed into a PSR-7 ResponseInterface instance for use with middleware, emitters, or external HTTP tools.
 *
 * The conversion process triggers Yii2 Response lifecycle events and ensures session cookies are attached when a
 * session is active, maintaining compatibility with Yii2 Session management and cookie handling.
 *
 * Key features.
 * - Automatic session cookie injection when session is active.
 * - Exception-safe conversion with strict type and configuration validation.
 * - Immutable, type-safe response adaptation for modern HTTP stacks.
 * - PSR-7 ResponseInterface conversion via {@see getPsr7Response()}.
 * - Triggers Yii2 Response lifecycle events (before send, after prepare, after send).
 *
 * @see ResponseAdapter for Yii2 to PSR-7 Response adapter.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class Response extends \yii\web\Response
{
    /**
     * @var string A secret key used for cookie validation. This property must be set if {@see enableCookieValidation}
     * is 'true'.
     */
    public string $cookieValidationKey = '';

    /**
     * @var bool Whether to enable cookie validation. If 'true', the response will validate cookies using the
     * {@see cookieValidationKey}. This is recommended for security, especially when handling session cookies.
     */
    public bool $enableCookieValidation = false;
    /**
     * PSR-7 ResponseAdapter for bridging PSR-7 ResponseInterface with Yii2 Response component.
     *
     * Adapter allows the Response class to access PSR-7 ResponseInterface data while maintaining compatibility with
     * Yii2 Response component.
     */
    private ResponseAdapter|null $adapter = null;

    /**
     * Converts the Yii2 Response component to a PSR-7 ResponseInterface instance.
     *
     * Delegates the conversion process to a {@see ResponseAdapter}, triggering Yii2 Response lifecycle events and
     * ensuring session cookies are attached when a session is active.
     *
     * This method maintains compatibility with Yii2 Session management and cookie handling, enabling seamless
     * interoperability with PSR-7 compatible HTTP stacks and modern PHP runtimes.
     *
     * The conversion process includes.
     * - Converting the response to a PSR-7 ResponseInterface instance.
     * - Preparing the response and injecting the session cookie if the session is active.
     * - Triggering the before send and after prepare events.
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     * @throws NotInstantiableException if a class or service can't be instantiated.
     *
     * @return ResponseInterface PSR-7 ResponseInterface instance representing the current Yii2 Response.
     *
     * Usage example:
     * ```php
     * $psrResponse = $response->getPsr7Response();
     * ```
     */
    public function getPsr7Response(): ResponseInterface
    {
        $this->trigger(self::EVENT_BEFORE_SEND);
        $this->prepare();
        $this->trigger(self::EVENT_AFTER_PREPARE);


        if (Yii::$app->has('session') && ($session = Yii::$app->getSession())->getIsActive()) {
            $cookieParams = $session->getCookieParams();

            $cookieConfig = [
                'name' => $session->getName(),
                'value' => $session->getId(),
                'path' => $cookieParams['path'] ?? '/',
                'domain' => $cookieParams['domain'] ?? '',
                'secure' => filter_var($cookieParams['secure'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'httpOnly' => filter_var($cookieParams['httponly'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'sameSite' => $cookieParams['samesite'] ?? Cookie::SAME_SITE_LAX,
            ];

            $this->cookies->add(new Cookie($cookieConfig));
            $session->close();
        }

        return $this->getAdapter()->toPsr7();
    }

    /**
     * Resets the PSR-7 ResponseAdapter instance for the current Response.
     *
     * Sets the internal {@see ResponseAdapter} property to `null`, ensuring that a new adapter will be created on the
     * next access. This is useful for clearing cached adapter state between requests or after significant changes to
     * the Response component.
     *
     * Usage example:
     * ```php
     * $response->reset();
     * ```
     */
    public function reset(): void
    {
        $this->adapter = null;
    }

    /**
     * Retrieves the PSR-7 ResponseAdapter instance for the current Response.
     *
     * Instantiates and returns a {@see ResponseAdapter} for bridging the Yii2 Response component with PSR-7
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
    private function getAdapter(): ResponseAdapter
    {
        if ($this->adapter === null) {
            $this->adapter = new ResponseAdapter(
                $this,
                Yii::$container->get(ResponseFactoryInterface::class),
                Yii::$container->get(StreamFactoryInterface::class),
                Yii::$app->getSecurity(),
            );
        }

        return $this->adapter;
    }
}
