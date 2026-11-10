<?php

declare(strict_types=1);

namespace App\Bot\Command;

use App\Application\Balance\BalanceService;
use App\Bot\BotContext;
use App\Bot\Reply\TextReply;
use App\Bot\View\BalanceText;

final readonly class BalanceCommand implements BotCommand
{
    public function __construct(
        private BalanceService $balances,
        private BalanceText $view,
    ) {
    }

    public function name(): string
    {
        return 'balance';
    }

    public function description(): string
    {
        return 'кто кому сколько должен';
    }

    public function handle(BotContext $context, string $arguments): array
    {
        return [new TextReply($context->chatId, $this->view->render($this->balances->current()))];
    }
}
