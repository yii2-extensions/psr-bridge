<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\adapter;

use ErrorException;
use HttpSoft\Message\{ResponseFactory, ServerRequest, StreamFactory};
use PHPUnit\Framework\Attributes\{PreserveGlobalState, RunTestsInSeparateProcesses, TestWith};
use Yii;
use yii\base\{InvalidConfigException, Security};
use yii\web\Cookie;
use yii2\extensions\psrbridge\adapter\{ResponseAdapter, ServerRequestAdapter};
use yii2\extensions\psrbridge\exception\Message;
use yii2\extensions\psrbridge\http\Response;
use yii2\extensions\psrbridge\tests\support\{ApplicationFactory, TestCase};

/**
 * Checks malformed input boundaries that static analysis alone cannot exercise.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdapterBoundaryTest extends TestCase
{
    public function testAnUnparseableCookieDateRemainsASessionCookie(): void
    {
        $response = new Response(['charset' => 'UTF-8']);

        $response->cookies->add(new Cookie(['name' => 'date', 'value' => 'value', 'expire' => 'not-a-date-value']));

        $header = (new ResponseAdapter($response, new ResponseFactory(), new StreamFactory(), new Security()))
            ->toPsr7()
            ->getHeaderLine('Set-Cookie');

        self::assertStringNotContainsString(
            'Expires=',
            $header,
            'Expiration date is omitted.',
        );
        self::assertStringNotContainsString(
            'Max-Age=',
            $header,
            'Maximum age is omitted.',
        );
    }

    #[TestWith(['resource'])]
    #[TestWith(['string'])]
    #[TestWith(['extra'])]
    #[TestWith(['missing-zero'])]
    #[TestWith(['missing-one'])]
    #[TestWith(['missing-two'])]
    #[TestWith(['sparse-zero'])]
    #[TestWith(['sparse-one'])]
    #[TestWith(['sparse-two'])]
    public function testMalformedFileTuplesFailWithTheFormatException(string $shape): void
    {
        $handle = fopen('php://temp', 'w+');

        self::assertIsResource(
            $handle,
            'Temporary stream must be open.',
        );

        $stream = match ($shape) {
            'resource' => $handle,
            'string' => 'invalid',
            'extra' => [$handle, 0, 0, 'extra'],
            'missing-zero' => [null, 0, 0],
            'missing-one' => [$handle, null, 0],
            'missing-two' => [$handle, 0, null],
            'sparse-zero' => [1 => 0, 2 => 0, 3 => 'extra'],
            'sparse-one' => [0 => $handle, 2 => 0, 3 => 'extra'],
            'sparse-two' => [0 => $handle, 1 => 0, 3 => 'extra'],
            default => throw new \RuntimeException('Unknown malformed tuple.'),
        };

        $response = new Response(['charset' => 'UTF-8', 'stream' => $stream]);

        set_error_handler(
            static function (int $severity, string $message): never {
                throw new ErrorException(
                    $message,
                    0,
                    $severity,
                );
            }
        );

        try {
            $this->expectException(InvalidConfigException::class);
            $this->expectExceptionMessage(
                Message::RESPONSE_STREAM_FORMAT_INVALID->getMessage(),
            );

            (new ResponseAdapter($response, new ResponseFactory(), new StreamFactory(), new Security()))->toPsr7();
        } finally {
            restore_error_handler();

            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    #[TestWith([''])]
    #[TestWith([false])]
    #[TestWith([42])]
    #[TestWith([[]])]
    public function testNonstringsCannotEnterCookieSignatureValidation(mixed $value): void
    {
        ApplicationFactory::web();

        $security = new class extends Security {
            public int $calls = 0;

            public function validateData($data, $key, $rawHash = false)
            {
                ++$this->calls;
                return parent::validateData($data, $key, $rawHash);
            }
        };

        Yii::$app->set('security', $security);

        $adapter = new ServerRequestAdapter(new ServerRequest(cookieParams: ['token' => $value]));

        self::assertSame(
            [],
            $adapter->getCookies(true, self::COOKIE_VALIDATION_KEY),
            'Invalid cookie values must be rejected.',
        );
        self::assertSame(
            0,
            $security->calls,
            'Rejected values must not reach validation.',
        );
    }

    public function testObjectParsedBodiesAreReturnedWithoutArrayAccess(): void
    {
        $body = (object) ['_method' => 'PUT', 'value' => 1];

        $adapter = new ServerRequestAdapter(new ServerRequest(parsedBody: $body));

        self::assertSame(
            $body,
            $adapter->getBodyParams('_method'),
            'Object body must retain its identity.',
        );
    }

    /**
     * @param array<mixed> $payload
     */
    #[TestWith([[]])]
    #[TestWith([[1 => 'value']])]
    #[TestWith([[0 => 'token']])]
    #[TestWith([[0 => 'wrong-name', 1 => 'value']])]
    public function testSignedCookiesRequireBothMatchingNameAndValue(array $payload): void
    {
        ApplicationFactory::web();

        $signed = (new Security())->hashData(serialize($payload), self::COOKIE_VALIDATION_KEY);
        $adapter = new ServerRequestAdapter(new ServerRequest(cookieParams: ['token' => $signed]));

        set_error_handler(
            static function (int $severity, string $message): never {
                throw new ErrorException(
                    $message,
                    0,
                    $severity,
                );
            }
        );
        try {
            self::assertSame(
                [],
                $adapter->getCookies(true, self::COOKIE_VALIDATION_KEY),
                'Mismatched signed-cookie payloads must be rejected.',
            );
        } finally {
            restore_error_handler();
        }
    }

    protected function tearDown(): void
    {
        if (Yii::$app !== null && Yii::$app->has('errorHandler', true)) {
            Yii::$app->errorHandler->unregister();
        }

        parent::tearDown();
    }
}
