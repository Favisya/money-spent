<?php

declare(strict_types=1);

namespace App\Application\Parsing;

use App\Domain\Parsing\ExpenseParseException;
use App\Domain\Parsing\ParseError;

/**
 * Plain-text explanations of parser errors; callers escape them for their output format.
 */
final class ParseErrorMessages
{
    public const array EXAMPLES = [
        '17000 продукты',
        '18,5 eur чатгпт 10.11',
        '9700 доставка вчера',
        '700 кофе лично',
    ];

    public function message(ExpenseParseException $exception): string
    {
        $context = $exception->context;

        return match ($exception->error) {
            ParseError::Empty          => 'Пустая строка.',
            ParseError::TooLong        => sprintf('Слишком длинно, максимум %s символов.', $context['max'] ?? ''),
            ParseError::NoAmount       => 'Не нашёл сумму. Начни строку с числа.',
            ParseError::ZeroAmount     => 'Сумма не может быть нулевой.',
            ParseError::TooPrecise     => $this->tooPrecise($context),
            ParseError::AmountTooLarge => sprintf('Слишком большая сумма: «%s».', $context['amount'] ?? ''),
            ParseError::InvalidDate    => sprintf(
                'Не понял дату «%s». Формат: дд.мм, дд.мм.гггг, «вчера».',
                $context['date'] ?? '',
            ),
            ParseError::FutureDate => sprintf('Дата «%s» ещё не наступила.', $context['date'] ?? ''),
        };
    }

    /**
     * @param array<string, string> $context
     */
    private function tooPrecise(array $context): string
    {
        if (($context['digits'] ?? '') === '0') {
            return sprintf(
                'У %s нет дробной части, а в «%s» она есть.',
                $context['currency'] ?? '',
                $context['amount'] ?? '',
            );
        }

        return sprintf(
            'У %s не больше %s знаков после запятой, а в «%s» больше.',
            $context['currency'] ?? '',
            $context['digits'] ?? '',
            $context['amount'] ?? '',
        );
    }
}
