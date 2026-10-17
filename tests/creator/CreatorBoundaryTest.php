<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\creator;

use HttpSoft\Message\{ServerRequestFactory, StreamFactory, UploadedFileFactory};
use PHPUnit\Framework\Attributes\TestWith;
use yii2\extensions\psrbridge\creator\ServerRequestCreator;
use yii2\extensions\psrbridge\tests\support\TestCase;

/**
 * Checks the native parsed-body truth table independently of worker subprocesses.
 */
final class CreatorBoundaryTest extends TestCase
{
    public function testExistingNativeFormDataIsPreservedRegardlessOfMethodAndMime(): void
    {
        $_POST = ['kept' => 'yes'];
        $_GET = $_COOKIE = $_FILES = [];
        $_SERVER = ['REQUEST_METHOD' => 'PUT', 'REQUEST_URI' => '/', 'CONTENT_TYPE' => 'application/json'];

        $creator = new ServerRequestCreator(new ServerRequestFactory(), new StreamFactory(), new UploadedFileFactory());

        self::assertSame(
            $_POST,
            $creator->createFromGlobals()->getParsedBody(),
            'Existing form data must be preserved.',
        );
    }

    /**
     * @param array<mixed>|null $expected
     */
    #[TestWith(['POST', 'application/x-www-form-urlencoded', []])]
    #[TestWith(['post', 'Application/X-WWW-Form-Urlencoded; charset=UTF-8; x=y', []])]
    #[TestWith(['POST', 'multipart/form-data; boundary=abc', []])]
    #[TestWith(['POST', 'application/json', null])]
    #[TestWith(['POST', ';application/x-www-form-urlencoded', null])]
    #[TestWith(['POST', '', null])]
    #[TestWith(['PUT', 'application/x-www-form-urlencoded', null])]
    #[TestWith(['GET', 'application/json', null])]
    public function testNativeEmptyBodySemantics(string $method, string $type, array|null $expected): void
    {
        $_POST = $_GET = $_COOKIE = $_FILES = [];
        $_SERVER = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/', 'CONTENT_TYPE' => $type, 'CONTENT_LENGTH' => '0'];
        $creator = new ServerRequestCreator(new ServerRequestFactory(), new StreamFactory(), new UploadedFileFactory());
        $request = $creator->createFromGlobals();

        self::assertSame(
            $expected,
            $request->getParsedBody(),
            'Parsed body must match native empty-body semantics.',
        );
        self::assertSame(
            $type,
            $request->getHeaderLine('Content-Type'),
            'Content type must be preserved.',
        );
        self::assertSame(
            '0',
            $request->getHeaderLine('Content-Length'),
            'Content length must be preserved.',
        );
    }
}
