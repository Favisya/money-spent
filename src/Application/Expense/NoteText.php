<?php

declare(strict_types=1);

namespace App\Application\Expense;

/**
 * The short "what for" comment of an expense ("свет", "газ"): trimmed, collapsed, without words that only repeat
 * the category title ("продукты ашан" in «Продукты» is "ашан").
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

        $title = self::normalize($categoryTitle);
        $words = array_filter(
            explode(' ', $note),
            static fn (string $word): bool => self::normalize($word) !== $title,
        );

        return self::normalize($note) === $title || $words === [] ? null : implode(' ', $words);
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
