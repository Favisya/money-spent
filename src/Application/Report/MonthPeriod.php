<?php

declare(strict_types=1);

namespace App\Application\Report;

use App\Application\UserError;
use DateTimeImmutable;

final readonly class MonthPeriod
{
    private const string FORMAT_HINT = 'Укажи месяц числом: /month 10 или /month 10.2025';

    private function __construct(
        public int $year,
        public int $month,
    ) {
    }

    public static function of(int $year, int $month): self
    {
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            throw new UserError(self::FORMAT_HINT);
        }

        return new self($year, $month);
    }

    public static function containing(DateTimeImmutable $date): self
    {
        return new self((int) $date->format('Y'), (int) $date->format('n'));
    }

    /**
     * Accepts "", "10", "10.2025" and "2025-10". A month without a year that is still ahead means last year.
     */
    public static function fromArgument(string $argument, DateTimeImmutable $today): self
    {
        $argument = trim($argument);
        $current  = self::containing($today);

        if ($argument === '') {
            return $current;
        }

        if (preg_match('/^(\d{1,2})$/', $argument, $match) === 1) {
            $period = self::of($current->year, (int) $match[1]);

            return $period->isAfter($current) ? self::of($current->year - 1, $period->month) : $period;
        }

        if (preg_match('/^(\d{1,2})\.(\d{4})$/', $argument, $match) === 1) {
            return self::of((int) $match[2], (int) $match[1]);
        }

        if (preg_match('/^(\d{4})-(\d{1,2})$/', $argument, $match) === 1) {
            return self::of((int) $match[1], (int) $match[2]);
        }

        throw new UserError(self::FORMAT_HINT);
    }

    public function start(): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('%04d-%02d-01', $this->year, $this->month));
    }

    public function endExclusive(): DateTimeImmutable
    {
        return $this->start()->modify('first day of next month');
    }

    public function previous(): self
    {
        return self::containing($this->start()->modify('-1 month'));
    }

    public function next(): self
    {
        return self::containing($this->endExclusive());
    }

    public function isAfter(self $other): bool
    {
        return $this->year * 12 + $this->month > $other->year * 12 + $other->month;
    }

    public function key(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }
}
