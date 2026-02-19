<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use BackedEnum;
use PHPUnit\Framework\Attributes\{Group, TestWith};
use yii2\extensions\psrbridge\http\ServerExitCode;
use yii2\extensions\psrbridge\tests\support\TestCase;

/**
 * Unit tests for {@see ServerExitCode} enum value mappings.
 *
 * Test coverage.
 * - Ensures ServerExitCode enum cases map to expected integer values.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('http')]
final class ServerExitCodeTest extends TestCase
{
    /**
     * @param BackedEnum $exitCode ServerExitCode enum case under test.
     * @param int $expected Expected integer value for the enum case.
     */
    #[TestWith([ServerExitCode::OK, 0])]
    #[TestWith([ServerExitCode::REQUEST_LIMIT, 1])]
    #[TestWith([ServerExitCode::SHUTDOWN, 2])]
    public function testReturnCorrectValueForServerExitCodeCases(BackedEnum $exitCode, int $expected): void
    {
        self::assertSame(
            $expected,
            $exitCode->value,
            "ServerExitCode case '{$exitCode->name}' should have value '{$expected}', got '{$exitCode->value}'.",
        );
    }
}
