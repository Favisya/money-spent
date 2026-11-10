<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Web\Http\BackPath;
use App\Web\Locale\LanguageListener;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LanguageController extends AbstractController
{
    private const int COOKIE_TTL = 31_536_000;

    public function __construct(
        private readonly LanguageListener $languages,
    ) {
    }

    #[Route('/language', name: 'web_language', methods: ['POST'])]
    public function choose(Request $request): Response
    {
        $language = $request->request->getString('lang');
        $back     = $request->request->getString('back');
        $response = new RedirectResponse(BackPath::orRoot($back));

        if ($this->languages->isSupported($language)) {
            $response->headers->setCookie(Cookie::create(
                LanguageListener::COOKIE,
                $language,
                time() + self::COOKIE_TTL,
                sameSite: Cookie::SAMESITE_STRICT,
            ));
        }

        return $response;
    }
}
