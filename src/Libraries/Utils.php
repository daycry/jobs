<?php

declare(strict_types=1);

/**
 * This file is part of Daycry Queues.
 *
 * (c) Daycry <daycry9@proton.me>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Daycry\Jobs\Libraries;

use Daycry\Jobs\Config\Validation;
use Daycry\Jobs\Exceptions\JobException;

/**
 * Generic helper utilities for job/queue validation & config parsing.
 */
class Utils
{
    /**
     * Validate queue job data against a named rule set using framework validator.
     * Throws JobException on failure.
     */
    public static function checkDataQueue(array|object $data, string $rule): void
    {
        if (! is_array($data)) {
            $data = json_decode(json_encode($data), true);
        }

        $validator = service('validation', config(Validation::class), false);

        if (! $validator->reset()->run($data, $rule)) {
            throw JobException::validationError($validator->listErrors());
        }
    }

    /**
     * Parse comma-delimited string or pass-through array into trimmed array of strings.
     *
     * @param mixed $attr
     */
    public static function parseConfigFile($attr): array
    {
        if ($attr && ! is_array($attr)) {
            $attr = explode(',', $attr);
        }

        return array_map('trim', $attr);
    }
}
