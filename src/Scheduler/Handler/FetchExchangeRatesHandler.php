<?php

declare(strict_types=1);

namespace App\Scheduler\Handler;

use App\Application\Clock\LocalCalendar;
use App\Application\Rates\MarketRates;
use App\Application\Rates\PendingRateBackfill;
use App\Application\Rates\RateUpdater;
use App\Scheduler\Message\FetchExchangeRates;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class FetchExchangeRatesHandler
{
    private const int LOOKBACK_DAYS = 3;

    public function __construct(
        private RateUpdater $updater,
        private PendingRateBackfill $pending,
        private MarketRates $market,
        private LocalCalendar $calendar,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FetchExchangeRates $message): void
    {
        $result = $this->updater->updateDays($this->calendar->today(), self::LOOKBACK_DAYS);

        $this->logger->info('Exchange rates updated', [
            'stored'   => array_sum($result->storedByDate),
            'errors'   => count($result->errorsByDate),
            'resolved' => $this->pending->run(),
            'market'   => $this->market->refresh($this->calendar->today()),
        ]);
    }
}
