<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Application\Analytics\AnalysisPeriod;
use App\Application\Analytics\AnalyticsService;
use App\Application\Clock\LocalCalendar;
use App\Application\Report\MonthPeriod;
use App\Application\UserError;
use App\Infrastructure\Persistence\ParticipantRepository;
use App\Web\Analytics\AnalyticsViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AnalyticsController extends AbstractController
{
    private const int DEFAULT_MONTHS  = 12;
    private const array PRESET_MONTHS = [1, 3, 6, 12];

    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly AnalyticsViewBuilder $views,
        private readonly LocalCalendar $calendar,
        private readonly ParticipantRepository $participants,
    ) {
    }

    #[Route('/analytics', name: 'web_analytics', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($this->participants->all() === []) {
            return $this->redirectToRoute('web_participants');
        }

        $error = null;

        try {
            $period = $this->period($request);
        } catch (UserError $exception) {
            $period = $this->lastMonths(self::DEFAULT_MONTHS);
            $error  = $exception;
        }

        $who = $request->query->getString('who');

        return $this->render('web/analytics.html.twig', [
            'view'   => $this->views->build($this->analytics->analyze($period, $who === '' ? null : $who)),
            'months' => $request->query->getInt('n'),
            'all'    => $request->query->getBoolean('all'),
            'error'  => $error,
        ]);
    }

    private function period(Request $request): AnalysisPeriod
    {
        if ($request->query->getBoolean('all')) {
            return $this->analytics->allTime();
        }

        $from = $request->query->getString('from');
        $to   = $request->query->getString('to');

        if ($from !== '' || $to !== '') {
            $today = $this->calendar->today();

            return new AnalysisPeriod(
                MonthPeriod::fromArgument($from === '' ? $to : $from, $today),
                MonthPeriod::fromArgument($to, $today),
            );
        }

        $months = $request->query->getInt('n', self::DEFAULT_MONTHS);

        if (!in_array($months, self::PRESET_MONTHS, true)) {
            throw new UserError('Такого периода нет.');
        }

        return $this->lastMonths($months);
    }

    private function lastMonths(int $count): AnalysisPeriod
    {
        return AnalysisPeriod::lastMonths(MonthPeriod::containing($this->calendar->today()), $count);
    }
}
