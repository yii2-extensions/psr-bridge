<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\emitter;

/**
 * Defines supported units for HTTP Content-Range headers.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc7233#section-4.2 RFC 7233 section 4.2.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
enum ContentRangeUnit: string
{
    /**
     * Bytes unit used in Content-Range headers.
     */
    case BYTES = 'bytes';
}
