<?php

declare(strict_types=1);

namespace App\Application\Expense;

/**
 * The short "what for" comment of an expense ("свет", "газ"): trimmed, collapsed, dropped when it only repeats the
 * category title.
 */
final class NoteText
{
    public const int MAX_LENGTH = 255;

    public static function besides(?string $note, string $categoryTitle): ?string
    {
        $note = self::clean($note);

        if ($note === null) {
            return null;
        }

        return self::normalize($note) === self::normalize($categoryTitle) ? null : $note;
    }

    public static function clean(?string $note): ?string
    {
        $note = trim(preg_replace('/\s+/u', ' ', (string) $note) ?? '');

        return $note === '' ? null : mb_substr($note, 0, self::MAX_LENGTH);
    }

    private static function normalize(string $text): string
    {
        return str_replace('ё', 'е', mb_strtolower(trim($text)));
    }
}
