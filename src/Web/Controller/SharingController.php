<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Application\Sharing\Pairing;
use App\Application\UserError;
use App\Infrastructure\Persistence\ParticipantRepository;
use App\Web\People\CurrentParticipant;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Host-only page (DeviceGuard keeps paired devices out): who I am on this Mac, the network address, pairing codes
 * and the list of connected devices.
 */
final class SharingController extends AbstractController
{
    private const int COOKIE_TTL = 31_536_000;

    /**
     * @param string $sharingUrls comma separated addresses the host app currently listens on, empty when sharing is off
     */
    public function __construct(
        private readonly Pairing $pairing,
        private readonly ParticipantRepository $participants,
        private readonly CurrentParticipant $me,
        #[Autowire('%env(default::SHARING_URLS)%')]
        private readonly ?string $sharingUrls,
    ) {
    }

    #[Route('/sharing', name: 'web_sharing', methods: ['GET'])]
    public function index(): Response
    {
        return $this->page([]);
    }

    #[Route('/sharing/code', name: 'web_sharing_code', methods: ['POST'])]
    public function code(Request $request): Response
    {
        try {
            $code = $this->pairing->createCode($request->request->getInt('participant'));
        } catch (UserError $exception) {
            return $this->page(['error' => $exception], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->page(['code' => $code, 'codeFor' => $request->request->getInt('participant')]);
    }

    #[Route(
        '/sharing/devices/{id}/revoke',
        name: 'web_sharing_revoke',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function revoke(int $id): Response
    {
        $this->pairing->revoke($id);

        return $this->redirectToRoute('web_sharing');
    }

    #[Route('/sharing/me', name: 'web_sharing_me', methods: ['POST'])]
    public function me(Request $request): Response
    {
        $id       = $request->request->getInt('participant');
        $response = $this->redirectToRoute('web_sharing');

        if ($this->participants->find($id) !== null) {
            $response->headers->setCookie(Cookie::create(
                CurrentParticipant::COOKIE,
                (string) $id,
                time() + self::COOKIE_TTL,
                sameSite: Cookie::SAMESITE_STRICT,
            ));
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function page(array $state, int $status = Response::HTTP_OK): Response
    {
        $urls = array_values(array_filter(array_map('trim', explode(',', (string) $this->sharingUrls))));

        return $this->render('web/sharing.html.twig', [
            'urls'         => array_values(array_filter($urls, self::isAddress(...))),
            'participants' => $this->participants->all(),
            'meId'         => $this->me->id(),
            'devices'      => $this->pairing->devices(),
            'server'       => PHP_BINARY,
            'code'         => null,
            'codeFor'      => null,
            'error'        => null,
            ...$state,
        ], new Response(status: $status));
    }

    private static function isAddress(string $url): bool
    {
        return preg_match('#^http://[\w.\-]+(:\d{1,5})?$#', $url) === 1;
    }
}
