<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use BackedEnum;
use PHPUnit\Framework\Attributes\{Group, TestWith};
use yii2\extensions\psrbridge\http\ServerExitCode;
use yii2\extensions\psrbridge\tests\support\TestCase;

/**
 * Test suite for {@see ServerExitCode} enum value mapping.
 *
 * Verifies that each ServerExitCode enum case returns the expected integer value, ensuring correct mapping between enum
 * cases and their integer representations.
 *
 * Test coverage.
 * - Confirms integer values for all ServerExitCode cases.
 * - Ensures enum-to-integer mapping is correct and stable.
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
