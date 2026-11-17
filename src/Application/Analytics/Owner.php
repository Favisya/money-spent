<?php

declare(strict_types=1);

namespace App\Application\Analytics;

use App\Application\Participant\Participant;

/**
 * Whose money an expense is: shared by everyone or personal to one participant.
 *
 * A "whole" owner is a view across both: everything one participant spends, their personal expenses plus their
 * share of the shared ones.
 */
final readonly class Owner
{
    public const string SHARED = 'shared';

    private const string PERSONAL_PREFIX = 'p';
    private const string WHOLE_PREFIX    = 'c';
    private const string KEY_PATTERN     = '/^(shared|[pc]\d+)$/';

    private function __construct(
        public string $key,
        public string $label,
        public ?int $participantId,
        public int $slot,
        public bool $whole = false,
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

    public static function whole(Participant $participant, int $slot): self
    {
        return new self(self::WHOLE_PREFIX . $participant->id, $participant->name, $participant->id, $slot, true);
    }

    public static function keyFor(?int $participantId): string
    {
        return $participantId === null ? self::SHARED : self::PERSONAL_PREFIX . $participantId;
    }

    /**
     * A key a filter link may carry: shared, one participant's own, or one participant's whole spending.
     */
    public static function isKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * Whether expenses of the given owner belong to this view: a participant's whole spending takes their personal
     * expenses and the shared ones (at their share).
     */
    public function covers(string $ownerKey): bool
    {
        return $this->whole
            ? $ownerKey === self::SHARED || $ownerKey === self::keyFor($this->participantId)
            : $ownerKey === $this->key;
    }

    public function isShared(): bool
    {
        return $this->participantId === null;
    }
}
