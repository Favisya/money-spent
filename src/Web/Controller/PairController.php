<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Application\Sharing\Pairing;
use App\Application\UserError;
use App\Web\Security\DeviceGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where another Mac lands before it is paired: enter the code shown on the host.
 */
final class PairController extends AbstractController
{
    private const int COOKIE_TTL = 63_072_000;

    public function __construct(
        private readonly Pairing $pairing,
    ) {
    }

    #[Route('/pair', name: 'web_pair', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (DeviceGuard::isLocal($request)) {
            return $this->redirectToRoute('web_sharing');
        }

        return $this->render('web/pair.html.twig', ['error' => null, 'name' => '']);
    }

    #[Route('/pair', name: 'web_pair_submit', methods: ['POST'])]
    public function pair(Request $request): Response
    {
        $name = mb_substr($request->request->getString('name'), 0, 64);

        try {
            $issued = $this->pairing->redeem(mb_substr($request->request->getString('code'), 0, 32), $name);
        } catch (UserError $exception) {
            return $this->render(
                'web/pair.html.twig',
                ['error' => $exception->getMessage(), 'name' => $name],
                new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY),
            );
        }

        $response = $this->redirectToRoute('web_dashboard');
        $response->headers->setCookie(Cookie::create(
            DeviceGuard::COOKIE,
            $issued->token,
            time() + self::COOKIE_TTL,
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        ));

        return $response;
    }
}
