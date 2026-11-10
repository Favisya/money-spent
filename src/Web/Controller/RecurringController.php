<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Application\Clock\LocalCalendar;
use App\Application\Parsing\ParseErrorMessages;
use App\Application\Rates\PendingRateBackfill;
use App\Application\Recurring\RecurringExpenses;
use App\Application\UserError;
use App\Domain\Parsing\ExpenseParseException;
use App\Domain\Split\SplitType;
use App\Infrastructure\Persistence\ParticipantRepository;
use App\Infrastructure\Persistence\RecurringExpenseRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RecurringController extends AbstractController
{
    private const int TEXT_LIMIT = 500;
    private const array NOTICES  = [
        'added'   => 'Готово: трата будет записываться сама каждый месяц.',
        'paused'  => 'Поставлено на паузу.',
        'resumed' => 'Снова записывается каждый месяц.',
        'deleted' => 'Удалено. Уже записанные траты остались.',
        'refused' => 'Не получилось: трата удалена или уже повторяется каждый месяц.',
    ];

    public function __construct(
        private readonly RecurringExpenses $service,
        private readonly RecurringExpenseRepository $recurring,
        private readonly ParticipantRepository $participants,
        private readonly ParseErrorMessages $parseErrors,
        private readonly LocalCalendar $calendar,
        private readonly PendingRateBackfill $rates,
    ) {
    }

    #[Route('/recurring', name: 'web_recurring', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->page($request, [
            'notice'    => self::NOTICES[$request->query->getString('ok')] ?? null,
            'highlight' => $request->query->getInt('id'),
        ]);
    }

    #[Route('/recurring', name: 'web_recurring_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $text = mb_substr($request->request->getString('text'), 0, self::TEXT_LIMIT);

        try {
            $recurring = $this->service->create(
                $request->request->getInt('payer'),
                $text,
                SplitType::tryFrom($request->request->getString('whose')) ?? SplitType::Equal,
                $request->request->getInt('day'),
            );
        } catch (ExpenseParseException $exception) {
            return $this->failed($request, $text, $this->parseErrors->message($exception));
        } catch (UserError $exception) {
            return $this->failed($request, $text, $exception->getMessage());
        }

        return $this->booked($recurring->id, 'added');
    }

    #[Route(
        '/expenses/{id}/recurring',
        name: 'web_expense_recurring',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function fromExpense(int $id): Response
    {
        try {
            $recurring = $this->service->fromExpense($id);
        } catch (UserError) {
            return $this->redirectToRoute('web_recurring', ['ok' => 'refused']);
        }

        return $this->booked($recurring->id, 'added');
    }

    #[Route(
        '/recurring/{id}/active',
        name: 'web_recurring_active',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function active(Request $request, int $id): Response
    {
        $active = $request->request->getBoolean('active');

        try {
            $this->service->setActive($id, $active);
        } catch (UserError) {
            return $this->redirectToRoute('web_recurring');
        }

        return $this->booked($id, $active ? 'resumed' : 'paused');
    }

    #[Route(
        '/recurring/{id}/delete',
        name: 'web_recurring_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function delete(int $id): Response
    {
        try {
            $this->service->delete($id);
        } catch (UserError) {
            return $this->redirectToRoute('web_recurring');
        }

        return $this->redirectToRoute('web_recurring', ['ok' => 'deleted']);
    }

    /**
     * Books anything already due right away, so a subscription added today shows up immediately.
     */
    private function booked(int $id, string $notice): Response
    {
        if ($this->service->run() > 0) {
            $this->rates->run();
        }

        return $this->redirectToRoute('web_recurring', ['ok' => $notice, 'id' => $id]);
    }

    private function failed(Request $request, string $text, string $error): Response
    {
        return $this->page($request, [
            'error'   => $error,
            'text'    => $text,
            'payerId' => $request->request->getInt('payer'),
            'whose'   => $request->request->getString('whose'),
            'day'     => $request->request->getInt('day'),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function page(Request $request, array $state, int $status = Response::HTTP_OK): Response
    {
        return $this->render('web/recurring.html.twig', [
            'items'        => $this->recurring->findAll(),
            'participants' => $this->participants->all(),
            'payerId'      => $request->cookies->getInt('payer'),
            'day'          => (int) $this->calendar->today()->format('j'),
            'whose'        => 'equal',
            'text'         => '',
            'error'        => null,
            ...$state,
        ], new Response(status: $status));
    }
}
