<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

/**
 * Represents standardized exit codes for server execution states.
 *
 * Defines exit codes used to indicate the result of server execution, including normal completion, request limit
 * exhaustion and graceful shutdown.
 *
 * This enum provides a consistent and type-safe way to communicate server termination reasons to external process
 * managers, monitoring tools, or runtime environments.
 *
 * Key features.
 * - Designed for use in PSR-7/PSR-15 compatible HTTP stacks and Yii2 bridge components.
 * - Distinguishes between successful execution, request limit reached and clean shutdown.
 * - Enables clear signaling of server lifecycle events for process orchestration.
 * - Immutable, type-safe values for integration with PHP runtimes and worker managers.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
enum ServerExitCode: int
{
    /**
     * Successful execution - server completed normally.
     */
    case OK = 0;

    /**
     * Request limit reached - server handled maximum allowed requests.
     */
    case REQUEST_LIMIT = 1;

    /**
     * Clean shutdown requested - server should terminate gracefully.
     */
    case SHUTDOWN = 2;
}
