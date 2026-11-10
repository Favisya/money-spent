<?php

declare(strict_types=1);

namespace App\Web\People;

use App\Application\Analytics\Owner;
use App\Application\Participant\ParticipantColor;
use App\Infrastructure\Persistence\ParticipantRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * One color per participant across the whole UI: their badge, their personal spending in charts, their flows.
 * Participants who never picked a color get one by position, so two people never start with the same color.
 */
final class PeoplePalette implements ResetInterface
{
    private const string SHARED = 'var(--owner-0)';

    /** @var array<int, ParticipantColor>|null */
    private ?array $colors = null;

    public function __construct(
        private readonly ParticipantRepository $participants,
    ) {
    }

    public function reset(): void
    {
        $this->colors = null;
    }

    public function colorOf(int $participantId): ParticipantColor
    {
        return $this->colors()[$participantId] ?? ParticipantColor::defaultFor($participantId);
    }

    public function cssVar(int $participantId): string
    {
        return $this->colorOf($participantId)->cssVar();
    }

    public function ownerCss(Owner $owner): string
    {
        return $owner->participantId === null ? self::SHARED : $this->cssVar($owner->participantId);
    }

    /**
     * @return array<int, ParticipantColor>
     */
    private function colors(): array
    {
        if ($this->colors !== null) {
            return $this->colors;
        }

        $this->colors = [];

        foreach ($this->participants->all() as $position => $participant) {
            $this->colors[$participant->id] = $participant->color ?? ParticipantColor::defaultFor($position);
        }

        return $this->colors;
    }
}
