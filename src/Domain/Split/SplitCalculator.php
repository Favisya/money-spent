<?php

declare(strict_types=1);

namespace App\Domain\Split;

use InvalidArgumentException;

final class SplitCalculator
{
    /**
     * Splits an amount in base minor units between participants.
     * Indivisible remainder units go to the payer first, then to the others by ascending id.
     *
     * @param list<int> $participantIds
     *
     * @return array<int, int> participant id => share in base minor units (every participant is present)
     */
    public function shares(int $payerId, int $amountBaseMinor, SplitType $split, array $participantIds): array
    {
        $participantIds = $this->normalizeParticipants($participantIds);

        if (!in_array($payerId, $participantIds, true)) {
            throw new InvalidArgumentException(sprintf('Payer %d is not a participant.', $payerId));
        }

        $beneficiaries = $this->beneficiaries($payerId, $split, $participantIds);
        $shares        = array_fill_keys($participantIds, 0);
        $count         = count($beneficiaries);
        $baseShare     = intdiv($amountBaseMinor, $count);
        $remainder     = $amountBaseMinor - $baseShare * $count;
        $unit          = $remainder <=> 0;

        foreach ($beneficiaries as $index => $participantId) {
            $shares[$participantId] = $baseShare + ($index < abs($remainder) ? $unit : 0);
        }

        return $shares;
    }

    /**
     * @param list<int> $participantIds
     *
     * @return non-empty-list<int>
     */
    private function normalizeParticipants(array $participantIds): array
    {
        $unique = array_values(array_unique($participantIds));
        sort($unique);

        if ($unique === []) {
            throw new InvalidArgumentException('At least one participant is required.');
        }

        if (count($unique) !== count($participantIds)) {
            throw new InvalidArgumentException('Participant ids must be unique.');
        }

        return $unique;
    }

    /**
     * @param non-empty-list<int> $participantIds
     *
     * @return non-empty-list<int> payer first when included, others by ascending id
     */
    private function beneficiaries(int $payerId, SplitType $split, array $participantIds): array
    {
        $others = array_values(array_filter(
            $participantIds,
            static fn (int $id): bool => $id !== $payerId,
        ));

        $beneficiaries = match ($split) {
            SplitType::Equal     => [$payerId, ...$others],
            SplitType::PayerOnly => [$payerId],
            SplitType::OtherOnly => $others,
        };

        if ($beneficiaries === []) {
            throw new InvalidArgumentException('Split "other_only" requires at least two participants.');
        }

        return $beneficiaries;
    }
}
