<?php

declare(strict_types=1);

namespace App\Application\Parsing;

use App\Application\UserError;
use App\Domain\Parsing\ExpenseParseException;
use App\Domain\Parsing\ParseError;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Plain-text explanations of parser errors in the current language; callers escape them for their output format.
 */
final readonly class ParseErrorMessages
{
    public const array EXAMPLES = [
        '17000 продукты',
        '18,5 eur чатгпт 10.11',
        '9700 доставка вчера',
        '700 кофе лично',
    ];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function message(ExpenseParseException $exception): string
    {
        $error = $this->error($exception);

        return $this->translator->trans($error->key, $error->parameters);
    }

    /**
     * The explanation as a UserError, for pages that translate it when they render.
     */
    public function error(ExpenseParseException $exception): UserError
    {
        $context = $exception->context;

        return match ($exception->error) {
            ParseError::Empty   => new UserError('Пустая строка.'),
            ParseError::TooLong => new UserError(
                'Слишком длинно, максимум %max% символов.',
                ['%max%' => $context['max'] ?? ''],
            ),
            ParseError::NoAmount       => new UserError('Не нашёл сумму. Начни строку с числа.'),
            ParseError::ZeroAmount     => new UserError('Сумма не может быть нулевой.'),
            ParseError::TooPrecise     => $this->tooPrecise($context),
            ParseError::AmountTooLarge => new UserError(
                'Слишком большая сумма: «%amount%».',
                ['%amount%' => $context['amount'] ?? ''],
            ),
            ParseError::InvalidDate => new UserError(
                'Не понял дату «%date%». Формат: дд.мм, дд.мм.гггг, «вчера».',
                ['%date%' => $context['date'] ?? ''],
            ),
            ParseError::FutureDate => new UserError(
                'Дата «%date%» ещё не наступила.',
                ['%date%' => $context['date'] ?? ''],
            ),
        };
    }

    /**
     * @param array<string, string> $context
     */
    private function tooPrecise(array $context): UserError
    {
        if (($context['digits'] ?? '') === '0') {
            return new UserError(
                'У %currency% нет дробной части, а в «%amount%» она есть.',
                ['%currency%' => $context['currency'] ?? '', '%amount%' => $context['amount'] ?? ''],
            );
        }

        return new UserError(
            'У %currency% не больше %digits% знаков после запятой, а в «%amount%» больше.',
            [
                '%currency%' => $context['currency'] ?? '',
                '%digits%'   => $context['digits'] ?? '',
                '%amount%'   => $context['amount'] ?? '',
            ],
        );
    }
}
