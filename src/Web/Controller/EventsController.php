<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Application\Sharing\EventFeed;
use App\Application\Sharing\LedgerEvent;
use App\Web\People\CurrentParticipant;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class EventsController
{
    private const string CURSOR = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private EventFeed $feed,
        private CurrentParticipant $me,
    ) {
    }

    #[Route('/api/events', name: 'api_events', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $since = DateTimeImmutable::createFromFormat(self::CURSOR, $request->query->getString('since'));
        $feed  = $this->feed->since($since === false ? null : $since, $this->me->id());

        $response = new JsonResponse([
            'cursor' => $feed['cursor']->format(self::CURSOR),
            'events' => array_map(
                static fn (LedgerEvent $event): array => [
                    'id'    => $event->id,
                    'title' => $event->title,
                    'body'  => $event->body,
                ],
                $feed['events'],
            ),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
