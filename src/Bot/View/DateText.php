<?php

declare(strict_types=1);

namespace App\Bot\View;

use DateTimeInterface;
use IntlDateFormatter;

/**
 * Dates in the current language, with ICU patterns.
 */
final readonly class DateText
{
    /** The day pattern is a translatable text, so each language keeps its own order and month names. */
    private const string DAY_PATTERN = 'dd.MM.y';

    public function __construct(
        private BotTranslator $translator,
    ) {
    }

    public function day(DateTimeInterface $date): string
    {
        return $this->format($date, $this->translator->text(self::DAY_PATTERN));
    }

    public function format(DateTimeInterface $date, string $pattern): string
    {
        $formatter = new IntlDateFormatter(
            $this->translator->locale(),
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $date->getTimezone(),
            IntlDateFormatter::GREGORIAN,
            $pattern,
        );

        $formatted = $formatter->format($date);

        return $formatted === false ? $date->format('d.m.Y') : $formatted;
    }
}
