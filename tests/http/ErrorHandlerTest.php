<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Throwable;
use yii\base\{Exception, UserException};
use yii\web\HttpException;
use yii2\extensions\psrbridge\http\{ErrorHandler, Response};
use yii2\extensions\psrbridge\tests\support\stub\HTTPFunctions;
use yii2\extensions\psrbridge\tests\TestCase;

use function str_repeat;

#[Group('http')]
final class ErrorHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        HTTPFunctions::reset();

        parent::tearDown();
    }

    public function testHandleExceptionResetsState(): void
    {
        $errorHandler = new ErrorHandler();

        $errorHandler->discardExistingOutput = false;

        $exception = new Exception('Test exception');

        $response = $errorHandler->handleException($exception);

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Should set correct status code after 'handlingException()'.",
        );
    }

    public function testHandleExceptionWithComplexMessage(): void
    {
        $errorHandler = new ErrorHandler();

        $errorHandler->discardExistingOutput = false;

        $exception = new Exception('Complex exception with special chars: <>&"\'');

        $response = $errorHandler->handleException($exception);

        self::assertSame(
            500,
            $response->getStatusCode(),
            'Should set correct status code for complex exception.',
        );
        self::assertIsString(
            $response->data,
            'Should set response data as string for complex exception.',
        );
    }

    public function testHandleExceptionWithEmptyMessage(): void
    {
        $errorHandler = new ErrorHandler();

        $errorHandler->discardExistingOutput = false;

        $exception = new Exception('');

        $response = $errorHandler->handleException($exception);

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Should set status code to '500' for 'Exception' with empty message.",
        );
        self::assertNotNull(
            $response->data,
            "Should set response data even for 'Exception' with empty message.",
        );
    }

    public function testHandleExceptionWithGenericException(): void
    {
        $errorHandler = new ErrorHandler();

        $errorHandler->discardExistingOutput = false;

        $exception = new Exception('Generic test exception');

        $response = $errorHandler->handleException($exception);

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Should set status code to '500' for generic exception.",
        );
        self::assertNotEmpty(
            $response->data,
            'Should set response data with exception information.',
        );
    }

    #[TestWith(['apache'])]
    #[TestWith(['cli'])]
    public function testHandleExceptionWithHttpException(string $sapi): void
    {
        HTTPFunctions::set_sapi($sapi);

        $errorHandler = new ErrorHandler();

        $errorHandler->discardExistingOutput = false;

        $exception = new HttpException(404, 'Page not found');

        $response = $errorHandler->handleException($exception);

        self::assertSame(
            404,
            $response->getStatusCode(),
            "Should preserve HTTP status code from 'HttpException'.",
        );
        self::assertNotEmpty(
            $response->data,
            'Should set response data for HTTP exception.',
        );
    }

    public function testHandleExceptionWithLongMessage(): void
    {
        $errorHandler = new ErrorHandler();

        $errorHandler->discardExistingOutput = false;

        $longMessage = str_repeat('This is a very long error message. ', 100);

        $exception = new Exception($longMessage);

        $response = $errorHandler->handleException($exception);

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Should set correct status code for 'Exception' with long message.",
        );
        self::assertNotEmpty(
            $response->data,
            "Should set response data for 'Exception' with long message.",
        );
    }

    public function testHandleExceptionWithMultipleDifferentExceptions(): void
    {
        $exceptions = [
            new Exception('First exception'),
            new RuntimeException('Second exception'),
            new HttpException(400, 'Bad request'),
            new UserException('User error'),
        ];

        foreach ($exceptions as $index => $exception) {
            $errorHandler = new ErrorHandler();

            $errorHandler->discardExistingOutput = false;

            $response = $errorHandler->handleException($exception);

            if ($exception instanceof HttpException) {
                self::assertSame(
                    $exception->statusCode,
                    $response->getStatusCode(),
                    "Should preserve HTTP status code for 'HttpException' {$index}.",
                );
            } else {
                self::assertSame(
                    500,
                    $response->getStatusCode(),
                    "Should set status code to '500' for non 'HTTPException' {$index}.",
                );
            }
            self::assertNotEmpty(
                $response->data,
                "Should set response data for exceptions {$index}.",
            );
        }
    }

    public function testHandleExceptionWithNestedExceptions(): void
    {
        $errorHandler = new ErrorHandler();

        $errorHandler->discardExistingOutput = false;

        $innerException = new RuntimeException('Inner exception');
        $outerException = new Exception('Outer exception', 0, $innerException);

        $response = $errorHandler->handleException($outerException);

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Should set status code to '500' for nested exceptions.",
        );
        self::assertNotEmpty(
            $response->data,
            'Should set response data for nested exceptions.',
        );
    }

    public function testHandleExceptionWithRuntimeException(): void
    {
        $errorHandler = new ErrorHandler();

        $errorHandler->discardExistingOutput = false;

        $exception = new RuntimeException('Runtime test exception');

        $response = $errorHandler->handleException($exception);

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Should set status code to '500' for RuntimeException.",
        );
        self::assertNotEmpty(
            $response->data,
            'Should set response data for runtime exception.',
        );
    }

    public function testHandleExceptionWithSpecialCharactersInTrace(): void
    {
        $errorHandler = new ErrorHandler();

        $errorHandler->discardExistingOutput = false;

        try {
            throw new Exception('Test with <script>alert("xss")</script>');
        } catch (Throwable $exception) {
            $response = $errorHandler->handleException($exception);

            self::assertSame(
                500,
                $response->getStatusCode(),
                "Should set correct status code for 'Exception' with special trace.",
            );
            self::assertIsString(
                $response->data,
                "Should set response data as string for 'Exception' with special trace.",
            );
        }
    }

    public function testHandleExceptionWithUserException(): void
    {
        $errorHandler = new ErrorHandler();

        $errorHandler->discardExistingOutput = false;

        $exception = new UserException('User-friendly error message');

        $response = $errorHandler->handleException($exception);

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Should set status code to '500' for 'UserException'.",
        );
        self::assertNotEmpty(
            $response->data,
            'Should set response data for user exception.',
        );
    }

    public function testHandleExceptionWithZeroCode(): void
    {
        $errorHandler = new ErrorHandler();

        $errorHandler->discardExistingOutput = false;

        $exception = new Exception('Exception with zero code', 0);

        $response = $errorHandler->handleException($exception);

        self::assertSame(
            500,
            $response->getStatusCode(),
            "Should set status code to '500' for 'Exception' with zero code.",
        );
        self::assertNotEmpty(
            $response->data,
            "Should set response data for 'Exception' with zero code.",
        );
    }

    public function testResponseDataIsNotEmpty(): void
    {
        $errorHandler = new ErrorHandler();

        $errorHandler->discardExistingOutput = false;

        $exception = new Exception('Test exception for data validation');

        $response = $errorHandler->handleException($exception);

        self::assertNotEmpty(
            $response->data,
            'Should always set non-empty response data.',
        );
        self::assertIsString(
            $response->data,
            "'Response' data should be string.",
        );
    }

    public function testResponseFormatDefaultsToHtml(): void
    {
        $errorHandler = new ErrorHandler();

        $errorHandler->discardExistingOutput = false;

        $exception = new Exception('Test exception for format validation');

        $response = $errorHandler->handleException($exception);

        self::assertSame(
            Response::FORMAT_HTML,
            $response->format,
            'Should default to HTML format.',
        );
    }
}
