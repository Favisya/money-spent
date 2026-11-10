<?php

declare(strict_types=1);

namespace App\Application\Report;

use App\Application\Participant\Participant;
use App\Domain\Money\Money;

final readonly class ParticipantTotal
{
    /**
     * @param Money $paid  what the participant actually paid
     * @param Money $share what the participant consumed according to the split
     */
    public function __construct(
        public Participant $participant,
        public Money $paid,
        public Money $share,
    ) {
    }
}
