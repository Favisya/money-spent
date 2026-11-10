<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Application\Clock\LocalCalendar;
use App\Application\Currency\CurrencyAdmin;
use App\Application\Currency\CurrencyStandard;
use App\Application\Rates\MarketRates;
use App\Application\Rates\RateSourceChoice;
use App\Application\Reference\CurrencyCatalog;
use App\Application\UserError;
use App\Infrastructure\Persistence\CurrencyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CurrencyController extends AbstractController
{
    private const int TEXT_LIMIT = 500;
    private const string CODE    = '[A-Z]{3}';
    private const array NOTICES  = [
        'added'  => 'Валюта добавлена: она есть в переключателе сверху и понимается в строке ввода.',
        'saved'  => 'Сохранено.',
        'hidden' => 'Валюта скрыта: старые записи в ней остались, новые в ней не записать.',
        'shown'  => 'Валюта снова в списке.',
    ];

    public function __construct(
        private readonly CurrencyAdmin $admin,
        private readonly CurrencyCatalog $catalog,
        private readonly CurrencyRepository $currencies,
        private readonly CurrencyStandard $standard,
        private readonly MarketRates $market,
        private readonly LocalCalendar $calendar,
    ) {
    }

    #[Route('/currencies', name: 'web_currencies', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->page($request, [
            'notice'    => self::NOTICES[$request->query->getString('ok')] ?? null,
            'highlight' => $request->query->getString('code'),
        ]);
    }

    #[Route('/currencies', name: 'web_currency_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $code    = mb_substr($request->request->getString('code'), 0, 3);
        $aliases = mb_substr($request->request->getString('aliases'), 0, self::TEXT_LIMIT);

        try {
            $this->admin->add($code, $aliases);
        } catch (UserError $error) {
            return $this->page($request, [
                'addError'   => $error,
                'addCode'    => $code,
                'addAliases' => $aliases,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->redirectToRoute('web_currencies', ['ok' => 'added', 'code' => strtoupper($code)]);
    }

    #[Route('/currencies/{code}', name: 'web_currency_save', requirements: ['code' => self::CODE], methods: ['POST'])]
    public function save(Request $request, string $code): Response
    {
        $aliases = mb_substr($request->request->getString('aliases'), 0, self::TEXT_LIMIT);

        try {
            $this->admin->changeAliases($code, $aliases);
        } catch (UserError $error) {
            return $this->page($request, [
                'editError'   => [$code => $error],
                'editAliases' => [$code => $aliases],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->redirectToRoute('web_currencies', ['ok' => 'saved', 'code' => $code]);
    }

    #[Route(
        '/currencies/{code}/visibility',
        name: 'web_currency_visibility',
        requirements: ['code' => self::CODE],
        methods: ['POST'],
    )]
    public function visibility(Request $request, string $code): Response
    {
        $active = $request->request->getBoolean('active');

        try {
            $this->admin->setActive($code, $active);
        } catch (UserError $error) {
            return $this->page($request, ['editError' => [$code => $error]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->redirectToRoute('web_currencies', ['ok' => $active ? 'shown' : 'hidden', 'code' => $code]);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function page(Request $request, array $state, int $status = Response::HTTP_OK): Response
    {
        $locale  = $request->getLocale();
        $today   = $this->calendar->today();
        $rates   = [];
        $names   = [];
        $offered = [];

        foreach ($this->catalog->all() as $definition) {
            $currency                 = $definition->currency;
            $names[$currency->code]   = $this->standard->name($currency->code, $locale);
            $rates[$currency->code]   = $this->market->rate(RateSourceChoice::Cba, $currency, $today)?->rate;
            $offered[$currency->code] = $definition->active;
        }

        $addable = array_filter(
            $this->standard->names($locale),
            static fn (string $code): bool => !($offered[$code] ?? false),
            ARRAY_FILTER_USE_KEY,
        );

        return $this->render('web/currencies.html.twig', [
            'currencies' => $this->catalog->all(),
            'base'       => $this->catalog->base(),
            'names'      => $names,
            'rates'      => $rates,
            'usage'      => $this->currencies->usage(),
            'addable'    => $addable,
            ...$state,
        ], new Response(status: $status));
    }
}
