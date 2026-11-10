<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Application\Rates\RateSourceChoice;
use App\Web\Currency\DisplayCurrency;
use App\Web\Http\BackPath;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DisplayCurrencyController extends AbstractController
{
    private const int COOKIE_TTL = 31_536_000;

    public function __construct(
        private readonly DisplayCurrency $display,
    ) {
    }

    #[Route('/display-currency', name: 'web_display_currency', methods: ['POST'])]
    public function choose(Request $request): Response
    {
        $code = $request->request->getString('code');
        $back = $request->request->getString('back');

        $response = new RedirectResponse(BackPath::orRoot($back));

        $source = RateSourceChoice::tryFrom($request->request->getString('source'));

        if ($source !== null) {
            $response->headers->setCookie(Cookie::create(
                DisplayCurrency::SOURCE_COOKIE,
                $source->value,
                time() + self::COOKIE_TTL,
                sameSite: Cookie::SAMESITE_STRICT,
            ));
        }

        if ($this->display->isChoice($code)) {
            $response->headers->setCookie(Cookie::create(
                DisplayCurrency::COOKIE,
                $code,
                time() + self::COOKIE_TTL,
                sameSite: Cookie::SAMESITE_STRICT,
            ));
        }

        return $response;
    }
}
