<?php

declare(strict_types=1);

namespace App\Bot\Command;

use App\Application\Report\MonthlyReportService;
use App\Application\Report\MonthPeriod;
use App\Bot\BotContext;
use App\Bot\Reply\TextReply;
use App\Bot\View\MonthlyReportText;

final readonly class MonthCommand implements BotCommand
{
    public function __construct(
        private MonthlyReportService $reports,
        private MonthlyReportText $view,
    ) {
    }

    public function name(): string
    {
        return 'month';
    }

    public function description(): string
    {
        return 'итоги месяца: /month, /month 10, /month 10.2025';
    }

    public function handle(BotContext $context, string $arguments): array
    {
        $report = $this->reports->build(MonthPeriod::fromArgument($arguments, $context->today));

        return [new TextReply($context->chatId, $this->view->render($report))];
    }
}
