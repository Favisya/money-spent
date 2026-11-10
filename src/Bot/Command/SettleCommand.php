<?php

declare(strict_types=1);

namespace App\Bot\Command;

use App\Application\Balance\BalanceService;
use App\Application\Parsing\ParserFactory;
use App\Application\Settlement\SettlementRecorder;
use App\Bot\BotContext;
use App\Bot\Message\MessageUuid;
use App\Bot\Reply\TextReply;
use App\Bot\View\BalanceText;
use App\Bot\View\BotTranslator;
use App\Bot\View\Html;
use App\Bot\View\MoneyFormatter;
use App\Bot\View\ParseErrorReply;
use App\Domain\Parsing\ExpenseParseException;

final readonly class SettleCommand implements BotCommand
{
    private const string NO_RATE = 'курса пока нет, в баланс попадёт позже';

    public function __construct(
        private ParserFactory $parsers,
        private SettlementRecorder $recorder,
        private BalanceService $balances,
        private BalanceText $balanceView,
        private MoneyFormatter $money,
        private ParseErrorReply $errors,
        private BotTranslator $translator,
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
            return [new TextReply(
                $context->chatId,
                '⚠️ ' . $this->translator->text('Укажи сумму перевода: /settle 2500 руб'),
            )];
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

        $rate = match (true) {
            $base === null                                    => ' (' . $this->translator->text(self::NO_RATE) . ')',
            $base->currency->equals($draft->amount->currency) => '',
            default                                           => ' ≈ ' . $this->money->format($base),
        };

        $text = sprintf(
            "🤝 %s%s\n\n%s",
            $this->translator->text('Перевод записан: <b>%from% → %to%</b> %amount%', [
                '%from%'   => Html::escape($context->participant->name),
                '%to%'     => Html::escape($recorded->to->name),
                '%amount%' => $this->money->format($draft->amount),
            ]),
            $rate,
            $this->balanceView->render($this->balances->current()),
        );

        return [new TextReply($context->chatId, $text)];
    }
}
