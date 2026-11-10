<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Application\Participant\Participant;

/**
 * Finds a participant mentioned in a note line ("от алисы" -> Алиса) by the stem of their name.
 */
final class PayerGuesser
{
    private const int MIN_STEM = 3;

    /**
     * @param list<Participant> $participants
     */
    public function guess(string $line, array $participants): ?Participant
    {
        $words   = preg_split('/[^\p{L}]+/u', mb_strtolower($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $matches = [];

        foreach ($participants as $participant) {
            $stem = self::stem($participant->name);

            if ($stem === null) {
                continue;
            }

            foreach ($words as $word) {
                if (str_starts_with(str_replace('ё', 'е', $word), $stem)) {
                    $matches[$participant->id] = $participant;

                    break;
                }
            }
        }

        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    /**
     * The participant a single word refers to ("алисе", "Дима"), if any.
     *
     * @param list<Participant> $participants
     */
    public function participantIn(string $word, array $participants): ?Participant
    {
        $word = str_replace('ё', 'е', mb_strtolower($word));

        foreach ($participants as $participant) {
            $stem = self::stem($participant->name);

            if ($stem !== null && str_starts_with($word, $stem)) {
                return $participant;
            }
        }

        return null;
    }

    /**
     * Drops the payer mention ("от алисы", "алиса") from a note so it does not pollute note statistics.
     */
    public function stripMention(?string $note, Participant $payer): ?string
    {
        $stem = self::stem($payer->name);

        if ($note === null || $stem === null) {
            return $note;
        }

        $pattern  = sprintf('/(?:^|\s)(?:от\s+)?%s\p{L}*(?=\s|$)/iu', preg_quote($stem, '/'));
        $original = str_replace('ё', 'е', $note);
        $stripped = preg_replace($pattern, ' ', $original) ?? $original;
        $cleaned  = trim(preg_replace('/\s+/u', ' ', $stripped) ?? $stripped);

        if ($cleaned === trim($original)) {
            return $note;
        }

        return $cleaned === '' ? null : $cleaned;
    }

    private static function stem(string $name): ?string
    {
        $first = preg_split('/\s+/u', str_replace('ё', 'е', mb_strtolower(trim($name))))[0] ?? '';
        $stem  = mb_strlen($first) > self::MIN_STEM ? mb_substr($first, 0, -1) : $first;

        return mb_strlen($stem) >= self::MIN_STEM ? $stem : null;
    }
}
