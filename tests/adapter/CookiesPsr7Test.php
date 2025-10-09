<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\adapter;

use PHPUnit\Framework\Attributes\Group;
use yii\base\{InvalidCallException, InvalidConfigException};
use yii\web\Cookie;
use yii2\extensions\psrbridge\exception\Message;
use yii2\extensions\psrbridge\http\Request;
use yii2\extensions\psrbridge\tests\support\{FactoryHelper, TestCase};

/**
 * Test suite for {@see Request} cookie handling functionality and behavior.
 *
 * Verifies correct PSR-7 cookie adapter behavior, including read-only cookie collection enforcement and validation key
 * requirements when processing cookies through the Yii2 PSR bridge layer.
 *
 * Test coverage.
 * - Confirms read-only enforcement preventing modifications to cookie collections with PSR-7 adapter.
 * - Ensures proper cookie parameter processing from PSR-7 requests.
 * - Validates exception handling when cookie validation is enabled without a validation key.
 * - Verifies configuration validation for secure cookie handling.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('adapter')]
#[Group('cookies')]
final class CookiesPsr7Test extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testThrowInvalidCallExceptionWhenReturnReadOnlyCookieCollectionWhenAdapterIsSet(): void
    {
        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams(
            [
                'another_cookie' => 'another_value',
                'test_cookie' => 'test_value',
            ],
        );

        $request = new Request();

        $request->enableCookieValidation = false;
        $request->cookieValidationKey = self::COOKIE_VALIDATION_KEY;

        $request->setPsr7Request($psr7Request);

        $cookies = $request->getCookies();

        $this->expectException(InvalidCallException::class);
        $this->expectExceptionMessage('The cookie collection is read only.');

        $cookies->add(
            new Cookie(
                [
                    'name' => 'new_cookie',
                    'value' => 'new_value',
                ],
            ),
        );
    }

    public function testThrowInvalidConfigExceptionWhenValidationEnabledButNoValidationKey(): void
    {
        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams(['session_id' => 'abc123']);

        $request = new Request();

        $request->enableCookieValidation = true;
        $request->cookieValidationKey = '';

        $request->setPsr7Request($psr7Request);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(Message::COOKIE_VALIDATION_KEY_REQUIRED->getMessage());

        $request->getCookies();
    }
}
