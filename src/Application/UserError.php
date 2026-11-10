<?php

declare(strict_types=1);

namespace App\Application;

use RuntimeException;

/**
 * Business rule violation whose message is safe and meant to be shown to the user as is.
 *
 * The key is the Russian text and doubles as the translation id; %placeholders% are filled from the parameters.
 * getMessage() is the Russian text with the parameters filled in.
 */
final class UserError extends RuntimeException
{
    /**
     * @param array<string, string|int> $parameters
     */
    public function __construct(
        public readonly string $key,
        public readonly array $parameters = [],
    ) {
        parent::__construct(strtr($key, array_map('strval', $parameters)));
    }
}
