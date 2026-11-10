<?php

declare(strict_types=1);

namespace App\Application\Rates;

use App\Infrastructure\Persistence\LedgerEntryRepository;

/**
 * Fetches historical rates for the dates of entries that still have none (e.g. after importing an old note),
 * then converts those entries.
 */
final readonly class PendingRateBackfill
{
    private const int MAX_DATES = 60;

    public function __construct(
        private LedgerEntryRepository $entries,
        private RateUpdater $updater,
        private PendingRateResolver $resolver,
    ) {
    }

    /**
     * @return int number of entries that got a rate
     */
    public function run(): int
    {
        foreach ($this->entries->pendingDates(self::MAX_DATES) as $date) {
            $this->updater->updateDays($date, 1);
        }

        return $this->resolver->resolve();
    }
}
