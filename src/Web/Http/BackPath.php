<?php

declare(strict_types=1);

namespace App\Web\Http;

/**
 * Where a settings form returns to: a path on this site only, never another host.
 */
final class BackPath
{
    private const string LOCAL_PATH = '#^/(?![/\\\\])[^\s]*$#';

    public static function orRoot(string $back): string
    {
        return preg_match(self::LOCAL_PATH, $back) === 1 ? $back : '/';
    }
}
