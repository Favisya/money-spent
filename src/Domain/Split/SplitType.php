<?php

declare(strict_types=1);

namespace App\Domain\Split;

enum SplitType: string
{
    case Equal     = 'equal';
    case PayerOnly = 'payer_only';
    case OtherOnly = 'other_only';

    /**
     * Whose expense this is: the only participant who consumed it, or null when it is shared.
     *
     * @param list<int> $participantIds
     */
    public function ownerId(int $payerId, array $participantIds): ?int
    {
        $others = array_values(array_filter($participantIds, static fn (int $id): bool => $id !== $payerId));

        return match ($this) {
            self::Equal     => $others === [] ? $payerId : null,
            self::PayerOnly => $payerId,
            self::OtherOnly => count($others) === 1 ? $others[0] : null,
        };
    }

    /**
     * The split that keeps the expense with the same owner when somebody else pays for it.
     */
    public static function forOwner(?int $ownerId, int $payerId): self
    {
        return match ($ownerId) {
            null     => self::Equal,
            $payerId => self::PayerOnly,
            default  => self::OtherOnly,
        };
    }
}
