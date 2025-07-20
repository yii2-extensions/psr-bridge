<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use PHPUnit\Framework\Attributes\Group;
use Yii;
use yii\base\InvalidConfigException;
use yii\web\CookieCollection;
use yii2\extensions\psrbridge\http\Request;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

#[Group('http')]
final class PSR7RequestTest extends TestCase
{
    public function testResetCookieCollectionAfterReset(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams(['reset_cookie' => 'test_value']);

        $request = new Request();

        $request->enableCookieValidation = false;
        $request->cookieValidationKey = 'test-validation-key-32-characters';

        $request->setPsr7Request($psr7Request);
        $cookies1 = $request->getCookies();

        $request->reset();

        $newPsr7Request = FactoryHelper::createRequest('GET', '/test');

        $newPsr7Request = $newPsr7Request->withCookieParams(['new_cookie' => 'new_value']);
        $request->setPsr7Request($newPsr7Request);
        $cookies2 = $request->getCookies();

        self::assertNotSame(
            $cookies1,
            $cookies2,
            "After reset, 'getCookies()' should return a new 'CookieCollection' instance.",
        );
        self::assertTrue(
            $cookies2->has('new_cookie'),
            "New 'CookieCollection' should contain 'new_cookie' after reset.",
        );
        self::assertSame(
            'new_value',
            $cookies2->getValue('new_cookie'),
            "New cookie 'new_cookie' should have the expected value after reset.",
        );
    }
    public function testReturnBodyParamsWhenPsr7RequestHasFormData(): void
    {
        $psr7Request = FactoryHelper::createRequest(
            'POST',
            '/test',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            [
                'key1' => 'value1',
                'key2' => 'value2',
            ],
        );

        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $bodyParams = $request->getBodyParams();

        self::assertIsArray(
            $bodyParams,
            "Body parameters should be returned as an array when 'PSR-7' request contains form data.",
        );
        self::assertArrayHasKey(
            'key1',
            $bodyParams,
            "Body parameters should contain the key 'key1' when present in the 'PSR-7' request.",
        );
        self::assertSame(
            'value1',
            $bodyParams['key1'] ?? null,
            "Body parameter 'key1' should have the expected value from the 'PSR-7' request.",
        );
        self::assertArrayHasKey(
            'key2',
            $bodyParams,
            "Body parameters should contain the key 'key2' when present in the 'PSR-7' request.",
        );
        self::assertSame(
            'value2',
            $bodyParams['key2'] ?? null,
            "Body parameter 'key2' should have the expected value from the 'PSR-7' request.",
        );
    }

    public function testReturnBodyParamsWithMethodParamRemoved(): void
    {
        $psr7Request = FactoryHelper::createRequest(
            'POST',
            '/test',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            [
                'key1' => 'value1',
                'key2' => 'value2',
                '_method' => 'PUT',
            ],
        );

        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $bodyParams = $request->getBodyParams();

        self::assertIsArray(
            $bodyParams,
            'Body parameters should be returned as an array when method parameter is present.',
        );
        self::assertArrayNotHasKey(
            '_method',
            $bodyParams,
            "Method parameter '_method' should be removed from body parameters.",
        );
        self::assertArrayHasKey(
            'key1',
            $bodyParams,
            "Body parameters should contain the key 'key1' after method parameter removal.",
        );
        self::assertSame(
            'value1',
            $bodyParams['key1'] ?? null,
            "Body parameter 'key1' should have the expected value after method parameter removal.",
        );
        self::assertArrayHasKey(
            'key2',
            $bodyParams,
            "Body parameters should contain the key 'key2' after method parameter removal.",
        );
        self::assertSame(
            'value2',
            $bodyParams['key2'] ?? null,
            "Body parameter 'key2' should have the expected value after method parameter removal.",
        );
    }

    public function testReturnCookieCollectionWhenCookiesPresent(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams(
            [
                'session_id' => 'abc123',
                'theme' => 'dark',
                'empty_cookie' => '',
            ],
        );

        $request = new Request();

        $request->enableCookieValidation = false;
        $request->cookieValidationKey = 'test-validation-key-32-characters';

        $request->setPsr7Request($psr7Request);
        $cookies = $request->getCookies();

        self::assertInstanceOf(
            CookieCollection::class,
            $cookies,
            "Cookies should return a 'CookieCollection' instance when cookies are present.",
        );
        self::assertCount(
            2,
            $cookies,
            "'CookieCollection' should contain '2' cookies when empty cookies are filtered out.",
        );
        self::assertTrue(
            $cookies->has('session_id'),
            "'CookieCollection' should contain 'session_id' cookie.",
        );
        self::assertSame(
            'abc123',
            $cookies->getValue('session_id'),
            "Cookie 'session_id' should have the expected value from the 'PSR-7' request.",
        );
        self::assertTrue(
            $cookies->has('theme'),
            "'CookieCollection' should contain 'theme' cookie.",
        );
        self::assertSame(
            'dark',
            $cookies->getValue('theme'),
            "Cookie 'theme' should have the expected value from the 'PSR-7' request.",
        );
        self::assertFalse(
            $cookies->has('empty_cookie'),
            "'CookieCollection' should not contain empty cookies.",
        );
    }

    public function testReturnCookieCollectionWhenNoCookiesPresent(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $request = new Request();

        $request->enableCookieValidation = false;
        $request->cookieValidationKey = 'test-validation-key-32-characters';

        $request->setPsr7Request($psr7Request);
        $cookies = $request->getCookies();

        self::assertInstanceOf(
            CookieCollection::class,
            $cookies,
            "Cookies should return a 'CookieCollection' instance when no cookies are present.",
        );
        self::assertCount(
            0,
            $cookies,
            "'CookieCollection' should be empty when no cookies are present in the 'PSR-7' request.",
        );
    }

    public function testReturnCookieCollectionWithValidationDisabled(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams(
            [
                'user_id' => '42',
                'preferences' => 'compact',
            ],
        );

        $request = new Request();

        $request->enableCookieValidation = false;
        $request->cookieValidationKey = 'test-validation-key-32-characters';

        $request->setPsr7Request($psr7Request);
        $cookies = $request->getCookies();

        self::assertInstanceOf(
            CookieCollection::class,
            $cookies,
            "Cookies should return a 'CookieCollection' instance when validation is disabled.",
        );
        self::assertCount(
            2,
            $cookies,
            "'CookieCollection' should contain all non-empty cookies when validation is disabled.",
        );
        self::assertTrue(
            $cookies->has('user_id'),
            "'CookieCollection' should contain 'user_id' cookie when validation is disabled.",
        );
        self::assertSame(
            '42',
            $cookies->getValue('user_id'),
            "Cookie 'user_id' should have the expected value when validation is disabled.",
        );
        self::assertTrue(
            $cookies->has('preferences'),
            "'CookieCollection' should contain 'preferences' cookie when validation is disabled.",
        );
        self::assertSame(
            'compact',
            $cookies->getValue('preferences'),
            "Cookie 'preferences' should have the expected value when validation is disabled.",
        );
    }

    public function testReturnEmptyCookieCollectionWhenValidationEnabledButNoValidationKey(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams(['session_id' => 'abc123']);

        $request = new Request();

        $request->enableCookieValidation = true;
        $request->cookieValidationKey = '';

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Cookie validation key must be provided.');

        $request->setPsr7Request($psr7Request);
        $request->getCookies();
    }

    public function testReturnEmptyCookieCollectionWhenValidationEnabledWithInvalidCookies(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams(
            [
                'invalid_cookie' => 'invalid_data',
                'empty_cookie' => '',
            ],
        );

        $request = new Request();

        $request->enableCookieValidation = true;
        $request->cookieValidationKey = 'test-validation-key-32-characters';

        $request->setPsr7Request($psr7Request);
        $cookies = $request->getCookies();

        self::assertInstanceOf(
            CookieCollection::class,
            $cookies,
            "Cookies should return a 'CookieCollection' instance when validation is enabled with invalid cookies.",
        );
        self::assertCount(
            0,
            $cookies,
            "'CookieCollection' should be empty when validation is enabled but cookies are invalid.",
        );
    }

    public function testReturnNewCookieCollectionInstanceOnEachCall(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams(['cached_cookie' => 'test_value']);

        $request = new Request();

        $request->enableCookieValidation = false;
        $request->cookieValidationKey = 'test-validation-key-32-characters';

        $request->setPsr7Request($psr7Request);
        $cookies1 = $request->getCookies();
        $cookies2 = $request->getCookies();

        self::assertNotSame(
            $cookies1,
            $cookies2,
            "Each call to 'getCookies()' should return a new 'CookieCollection' instance, not a cached one.",
        );
    }

    public function testReturnValidatedCookiesWhenValidationEnabledWithValidCookies(): void
    {
        $this->mockWebApplication();

        $validationKey = 'test-validation-key-32-characters';

        // create a valid signed cookie using Yii security component
        $cookieName = 'valid_session';
        $cookieValue = 'abc123session';
        $data = [$cookieName, $cookieValue];

        $signedCookieValue = Yii::$app->getSecurity()->hashData(\yii\helpers\Json::encode($data), $validationKey);
        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams(
            [
                $cookieName => $signedCookieValue,
                'invalid_cookie' => 'invalid_data',
            ],
        );

        $request = new Request();

        $request->enableCookieValidation = true;
        $request->cookieValidationKey = $validationKey;

        $request->setPsr7Request($psr7Request);
        $cookies = $request->getCookies();

        self::assertInstanceOf(
            CookieCollection::class,
            $cookies,
            "Cookies should return a 'CookieCollection' instance when validation is enabled with valid cookies.",
        );
        self::assertCount(
            1,
            $cookies,
            "'CookieCollection' should contain only the valid signed cookie when validation is enabled.",
        );
        self::assertTrue(
            $cookies->has($cookieName),
            "'CookieCollection' should contain the valid signed cookie '{$cookieName}'.",
        );
        self::assertSame(
            $cookieValue,
            $cookies->getValue($cookieName),
            "Valid signed cookie '{$cookieName}' should have the expected decrypted value.",
        );
        self::assertFalse(
            $cookies->has('invalid_cookie'),
            "'CookieCollection' should not contain invalid cookies when validation is enabled.",
        );
    }
}
