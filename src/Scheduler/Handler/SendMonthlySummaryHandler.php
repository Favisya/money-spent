<?php

declare(strict_types=1);

namespace App\Scheduler\Handler;

use App\Application\Balance\BalanceService;
use App\Application\Clock\LocalCalendar;
use App\Application\Report\MonthlyReportService;
use App\Application\Report\MonthPeriod;
use App\Bot\Telegram\TelegramApiException;
use App\Bot\Telegram\TelegramClient;
use App\Bot\View\BalanceText;
use App\Bot\View\MonthlyReportText;
use App\Infrastructure\Persistence\ParticipantRepository;
use App\Scheduler\Message\SendMonthlySummary;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

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
    ) {
    }

    public function __invoke(SendMonthlySummary $message): void
    {
        $period = MonthPeriod::containing($this->calendar->today())->previous();
        $text   = $this->reportView->render($this->reports->build($period))
            . "\n\n<b>Баланс</b>\n"
            . $this->balanceView->render($this->balances->current());

        foreach ($this->participants->all() as $participant) {
            if ($participant->telegramId === null) {
                continue;
            }

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
}
