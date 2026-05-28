<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

/**
 * Represents process exit codes for server lifecycle outcomes.
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
