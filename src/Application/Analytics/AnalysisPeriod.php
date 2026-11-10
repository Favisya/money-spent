<?php

declare(strict_types=1);

namespace App\Application\Analytics;

use App\Application\Report\MonthPeriod;
use App\Application\UserError;
use DateTimeImmutable;

final readonly class AnalysisPeriod
{
    private const int MAX_MONTHS = 120;

    public function __construct(
        public MonthPeriod $from,
        public MonthPeriod $to,
    ) {
        if ($from->isAfter($to)) {
            throw new UserError('Начало периода позже конца.');
        }

        if ($this->monthCount() > self::MAX_MONTHS) {
            throw new UserError('Период длиннее %max% месяцев.', ['%max%' => self::MAX_MONTHS]);
        }
    }

    public static function lastMonths(MonthPeriod $to, int $count): self
    {
        $from = $to;

        for ($i = 1; $i < $count; ++$i) {
            $from = $from->previous();
        }

        return new self($from, $to);
    }

    public function start(): DateTimeImmutable
    {
        return $this->from->start();
    }

    public function endExclusive(): DateTimeImmutable
    {
        return $this->to->endExclusive();
    }

    public function monthCount(): int
    {
        return ($this->to->year - $this->from->year) * 12 + $this->to->month - $this->from->month + 1;
    }

    public function isSingleMonth(): bool
    {
        return $this->monthCount() === 1;
    }

    /**
     * @return list<MonthPeriod> oldest first
     */
    public function months(): array
    {
        $months = [];

        for ($month = $this->from; !$month->isAfter($this->to); $month = $month->next()) {
            $months[] = $month;
        }

        return $months;
    }

    /**
     * Calendar days that have already started, so an average over the current month is not diluted by the future.
     */
    public function elapsedDays(DateTimeImmutable $today): int
    {
        $end = min($this->endExclusive(), $today->setTime(0, 0)->modify('+1 day'));

        if ($end <= $this->start()) {
            return 1;
        }

        return max(1, (int) $this->start()->diff($end)->days);
    }
}
