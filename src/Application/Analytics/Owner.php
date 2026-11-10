<?php

declare(strict_types=1);

namespace App\Application\Analytics;

use App\Application\Participant\Participant;

/**
 * Whose money an expense is: shared by everyone or personal to one participant.
 */
final readonly class Owner
{
    public const string SHARED = 'shared';

    private function __construct(
        public string $key,
        public string $label,
        public ?int $participantId,
        public int $slot,
    ) {
    }

    public static function shared(): self
    {
        return new self(self::SHARED, 'Общие', null, 0);
    }

    public static function personal(Participant $participant, int $slot): self
    {
        return new self(self::keyFor($participant->id), $participant->name, $participant->id, $slot);
    }

    public static function keyFor(?int $participantId): string
    {
        return $participantId === null ? self::SHARED : 'p' . $participantId;
    }

    public function isShared(): bool
    {
        return $this->participantId === null;
    }
}
