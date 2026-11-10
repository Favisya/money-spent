<?php

declare(strict_types=1);

namespace App\Application\Rates;

use App\Infrastructure\Persistence\LedgerEntryRepository;
use App\Infrastructure\Persistence\LedgerTable;

/**
 * Fills in rates for entries saved while no rate was known yet.
 */
final readonly class PendingRateResolver
{
    public function __construct(
        private LedgerEntryRepository $entries,
        private RateResolver $rates,
    ) {
    }

    /**
     * @return int number of resolved entries
     */
    public function resolve(): int
    {
        $resolved = 0;

        foreach (LedgerTable::cases() as $table) {
            foreach ($this->entries->findPendingRates($table) as $entry) {
                $rate = $this->rates->find($entry->amount->currency, $entry->date);

                if ($rate === null) {
                    continue;
                }

                $converted = $rate->convert($entry->amount);
                $resolved += $this->entries->applyRate($table, $entry->id, $rate->rate, $converted->minor) ? 1 : 0;
            }
        }

        return $resolved;
    }
}
