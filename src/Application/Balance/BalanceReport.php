<?php

declare(strict_types=1);

namespace App\Application\Balance;

use App\Application\Participant\Participant;
use App\Domain\Balance\Balance;
use App\Domain\Money\Currency;

final readonly class BalanceReport
{
    /**
     * @param array<int, Participant> $participants by id
     */
    public function __construct(
        public Balance $balance,
        public array $participants,
        public Currency $base,
        public int $pendingCount,
    ) {
    }
}
