<?php

declare(strict_types=1);

namespace App\Web\People;

use App\Application\Sharing\Device;
use App\Infrastructure\Persistence\ParticipantRepository;
use App\Web\Security\DeviceGuard;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Who is looking: the participant a paired device belongs to, or on the host Mac the one picked as "это я".
 */
final readonly class CurrentParticipant
{
    public const string COOKIE = 'me';

    public function __construct(
        private RequestStack $requests,
        private ParticipantRepository $participants,
    ) {
    }

    public function id(): ?int
    {
        $request = $this->requests->getMainRequest();
        $device  = $request?->attributes->get(DeviceGuard::ATTRIBUTE);

        if ($device instanceof Device) {
            return $device->participantId;
        }

        $chosen = $request?->cookies->getInt(self::COOKIE) ?? 0;
        $all    = $this->participants->all();

        foreach ($all as $participant) {
            if ($participant->id === $chosen) {
                return $chosen;
            }
        }

        return $all[0]->id ?? null;
    }

    public function isRemote(): bool
    {
        return $this->requests->getMainRequest()?->attributes->get(DeviceGuard::ATTRIBUTE) instanceof Device;
    }
}
