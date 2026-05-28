<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\Group;
use yii\base\{InvalidConfigException, Security};
use yii\helpers\Json;
use yii2\extensions\psrbridge\tests\support\{ApplicationFactory, HelperFactory, TestCase};

use function array_filter;
use function array_values;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function urldecode;

/**
 * Unit tests for {@see \yii2\extensions\psrbridge\http\Application} CSRF validation in stateless mode.
 */
#[Group('http')]
final class ApplicationCsrfTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testIsolateCsrfTokenStateBetweenRequestsOnSameApplicationInstance(): void
    {
        $security = new Security();

        $rawTokenA = $security->generateRandomString();
        $maskedTokenA = $security->maskToken($rawTokenA);

        $cookiesA = $this->signCookies(['_csrf' => $rawTokenA]);

        $rawTokenB = $security->generateRandomString();
        $maskedTokenB = $security->maskToken($rawTokenB);

        $cookiesB = $this->signCookies(['_csrf' => $rawTokenB]);

        $app = ApplicationFactory::stateless($this->csrfConfig());

        $responseA = $app->handle(
            HelperFactory::createRequest(
                'POST',
                '/site/post',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                ['action' => 'a', '_csrf' => $maskedTokenA],
            )->withCookieParams($cookiesA),
        );
        $responseB = $app->handle(
            HelperFactory::createRequest(
                'POST',
                '/site/post',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                ['action' => 'b', '_csrf' => $maskedTokenB],
            )->withCookieParams($cookiesB),
        );
        $responseMismatched = $app->handle(
            HelperFactory::createRequest(
                'POST',
                '/site/post',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                ['action' => 'c', '_csrf' => $maskedTokenA],
            )->withCookieParams($cookiesB),
        );

        self::assertSame(
            200,
            $responseA->getStatusCode(),
            'First request must pass with its own CSRF token.',
        );
        self::assertSame(
            200,
            $responseB->getStatusCode(),
            'Second request must pass with its own CSRF token.',
        );
        self::assertSame(
            400,
            $responseMismatched->getStatusCode(),
            'Token paired with a different cookie must be rejected.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnBadRequestResponseForPostWithMissingCsrfToken(): void
    {
        $rawToken = (new Security())->generateRandomString();

        $cookies = $this->signCookies(['_csrf' => $rawToken]);

        $app = ApplicationFactory::stateless($this->csrfConfig());

        $response = $app->handle(
            HelperFactory::createRequest(
                'POST',
                '/site/post',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                ['action' => 'upload'],
            )->withCookieParams($cookies),
        );

        self::assertSame(
            400,
            $response->getStatusCode(),
            "Expected HTTP '400' for route 'site/post'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnBadRequestResponseForPostWithTamperedCsrfToken(): void
    {
        $security = new Security();

        $rawToken = $security->generateRandomString();

        $cookies = $this->signCookies(['_csrf' => $rawToken]);

        $tamperedToken = $security->maskToken($security->generateRandomString());

        $app = ApplicationFactory::stateless($this->csrfConfig());

        $response = $app->handle(
            HelperFactory::createRequest(
                'POST',
                '/site/post',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                ['action' => 'upload', '_csrf' => $tamperedToken],
            )->withCookieParams($cookies),
        );

        self::assertSame(
            400,
            $response->getStatusCode(),
            "Expected HTTP '400' for route 'site/post'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnSignedCsrfCookieInResponseSetCookieHeader(): void
    {
        $app = ApplicationFactory::stateless($this->csrfConfig());

        $response = $app->handle(HelperFactory::createRequest('GET', '/site/csrf'));

        $csrfCookies = array_filter(
            $response->getHeader('Set-Cookie'),
            static fn(string $header): bool => str_starts_with($header, '_csrf='),
        );

        self::assertCount(
            1,
            $csrfCookies,
            'Response must emit exactly one CSRF Set-Cookie header.',
        );

        $header = array_values($csrfCookies)[0] ?? '';
        $rawValue = substr($header, strlen('_csrf='));
        $semicolon = strpos($rawValue, ';');
        $signedValue = urldecode($semicolon === false ? $rawValue : substr($rawValue, 0, $semicolon));

        self::assertIsString(
            (new Security())->validateData($signedValue, self::COOKIE_VALIDATION_KEY),
            'CSRF cookie value must be signed with the validation key.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnSuccessResponseForPostWithValidCsrfTokenInBody(): void
    {
        $security = new Security();

        $rawToken = $security->generateRandomString();
        $maskedToken = $security->maskToken($rawToken);

        $cookies = $this->signCookies(['_csrf' => $rawToken]);

        $app = ApplicationFactory::stateless($this->csrfConfig());

        $response = $app->handle(
            HelperFactory::createRequest(
                'POST',
                '/site/post',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                ['action' => 'upload', '_csrf' => $maskedToken],
            )->withCookieParams($cookies),
        );

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/post'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/post'.",
        );
        self::assertJsonStringEqualsJsonString(
            Json::encode(['action' => 'upload', '_csrf' => $maskedToken]),
            $response->getBody()->getContents(),
            'Body must echo posted parameters when the CSRF token is valid.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnSuccessResponseForPostWithValidCsrfTokenInHeader(): void
    {
        $security = new Security();

        $rawToken = $security->generateRandomString();
        $maskedToken = $security->maskToken($rawToken);

        $cookies = $this->signCookies(['_csrf' => $rawToken]);

        $app = ApplicationFactory::stateless($this->csrfConfig());

        $response = $app->handle(
            HelperFactory::createRequest(
                'POST',
                '/site/post',
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'X-CSRF-Token' => $maskedToken,
                ],
                ['action' => 'upload'],
            )->withCookieParams($cookies),
        );

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/post'.",
        );
        self::assertJsonStringEqualsJsonString(
            Json::encode(['action' => 'upload']),
            $response->getBody()->getContents(),
            'Body must echo posted parameters when the token is sent via header.',
        );
    }

    /**
     * Stateless application configuration overrides enabling CSRF and cookie validation.
     *
     * @return array Configuration overrides for {@see ApplicationFactory::stateless()}.
     *
     * @phpstan-return array<string, mixed>
     */
    private function csrfConfig(): array
    {
        return [
            'components' => [
                'request' => [
                    'cookieValidationKey' => self::COOKIE_VALIDATION_KEY,
                    'enableCookieValidation' => true,
                    'enableCsrfCookie' => true,
                    'enableCsrfValidation' => true,
                ],
            ],
        ];
    }
}
