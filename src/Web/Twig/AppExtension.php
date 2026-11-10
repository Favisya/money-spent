<?php

declare(strict_types=1);

namespace App\Web\Twig;

use App\Application\Reference\CurrencyCatalog;
use App\Application\Report\MonthPeriod;
use App\Bot\View\MoneyFormatter;
use App\Bot\View\MonthNames;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Split\SplitType;
use App\Web\Currency\DisplayCurrency;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final readonly class AppExtension
{
    private const array SHORT_MONTHS = [
        1  => 'янв',
        2  => 'фев',
        3  => 'мар',
        4  => 'апр',
        5  => 'май',
        6  => 'июн',
        7  => 'июл',
        8  => 'авг',
        9  => 'сен',
        10 => 'окт',
        11 => 'ноя',
        12 => 'дек',
    ];

    public function __construct(
        private MoneyFormatter $money,
        private MonthNames $months,
        private CurrencyCatalog $currencies,
        private DisplayCurrency $display,
    ) {
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
     * @return list<Currency>
     */
    #[AsTwigFunction('display_choices')]
    public function displayChoices(): array
    {
        return $this->display->choices();
    }

    #[AsTwigFilter('rate')]
    public function rate(string $rate): string
    {
        return $this->money->rate($rate);
    }

    #[AsTwigFilter('month_label')]
    public function monthLabel(MonthPeriod $period): string
    {
        return $this->months->label($period);
    }

    #[AsTwigFilter('month_short')]
    public function monthShort(MonthPeriod $period): string
    {
        return self::SHORT_MONTHS[$period->month];
    }

    #[AsTwigFilter('split_label')]
    public function splitLabel(SplitType $split): string
    {
        return match ($split) {
            SplitType::Equal     => 'пополам',
            SplitType::PayerOnly => 'личное',
            SplitType::OtherOnly => 'за другого',
        };
    }

    private function toBase(Money|int $amount): Money
    {
        return $amount instanceof Money ? $amount : Money::ofMinor($amount, $this->currencies->base());
    }
}
