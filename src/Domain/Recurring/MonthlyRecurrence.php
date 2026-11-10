<?php

declare(strict_types=1);

namespace App\Domain\Recurring;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * "Every month on day N": day 31 means the last day of shorter months.
 */
final readonly class MonthlyRecurrence
{
    public function __construct(
        public int $dayOfMonth,
    ) {
        if ($dayOfMonth < 1 || $dayOfMonth > 31) {
            throw new InvalidArgumentException(sprintf('Day of month must be 1..31, got %d.', $dayOfMonth));
        }
    }

    public function dateIn(DateTimeImmutable $month): DateTimeImmutable
    {
        $first = $month->setTime(0, 0)->modify('first day of this month');

        return $first->setDate(
            (int) $first->format('Y'),
            (int) $first->format('n'),
            min($this->dayOfMonth, (int) $first->format('t')),
        );
    }

    /**
     * Occurrences from $nextMonth on that are already due by $today, oldest first, at most $limit.
     *
     * @return list<DateTimeImmutable>
     */
    public function dueDates(DateTimeImmutable $nextMonth, DateTimeImmutable $today, int $limit): array
    {
        $today = $today->setTime(0, 0);
        $month = new DateTimeImmutable($nextMonth->format('Y-m-01'), $today->getTimezone());
        $dates = [];

        while (count($dates) < $limit) {
            $date = $this->dateIn($month);

            if ($date > $today) {
                break;
            }

            $dates[] = $date;
            $month   = $month->modify('first day of next month');
        }

        return $dates;
    }
}
