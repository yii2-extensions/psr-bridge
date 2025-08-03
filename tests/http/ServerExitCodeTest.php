<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use BackedEnum;
use PHPUnit\Framework\Attributes\{Group, TestWith};
use yii2\extensions\psrbridge\http\ServerExitCode;
use yii2\extensions\psrbridge\tests\TestCase;

#[Group('http')]
final class ServerExitCodeTest extends TestCase
{
    /**
     * @param BackedEnum $exitCode ServerExitCode enum case under test.
     * @param int $expected Expected integer value for the enum case.
     */
    #[TestWith([ServerExitCode::OK, 0])]
    #[TestWith([ServerExitCode::SHUTDOWN, 1])]
    #[TestWith([ServerExitCode::REQUEST_LIMIT, 2])]
    public function testReturnCorrectValueForServerExitCodeCases(BackedEnum $exitCode, int $expected): void
    {
        self::assertSame(
            $expected,
            $exitCode->value,
            "ServerExitCode case '{$exitCode->name}' should have value '{$expected}', got '{$exitCode->value}'.",
        );
    }
}
