<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Application\Participant\ParticipantAdmin;
use App\Application\UserError;
use App\Infrastructure\Persistence\ParticipantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ParticipantController extends AbstractController
{
    public function __construct(
        private readonly ParticipantAdmin $admin,
        private readonly ParticipantRepository $participants,
    ) {
    }

    #[Route('/participants', name: 'web_participants', methods: ['GET'])]
    public function list(Request $request): Response
    {
        return $this->render('web/participants.html.twig', [
            'participants' => $this->participants->all(),
            'saved'        => $request->query->getBoolean('saved'),
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
            $this->admin->save($id, $request->request->getString('name'), $request->request->getString('telegram_id'));
        } catch (UserError $exception) {
            return $this->render('web/participants.html.twig', [
                'participants' => $this->participants->all(),
                'error'        => $exception->getMessage(),
                'errorFor'     => $id ?? 'new',
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->redirectToRoute('web_participants', ['saved' => 1]);
    }
}
