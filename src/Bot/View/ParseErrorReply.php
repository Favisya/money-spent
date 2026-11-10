<?php

declare(strict_types=1);

namespace App\Bot\View;

use App\Application\Parsing\ParseErrorMessages;
use App\Domain\Parsing\ExpenseParseException;

final readonly class ParseErrorReply
{
    public function __construct(
        private ParseErrorMessages $messages,
        private BotTranslator $translator,
    ) {
    }

    public function render(ExpenseParseException $exception): string
    {
        $examples = array_map(
            fn (string $example): string => '<code>' . Html::escape($this->translator->text($example)) . '</code>',
            ParseErrorMessages::EXAMPLES,
        );

        return '⚠️ ' . Html::escape($this->messages->message($exception))
            . "\n\n" . $this->translator->text('Примеры:') . "\n" . implode("\n", $examples);
    }
}
