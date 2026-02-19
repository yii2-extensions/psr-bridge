<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Yii;
use yii\base\Security;
use yii2\extensions\psrbridge\tests\support\stub\MockerFunctions;

use function fclose;
use function fwrite;
use function is_resource;
use function stream_get_meta_data;
use function tmpfile;
use function unlink;

/**
 * Base class for package integration tests.
 *
 * Provides application bootstrap helpers, cookie-signing utilities, and temporary file cleanup for isolated tests.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * A secret key used for cookie validation in tests.
     */
    protected const COOKIE_VALIDATION_KEY = 'test-cookie-validation-key';

    /**
     * Original $_SERVER superglobal values before tests modify them, to ensure proper restoration after each test.
     *
     * @phpstan-var array<mixed, mixed>
     */
    private array $originalServer = [];

    /**
     * Temporary file resources used during tests.
     *
     * @phpstan-var array<resource>
     */
    private array $tmpFiles = [];

    /**
     * Asserts that the given PSR-7 response matches the expected JSON response for the 'site/index' route.
     *
     * @param ResponseInterface $response PSR-7 response to assert against the expected values.
     */
    protected function assertSiteIndexJsonResponse(ResponseInterface $response): void
    {
        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/index'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/index'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"hello":"world"}
            JSON,
            $response->getBody()->getContents(),
            "Expected JSON Response body '{\"hello\":\"world\"}'.",
        );
    }

    /**
     * Asserts that the given PSR-7 response matches the expected JSON response for the 'site/post' route.
     *
     * @param ResponseInterface $response PSR-7 response to assert against the expected values.
     */
    protected function assertSitePostUploadJsonResponse(ResponseInterface $response): void
    {
        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route '/site/post'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/post'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"action": "upload"}
            JSON,
            $response->getBody()->getContents(),
            "Expected PSR-7 Response body '{\"action\":\"upload\"}'.",
        );
    }

    /**
     * Closes the application and flushes the logger to ensure all logs are written out.
     *
     * @throws RuntimeException if an error occurs while closing the application or flushing the logger.
     */
    protected function closeApplication(): void
    {
        if (Yii::$app->has('session')) {
            $session = Yii::$app->getSession();

            if ($session->getIsActive()) {
                $session->destroy();
                $session->close();
            }
        }

        // ensure the logger is flushed after closing the application
        $logger = Yii::getLogger();
        $logger->flush();
    }

    /**
     * Closes the temporary file resource if it is a valid resource.
     *
     * @param resource ...$tmpFile Temporary file resources to close.
     */
    protected function closeTmpFile(...$tmpFile): void
    {
        foreach ($tmpFile as $file) {
            if (is_resource($file)) {
                fclose($file);
            }
        }
    }

    /**
     * Creates a temporary file with the specified content for testing.
     *
     * @param string $content Content to write to the temporary file.
     *
     * @return string Path to the created temporary file.
     */
    protected function createTempFileWithContent(string $content): string
    {
        $tmpPathFile = $this->createTmpFile();
        $handle = fopen($tmpPathFile, 'wb');

        if ($handle === false) {
            unlink($tmpPathFile);

            throw new RuntimeException('Unable to open temporary file for writing.');
        }

        $bytesWritten = fwrite($handle, $content);
        fclose($handle);

        if ($bytesWritten === false || $bytesWritten !== strlen($content)) {
            unlink($tmpPathFile);

            throw new RuntimeException('Unable to write content to temporary file.');
        }

        return $tmpPathFile;
    }

    /**
     * Creates a temporary file and registers its resource for cleanup.
     *
     * This method creates a new temporary file using the system's temporary directory and stores the file resource in
     * the internal list for later cleanup during test teardown. Returns the file path to the created temporary file.
     *
     * @throws RuntimeException If the temporary file cannot be created.
     * @return string Path to the created temporary file.
     */
    protected function createTmpFile(): string
    {
        $tmpFile = tmpfile();

        if ($tmpFile === false) {
            throw new RuntimeException('Failed to create temporary file.');
        }

        $this->tmpFiles[] = $tmpFile;

        return stream_get_meta_data($tmpFile)['uri'] ?? '';
    }

    protected function setUp(): void
    {
        parent::setUp();

        MockerFunctions::reset();

        $this->originalServer = $_SERVER;

        $_SERVER = [];
    }

    /**
     * Signs the given cookie parameters using Yii's Security component and the defined cookie validation key.
     *
     * @phpstan-param array<string, string|object> $cookieParams
     *
     * @phpstan-return array<string, string>
     */
    protected function signCookies(array $cookieParams): array
    {
        $security = new Security();
        $signed = [];

        foreach ($cookieParams as $name => $value) {
            $signed[$name] = $security->hashData(serialize([$name, $value]), self::COOKIE_VALIDATION_KEY);
        }

        return $signed;
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        $_FILES = [];
        $_GET = [];
        $_POST = [];
        $_SERVER = $this->originalServer;

        $this->closeTmpFile(...$this->tmpFiles);

        parent::tearDown();
    }
}
