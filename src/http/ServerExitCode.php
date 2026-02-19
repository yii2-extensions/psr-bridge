<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

/**
 * Represents process exit codes for server lifecycle outcomes.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
enum ServerExitCode: int
{
    /**
     * Indicates successful execution.
     */
    case OK = 0;

    /**
     * Indicates the configured request limit was reached.
     */
    case REQUEST_LIMIT = 1;

    /**
     * Indicates a graceful shutdown was requested.
     */
    case SHUTDOWN = 2;
}
