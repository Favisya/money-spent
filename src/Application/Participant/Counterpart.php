<?php

declare(strict_types=1);

namespace App\Application\Participant;

use App\Application\UserError;
use App\Infrastructure\Persistence\ParticipantRepository;

final readonly class Counterpart
{
    public function __construct(
        private ParticipantRepository $participants,
    ) {
    }

    /**
     * The only other participant. The ledger is designed for two people.
     */
    public function of(Participant $participant): Participant
    {
        $others = array_values(array_filter(
            $this->participants->all(),
            static fn (Participant $other): bool => $other->id !== $participant->id,
        ));

        if ($others === []) {
            throw new UserError('Второй участник ещё не зарегистрирован: пусть напишет боту /start.');
        }

        if (count($others) > 1) {
            throw new UserError('Перевод поддерживается только для двух участников.');
        }

        return $others[0];
    }
}
