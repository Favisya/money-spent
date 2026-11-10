<?php

declare(strict_types=1);

namespace App\Application\Settlement;

use App\Application\Participant\Participant;

final readonly class RecordedSettlement
{
    public function __construct(
        public SettlementDraft $draft,
        public Participant $to,
    ) {
    }
}
