<?php

declare(strict_types=1);

namespace App\Bot\View;

final class Html
{
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
