<?php

declare(strict_types=1);

namespace App\Bot\Command;

use App\Application\Balance\BalanceService;
use App\Application\Parsing\ParserFactory;
use App\Application\Settlement\SettlementRecorder;
use App\Application\UserError;
use App\Bot\BotContext;
use App\Bot\Message\MessageUuid;
use App\Bot\Reply\TextReply;
use App\Bot\View\BalanceText;
use App\Bot\View\Html;
use App\Bot\View\MoneyFormatter;
use App\Bot\View\ParseErrorReply;
use App\Domain\Parsing\ExpenseParseException;

final readonly class SettleCommand implements BotCommand
{
    public function __construct(
        private ParserFactory $parsers,
        private SettlementRecorder $recorder,
        private BalanceService $balances,
        private BalanceText $balanceView,
        private MoneyFormatter $money,
        private ParseErrorReply $errors,
    ) {
    }

    public function name(): string
    {
        return 'settle';
    }

    public function description(): string
    {
        return 'записать мой перевод второму участнику: /settle 2500 руб';
    }

    public function handle(BotContext $context, string $arguments): array
    {
        if ($arguments === '') {
            throw new UserError('Укажи сумму перевода: /settle 2500 руб');
        }

        try {
            $parsed = $this->parsers->create()->parse($arguments, $context->today);
        } catch (ExpenseParseException $exception) {
            return [new TextReply($context->chatId, $this->errors->render($exception))];
        }

        $recorded = $this->recorder->record(
            $context->participant,
            $parsed->amount,
            $parsed->spentAt,
            $parsed->note,
            MessageUuid::of($context),
        );

        $draft = $recorded->draft;
        $base  = $draft->amountBase();

        $text = sprintf(
            "🤝 Перевод записан: <b>%s → %s</b> %s%s\n\n%s",
            Html::escape($context->participant->name),
            Html::escape($recorded->to->name),
            $this->money->format($draft->amount),
            match (true) {
                $base === null                                    => ' (курса пока нет, в баланс попадёт позже)',
                $base->currency->equals($draft->amount->currency) => '',
                default                                           => ' ≈ ' . $this->money->format($base),
            },
            $this->balanceView->render($this->balances->current()),
        );

        return [new TextReply($context->chatId, $text)];
    }
}
