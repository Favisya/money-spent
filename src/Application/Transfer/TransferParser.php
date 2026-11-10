<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Application\Import\PayerGuesser;
use App\Application\Parsing\ParserFactory;
use App\Application\Participant\Counterpart;
use App\Application\Participant\Participant;
use App\Application\UserError;
use App\Domain\Parsing\ExpenseParseException;
use DateTimeImmutable;

/**
 * Reads lines like "алиса скинула 5000", "скинул алисе 3000 руб", "перевёл маме 10000 05.11".
 * A participant named before the verb (or after "от") is the sender, one named after it is the recipient; any other
 * first word after the verb is someone outside the ledger. "Я", "мне" and the like mean the defaults.
 */
final readonly class TransferParser
{
    private const int NAME_LENGTH = 64;

    private const array SPEAKER = ['я', 'мы', 'мне', 'нам', 'меня', 'нас'];

    private const array NOT_A_RECIPIENT = [
        'за', 'на', 'в', 'во', 'по', 'под', 'с', 'со', 'из', 'от', 'обратно', 'назад', 'долг', 'долга', 'деньги',
        'денег', 'сумму',
    ];

    /**
     * @param list<string> $transferVerbs
     */
    public function __construct(
        private ParserFactory $parsers,
        private PayerGuesser $guesser,
        private Counterpart $counterpart,
        private array $transferVerbs,
    ) {
    }

    public function isTransfer(string $text): bool
    {
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            if ($this->isVerb($word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Participant> $participants
     *
     * @throws ExpenseParseException when the amount, currency or date is wrong
     */
    public function parse(
        string $text,
        DateTimeImmutable $today,
        Participant $defaultSender,
        array $participants,
        ?Participant $defaultRecipient = null,
        ?string $defaultOutsider = null,
        ?DateTimeImmutable $defaultDate = null,
    ): TransferIntent {
        $parsed = $this->parsers->create()->parse($text, $today);
        $words  = $parsed->note === null ? [] : (preg_split('/\s+/u', $parsed->note) ?: []);
        $verb   = $this->verbIndex($words);

        $sender    = null;
        $recipient = null;
        $outsider  = null;
        $rest      = [];

        for ($i = 0, $count = count($words); $i < $count; ++$i) {
            $word  = self::normalize($words[$i]);
            $named = $this->guesser->participantIn($word, $participants);

            if ($i === $verb || in_array($word, self::SPEAKER, true)) {
                continue;
            }

            if ($word === 'от' && $this->guesser->participantIn($words[$i + 1] ?? '', $participants) !== null) {
                $sender ??= $this->guesser->participantIn($words[++$i], $participants);

                continue;
            }

            if ($named !== null) {
                if ($verb !== null && $i < $verb) {
                    $sender ??= $named;
                } else {
                    $recipient ??= $named;
                }

                continue;
            }

            $firstAfterVerb = $verb !== null && $i === $verb + 1;

            if ($outsider === null && $firstAfterVerb && !in_array($word, self::NOT_A_RECIPIENT, true)) {
                $outsider = $word === 'для' && isset($words[$i + 1]) ? $words[++$i] : $words[$i];

                continue;
            }

            $rest[] = $words[$i];
        }

        $sender ??= $defaultSender;

        if ($recipient === null && $outsider === null) {
            $recipient = $defaultRecipient;
            $outsider  = $defaultRecipient === null ? $defaultOutsider : null;
        }

        if ($recipient === null && $outsider === null) {
            $recipient = $this->counterpart->of($sender);
        }

        if ($recipient !== null && $recipient->id === $sender->id) {
            throw new UserError('Перевод самому себе не записываю: выбери, кому.');
        }

        if ($outsider !== null && (trim($outsider) === '' || mb_strlen($outsider) > self::NAME_LENGTH)) {
            throw new UserError(sprintf('Кому перевод — имя от 1 до %d символов.', self::NAME_LENGTH));
        }

        return new TransferIntent(
            $sender,
            $recipient,
            $outsider === null ? null : trim($outsider),
            $parsed->amount->abs(),
            $parsed->hasExplicitDate || $defaultDate === null ? $parsed->spentAt : $defaultDate,
            $rest === [] ? null : implode(' ', $rest),
        );
    }

    /**
     * @param list<string> $words
     */
    private function verbIndex(array $words): ?int
    {
        foreach ($words as $index => $word) {
            if ($this->isVerb($word)) {
                return $index;
            }
        }

        return null;
    }

    private function isVerb(string $word): bool
    {
        return in_array(self::normalize($word), $this->transferVerbs, true);
    }

    private static function normalize(string $word): string
    {
        $word = preg_replace('/^[,.;:!?()"\'«»]+|[,.;:!?()"\'«»]+$/u', '', $word) ?? $word;

        return str_replace('ё', 'е', mb_strtolower($word));
    }
}
