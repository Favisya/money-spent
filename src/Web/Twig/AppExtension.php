<?php

declare(strict_types=1);

namespace App\Web\Twig;

use App\Application\Analytics\Owner;
use App\Application\Rates\RateSourceChoice;
use App\Application\Reference\CurrencyCatalog;
use App\Application\Report\MonthPeriod;
use App\Application\UserError;
use App\Bot\View\MoneyFormatter;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Split\SplitType;
use App\Web\Currency\DisplayCurrency;
use App\Web\Currency\DisplayQuote;
use App\Web\Locale\CalendarNames;
use App\Web\Locale\LanguageListener;
use App\Web\People\PeoplePalette;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final readonly class AppExtension
{
    public function __construct(
        private MoneyFormatter $money,
        private CalendarNames $calendar,
        private CurrencyCatalog $currencies,
        private DisplayCurrency $display,
        private PeoplePalette $people,
        private TranslatorInterface $translator,
        private LanguageListener $languages,
    ) {
    }

    /**
     * A text meant for the user: a Russian source string, or a UserError with its parameters, in the page language.
     */
    #[AsTwigFilter('message')]
    public function message(string|UserError|null $message): string
    {
        return match (true) {
            $message === null             => '',
            $message instanceof UserError => $this->translator->trans($message->key, $message->parameters),
            default                       => $this->translator->trans($message),
        };
    }

    /**
     * @return list<string>
     */
    #[AsTwigFunction('languages')]
    public function languages(): array
    {
        return $this->languages->languages();
    }

    #[AsTwigFilter('money')]
    public function money(Money $money): string
    {
        return $this->money->format($money);
    }

    #[AsTwigFilter('money_number')]
    public function moneyNumber(Money $money): string
    {
        return $this->money->number($money);
    }

    /**
     * A base-currency amount (Money or minor units) in the currency the viewer picked.
     */
    #[AsTwigFilter('shown')]
    public function shown(Money|int $amount): string
    {
        return $this->money->format($this->display->show($this->toBase($amount)));
    }

    #[AsTwigFilter('shown_number')]
    public function shownNumber(Money|int $amount): string
    {
        return $this->money->number($this->display->show($this->toBase($amount)));
    }

    /**
     * @return list<string> the amount in every other currency, e.g. ["56.30 USD", "48.10 EUR"]
     */
    #[AsTwigFunction('equivalents')]
    public function equivalents(Money|int $amount): array
    {
        return array_map($this->money->format(...), $this->display->equivalents($this->toBase($amount)));
    }

    #[AsTwigFunction('display_currency')]
    public function displayCurrency(): Currency
    {
        return $this->display->current();
    }

    /**
     * @return list<DisplayQuote>
     */
    #[AsTwigFunction('rate_quotes')]
    public function rateQuotes(): array
    {
        return $this->display->quotes();
    }

    #[AsTwigFunction('rate_source')]
    public function rateSource(): RateSourceChoice
    {
        return $this->display->source();
    }

    /**
     * @return list<RateSourceChoice>
     */
    #[AsTwigFunction('rate_sources')]
    public function rateSources(): array
    {
        return RateSourceChoice::cases();
    }

    #[AsTwigFilter('rate_value')]
    public function rateValue(string $value): string
    {
        [$integral, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integral              = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', "\u{00A0}", $integral);

        return $fraction === '' ? $integral : $integral . ',' . $fraction;
    }

    /**
     * @return list<Currency>
     */
    #[AsTwigFunction('display_choices')]
    public function displayChoices(): array
    {
        return $this->display->choices();
    }

    /**
     * A round badge with the participant's first letter in their color.
     */
    #[AsTwigFunction('avatar', isSafe: ['html'])]
    public function avatar(int $participantId, string $name): string
    {
        $letter = mb_strtoupper(mb_substr(trim($name), 0, 1));

        return sprintf(
            '<span class="avatar" style="--av: %s" aria-hidden="true">%s</span>',
            $this->people->cssVar($participantId),
            htmlspecialchars($letter === '' ? '?' : $letter, ENT_QUOTES | ENT_HTML5),
        );
    }

    #[AsTwigFunction('person_color')]
    public function personColor(int $participantId): string
    {
        return $this->people->cssVar($participantId);
    }

    #[AsTwigFunction('owner_color')]
    public function ownerColor(Owner $owner): string
    {
        return $this->people->ownerCss($owner);
    }

    #[AsTwigFilter('rate')]
    public function rate(string $rate): string
    {
        return $this->money->rate($rate);
    }

    #[AsTwigFilter('month_label')]
    public function monthLabel(MonthPeriod $period): string
    {
        return $this->calendar->month($period);
    }

    #[AsTwigFilter('month_short')]
    public function monthShort(MonthPeriod $period): string
    {
        return $this->calendar->shortMonth($period);
    }

    /**
     * @return array<int, string> ISO day number (1 is Monday) => short weekday name
     */
    #[AsTwigFunction('weekdays')]
    public function weekdays(): array
    {
        return $this->calendar->weekdays();
    }

    /**
     * "Общие" or "Анна лично" in the page language.
     */
    #[AsTwigFilter('owner_label')]
    public function ownerLabel(Owner $owner): string
    {
        return $owner->isShared()
            ? $this->translator->trans('Общие')
            : $this->translator->trans('%name% лично', ['%name%' => $owner->label]);
    }

    #[AsTwigFilter('split_label')]
    public function splitLabel(SplitType $split): string
    {
        return $this->translator->trans(match ($split) {
            SplitType::Equal     => 'пополам',
            SplitType::PayerOnly => 'личное',
            SplitType::OtherOnly => 'за другого',
        });
    }

    private function toBase(Money|int $amount): Money
    {
        return $amount instanceof Money ? $amount : Money::ofMinor($amount, $this->currencies->base());
    }
}
