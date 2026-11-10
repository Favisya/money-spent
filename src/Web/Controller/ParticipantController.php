<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Application\Participant\Participant;
use App\Application\Participant\ParticipantAdmin;
use App\Application\Participant\ParticipantColor;
use App\Application\UserError;
use App\Infrastructure\Persistence\ParticipantRepository;
use App\Web\People\PeoplePalette;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ParticipantController extends AbstractController
{
    public function __construct(
        private readonly ParticipantAdmin $admin,
        private readonly ParticipantRepository $participants,
        private readonly PeoplePalette $palette,
    ) {
    }

    #[Route('/participants', name: 'web_participants', methods: ['GET'])]
    public function list(Request $request): Response
    {
        return $this->render('web/participants.html.twig', [
            ...$this->colorState(),
            'saved' => $request->query->getBoolean('saved'),
        ]);
    }

    #[Route('/participants', name: 'web_participant_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        return $this->save($request, null);
    }

    #[Route('/participants/{id}', name: 'web_participant_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        return $this->save($request, $id);
    }

    private function save(Request $request, ?int $id): Response
    {
        try {
            $this->admin->save(
                $id,
                $request->request->getString('name'),
                $request->request->getString('telegram_id'),
                $request->request->getString('color'),
            );
        } catch (UserError $exception) {
            return $this->render('web/participants.html.twig', [
                ...$this->colorState(),
                'error'    => $exception,
                'errorFor' => $id ?? 'new',
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->redirectToRoute('web_participants', ['saved' => 1]);
    }

    /**
     * @return array{
     *     participants: list<Participant>,
     *     colors: list<ParticipantColor>,
     *     colorOf: array<int, string>,
     *     newColor: string,
     * }
     */
    private function colorState(): array
    {
        $participants = $this->participants->all();
        $colorOf      = [];

        foreach ($participants as $participant) {
            $colorOf[$participant->id] = $this->palette->colorOf($participant->id)->value;
        }

        return [
            'participants' => $participants,
            'colors'       => ParticipantColor::cases(),
            'colorOf'      => $colorOf,
            'newColor'     => ParticipantColor::defaultFor(count($participants))->value,
        ];
    }
}
