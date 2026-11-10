<?php

declare(strict_types=1);

namespace App\Bot\Command;

use App\Application\Export\CsvExporter;
use App\Application\Report\MonthPeriod;
use App\Bot\BotContext;
use App\Bot\Reply\DocumentReply;
use App\Bot\Reply\TextReply;
use App\Bot\View\MonthNames;

final readonly class ExportCommand implements BotCommand
{
    public function __construct(
        private CsvExporter $exporter,
        private MonthNames $months,
    ) {
    }

    public function name(): string
    {
        return 'export';
    }

    public function description(): string
    {
        return 'CSV трат за месяц: /export, /export 10';
    }

    public function handle(BotContext $context, string $arguments): array
    {
        $period = MonthPeriod::fromArgument($arguments, $context->today);
        $csv    = $this->exporter->export($period);
        $label  = $this->months->label($period);

        if ($csv === null) {
            return [new TextReply($context->chatId, sprintf('За %s трат нет.', mb_strtolower($label)))];
        }

        return [new DocumentReply(
            $context->chatId,
            sprintf('expenses-%s.csv', $period->key()),
            $csv,
            sprintf('Траты: %s', mb_strtolower($label)),
        )];
    }
}
