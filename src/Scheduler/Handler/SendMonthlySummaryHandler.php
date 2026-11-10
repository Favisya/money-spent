<?php

declare(strict_types=1);

namespace App\Scheduler\Handler;

use App\Application\Balance\BalanceReport;
use App\Application\Balance\BalanceService;
use App\Application\Clock\LocalCalendar;
use App\Application\Report\MonthlyReport;
use App\Application\Report\MonthlyReportService;
use App\Application\Report\MonthPeriod;
use App\Bot\Locale\BotLanguage;
use App\Bot\Telegram\TelegramApiException;
use App\Bot\Telegram\TelegramClient;
use App\Bot\View\BalanceText;
use App\Bot\View\BotTranslator;
use App\Bot\View\MonthlyReportText;
use App\Infrastructure\Persistence\ParticipantRepository;
use App\Scheduler\Message\SendMonthlySummary;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Translation\LocaleSwitcher;

#[AsMessageHandler]
final readonly class SendMonthlySummaryHandler
{
    public function __construct(
        private MonthlyReportService $reports,
        private BalanceService $balances,
        private ParticipantRepository $participants,
        private MonthlyReportText $reportView,
        private BalanceText $balanceView,
        private TelegramClient $telegram,
        private LocalCalendar $calendar,
        private LoggerInterface $logger,
        private BotLanguage $languages,
        private LocaleSwitcher $locales,
        private BotTranslator $translator,
    ) {
    }

    public function __invoke(SendMonthlySummary $message): void
    {
        $period  = MonthPeriod::containing($this->calendar->today())->previous();
        $report  = $this->reports->build($period);
        $balance = $this->balances->current();

        foreach ($this->participants->all() as $participant) {
            if ($participant->telegramId === null) {
                continue;
            }

            $text = $this->locales->runWithLocale(
                $this->languages->forParticipant($participant),
                fn (): string => $this->render($report, $balance),
            );

            try {
                $this->telegram->sendMessage($participant->telegramId, $text);
            } catch (TelegramApiException $exception) {
                $this->logger->error('Monthly summary delivery failed', [
                    'participant_id' => $participant->id,
                    'error'          => $exception->getMessage(),
                ]);
            }
        }
    }

    private function render(MonthlyReport $report, BalanceReport $balance): string
    {
        return $this->reportView->render($report)
            . "\n\n<b>" . $this->translator->text('Баланс') . "</b>\n"
            . $this->balanceView->render($balance);
    }
}
