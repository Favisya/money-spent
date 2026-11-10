<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Application\Clock\LocalCalendar;
use App\Scheduler\Message\FetchExchangeRates;
use App\Scheduler\Message\RunRecurringExpenses;
use App\Scheduler\Message\SendMonthlySummary;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule('default')]
final readonly class MainSchedule implements ScheduleProviderInterface
{
    private const string RATES_CRON     = '30 13 * * *';
    private const string SUMMARY_CRON   = '0 10 1 * *';
    private const string RECURRING_CRON = '5 * * * *';

    public function __construct(
        private CacheInterface $cache,
        private LocalCalendar $calendar,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(
                RecurringMessage::cron(self::RATES_CRON, new FetchExchangeRates(), $this->calendar->zone()),
                RecurringMessage::cron(self::SUMMARY_CRON, new SendMonthlySummary(), $this->calendar->zone()),
                RecurringMessage::cron(self::RECURRING_CRON, new RunRecurringExpenses(), $this->calendar->zone()),
            )
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
