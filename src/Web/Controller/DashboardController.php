<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Application\Clock\LocalCalendar;
use App\Application\Expense\ExpenseEditor;
use App\Application\Expense\ExpenseRecorder;
use App\Application\Parsing\ParseErrorMessages;
use App\Application\Parsing\ParserFactory;
use App\Application\Report\MonthPeriod;
use App\Application\Settlement\SettlementRecorder;
use App\Application\Transfer\TransferParser;
use App\Application\Transfer\TransferRecorder;
use App\Application\UserError;
use App\Domain\Parsing\ExpenseParseException;
use App\Domain\Parsing\ParsedExpense;
use App\Domain\Split\SplitType;
use App\Infrastructure\Persistence\LedgerEntryRepository;
use App\Infrastructure\Persistence\LedgerTable;
use App\Infrastructure\Persistence\ParticipantRepository;
use App\Web\Dashboard\DashboardBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class DashboardController extends AbstractController
{
    private const string PAYER_COOKIE = 'payer';
    private const int COOKIE_TTL      = 31_536_000;
    private const string OWNER_KEY    = '/^(shared|p\d+)$/';
    private const array NOTICES       = [
        'added'    => 'Трата записана.',
        'deleted'  => 'Удалено.',
        'category' => 'Категория изменена.',
        'note'     => 'Заметка сохранена.',
        'split'    => 'Отмечено, чья трата.',
        'date'     => 'Дата изменена.',
        'amount'   => 'Сумма изменена, пересчёт по курсу на дату траты.',
        'payer'    => 'Изменено, кто платил.',
        'settled'  => 'Перевод записан.',
        'sent'     => 'Перевод другому человеку записан как личная трата отправителя, в категорию «Переводы».',
    ];

    public function __construct(
        private readonly DashboardBuilder $dashboards,
        private readonly LocalCalendar $calendar,
        private readonly ParserFactory $parsers,
        private readonly ParseErrorMessages $parseErrors,
        private readonly ExpenseRecorder $recorder,
        private readonly ExpenseEditor $editor,
        private readonly SettlementRecorder $settlements,
        private readonly LedgerEntryRepository $entries,
        private readonly ParticipantRepository $participants,
        private readonly TransferParser $transfers,
        private readonly TransferRecorder $transferRecorder,
    ) {
    }

    #[Route('/', name: 'web_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($this->participants->all() === []) {
            return $this->redirectToRoute('web_participants');
        }

        $current = MonthPeriod::containing($this->calendar->today());
        $error   = null;

        try {
            $period = MonthPeriod::fromArgument($request->query->getString('m'), $this->calendar->today());
        } catch (UserError $exception) {
            $period = $current;
            $error  = $exception->getMessage();
        }

        $who = $this->ownerKey($request->query->getString('who'));

        return $this->page($request, $period, [
            'notice'       => self::NOTICES[$request->query->getString('ok')] ?? null,
            'highlight'    => $request->query->getInt('id'),
            'expenseError' => $error,
        ], who: $who);
    }

    #[Route('/expenses', name: 'web_expense_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $text    = mb_substr($request->request->getString('text'), 0, 1000);
        $payerId = $request->request->getInt('payer');
        $payer   = $this->participants->find($payerId);

        if ($request->request->getString('kind') === 'transfer' || $this->transfers->isTransfer($text)) {
            return $this->addTransfer($request, $text, $payerId);
        }

        try {
            if ($payer === null) {
                throw new UserError('Выбери, кто платил.');
            }

            $parsed = $this->withWhose(
                $this->parsers->create()->parse($text, $this->calendar->today()),
                $request->request->getString('whose'),
            );
            $recorded = $this->recorder->record($payer, $parsed, Uuid::v4()->toRfc4122());
        } catch (ExpenseParseException $exception) {
            return $this->failedAdd($request, $text, $payerId, $this->parseErrors->message($exception));
        } catch (UserError $exception) {
            return $this->failedAdd($request, $text, $payerId, $exception->getMessage());
        }

        $response = $this->back($request, 'added', $recorded->expense->id, MonthPeriod::containing($parsed->spentAt));
        $response->headers->setCookie(
            Cookie::create(
                self::PAYER_COOKIE,
                (string) $payerId,
                time() + self::COOKIE_TTL,
                sameSite: Cookie::SAMESITE_STRICT,
            ),
        );

        return $response;
    }

    private function addTransfer(Request $request, string $text, int $senderId): Response
    {
        $to = $request->request->getString('to');

        try {
            $sender   = $this->participants->find($senderId) ?? throw new UserError('Выбери, кто перевёл.');
            $transfer = $this->transfers->parse(
                $text,
                $this->calendar->today(),
                $sender,
                $this->participants->all(),
                ctype_digit($to) ? $this->participants->find((int) $to) : null,
                $to === 'other' ? $request->request->getString('to_name') : null,
            );
            $this->transferRecorder->record($transfer, Uuid::v4()->toRfc4122());
        } catch (ExpenseParseException $exception) {
            return $this->failedAdd($request, $text, $senderId, $this->parseErrors->message($exception));
        } catch (UserError $exception) {
            return $this->failedAdd($request, $text, $senderId, $exception->getMessage());
        }

        $notice = $transfer->isBetweenParticipants() ? 'settled' : 'sent';

        return $this->back($request, $notice, null, MonthPeriod::containing($transfer->date));
    }

    #[Route('/expenses/{id}/note', name: 'web_expense_note', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function changeNote(Request $request, int $id): Response
    {
        try {
            $this->editor->changeNote($id, mb_substr($request->request->getString('note'), 0, 1000));
        } catch (UserError) {
            return $this->back($request, null);
        }

        return $this->back($request, 'note', $id);
    }

    #[Route('/expenses/{id}/category', name: 'web_expense_category', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function changeCategory(Request $request, int $id): Response
    {
        try {
            $this->editor->changeCategory($id, $request->request->getInt('category'));
        } catch (UserError) {
            return $this->back($request, null);
        }

        return $this->back($request, 'category', $id);
    }

    #[Route('/expenses/{id}/split', name: 'web_expense_split', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function changeSplit(Request $request, int $id): Response
    {
        $split = SplitType::tryFrom($request->request->getString('split'));

        try {
            if ($split === null) {
                throw new UserError('Не понял, чья это трата.');
            }

            $this->editor->changeSplit($id, $split);
        } catch (UserError) {
            return $this->back($request, null);
        }

        return $this->back($request, 'split', $id);
    }

    #[Route('/expenses/{id}/date', name: 'web_expense_date', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function changeDate(Request $request, int $id): Response
    {
        try {
            $expense = $this->editor->changeDate($id, mb_substr($request->request->getString('date'), 0, 20));
        } catch (UserError $error) {
            return $this->failedEdit($request, $error);
        }

        return $this->back($request, 'date', $id, MonthPeriod::containing($expense->spentAt));
    }

    #[Route('/expenses/{id}/amount', name: 'web_expense_amount', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function changeAmount(Request $request, int $id): Response
    {
        try {
            $this->editor->changeAmount(
                $id,
                mb_substr($request->request->getString('amount'), 0, 40),
                mb_substr($request->request->getString('currency'), 0, 3),
            );
        } catch (UserError $error) {
            return $this->failedEdit($request, $error);
        }

        return $this->back($request, 'amount', $id);
    }

    #[Route('/expenses/{id}/payer', name: 'web_expense_payer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function changePayer(Request $request, int $id): Response
    {
        try {
            $this->editor->changePayer($id, $request->request->getInt('payer'));
        } catch (UserError $error) {
            return $this->failedEdit($request, $error);
        }

        return $this->back($request, 'payer', $id);
    }

    #[Route('/expenses/{id}/delete', name: 'web_expense_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        try {
            $this->editor->delete($id);
        } catch (UserError) {
            return $this->back($request, null);
        }

        return $this->back($request, 'deleted');
    }

    #[Route('/settlements', name: 'web_settlement_add', methods: ['POST'])]
    public function settle(Request $request): Response
    {
        $text = mb_substr($request->request->getString('amount'), 0, 200);
        $from = $this->participants->find($request->request->getInt('from'));

        try {
            if ($from === null) {
                throw new UserError('Выбери, кто перевёл.');
            }

            $parsed = $this->parsers->create()->parse($text, $this->calendar->today());
            $this->settlements->record(
                $from,
                $parsed->amount,
                $parsed->spentAt,
                $parsed->note,
                Uuid::v4()->toRfc4122(),
            );
        } catch (ExpenseParseException $exception) {
            return $this->failedSettle($request, $text, $this->parseErrors->message($exception));
        } catch (UserError $exception) {
            return $this->failedSettle($request, $text, $exception->getMessage());
        }

        return $this->back($request, 'settled');
    }

    #[Route(
        '/settlements/{id}/delete',
        name: 'web_settlement_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function deleteSettlement(Request $request, int $id): Response
    {
        $this->entries->softDelete(LedgerTable::Settlement, $id);

        return $this->back($request, 'deleted');
    }

    /**
     * @param array<string, mixed> $state
     */
    private function page(
        Request $request,
        MonthPeriod $period,
        array $state,
        int $status = 200,
        ?string $who = null,
    ): Response {
        $current = MonthPeriod::containing($this->calendar->today());

        return $this->render('web/dashboard.html.twig', [
            'dashboard' => $this->dashboards->build($period, $current, $who),
            'whose'     => $request->request->getString('whose', SplitType::Equal->value),
            'payerId'   => $request->cookies->getInt(self::PAYER_COOKIE),
            ...$state,
        ], new Response(status: $status));
    }

    private function failedAdd(Request $request, string $text, int $payerId, string $error): Response
    {
        return $this->page($request, $this->returnPeriod($request), [
            'expenseError' => $error,
            'expenseText'  => $text,
            'payerId'      => $payerId,
            'kind'         => $request->request->getString('kind', 'expense'),
            'transferTo'   => $request->request->getString('to'),
            'transferName' => mb_substr($request->request->getString('to_name'), 0, 64),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function failedEdit(Request $request, UserError $error): Response
    {
        return $this->page($request, $this->returnPeriod($request), [
            'editError' => $error->getMessage(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function failedSettle(Request $request, string $text, string $error): Response
    {
        return $this->page($request, $this->returnPeriod($request), [
            'settleError'  => $error,
            'settleAmount' => $text,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function back(
        Request $request,
        ?string $notice,
        ?int $id = null,
        ?MonthPeriod $period = null,
    ): RedirectResponse {
        $query = array_filter([
            'm'   => ($period ?? $this->returnPeriod($request))->key(),
            'who' => $this->ownerKey($request->request->getString('who')),
            'ok'  => $notice,
            'id'  => $id,
        ]);

        return $this->redirectToRoute('web_dashboard', $query);
    }

    private function ownerKey(string $value): ?string
    {
        return preg_match(self::OWNER_KEY, $value) === 1 ? $value : null;
    }

    private function withWhose(ParsedExpense $parsed, string $whose): ParsedExpense
    {
        $split = SplitType::tryFrom($whose) ?? SplitType::Equal;

        return $split === SplitType::Equal ? $parsed : $parsed->withSplit($split);
    }

    private function returnPeriod(Request $request): MonthPeriod
    {
        $back = $request->request->getString('back');

        if (preg_match('/^(\d{4})-(\d{2})$/', $back, $match) === 1) {
            try {
                return MonthPeriod::of((int) $match[1], (int) $match[2]);
            } catch (UserError) {
                return MonthPeriod::containing($this->calendar->today());
            }
        }

        return MonthPeriod::containing($this->calendar->today());
    }
}
