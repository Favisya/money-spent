<?php

declare(strict_types=1);

namespace App\Bot\Command;

use App\Application\Undo\UndoService;
use App\Bot\BotContext;
use App\Bot\Reply\TextReply;
use App\Bot\View\Html;
use App\Bot\View\MoneyFormatter;
use App\Infrastructure\Persistence\LedgerTable;

final readonly class UndoCommand implements BotCommand
{
    public function __construct(
        private UndoService $undo,
        private MoneyFormatter $money,
    ) {
    }

    public function name(): string
    {
        return 'undo';
    }

    public function description(): string
    {
        return 'удалить мою последнюю запись';
    }

    public function handle(BotContext $context, string $arguments): array
    {
        $entry = $this->undo->undoLast($context->participant);

        if ($entry === null) {
            return [new TextReply($context->chatId, 'Нечего удалять.')];
        }

        $text = sprintf(
            '↩️ Удалил %s: %s от %s%s',
            $entry->table === LedgerTable::Expense ? 'трату' : 'перевод',
            $this->money->format($entry->amount),
            $entry->date->format('d.m.Y'),
            $entry->note === null ? '' : ' (' . Html::escape($entry->note) . ')',
        );

        return [new TextReply($context->chatId, $text)];
    }
}
