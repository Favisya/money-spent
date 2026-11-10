<?php

declare(strict_types=1);

namespace App\Application;

use RuntimeException;

/**
 * Business rule violation whose message is safe and meant to be shown to the user as is.
 */
final class UserError extends RuntimeException
{
}
