<?php

declare(strict_types=1);

namespace App\Web\Currency;

use App\Application\Clock\LocalCalendar;
use App\Application\Rates\MarketRates;
use App\Application\Rates\RateSourceChoice;
use App\Application\Reference\CurrencyCatalog;
use App\Domain\Money\Currency;
use App\Domain\Money\ExchangeRate;
use App\Domain\Money\Money;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The currency the viewer wants base (AMD) amounts shown in. Uses the latest known CBA rate; amounts recorded in their
 * own currency are never touched, only totals and converted columns.
 */
final class DisplayCurrency implements ResetInterface
{
    public const string COOKIE        = 'display_currency';
    public const string SOURCE_COOKIE = 'rate_source';

    private const array NOMINALS = [1, 100, 1_000, 10_000];
    private const int SCALE      = 8;

    /** @var array<string, ExchangeRate|null> */
    private array $rates = [];

    public function __construct(
        private readonly RequestStack $requests,
        private readonly CurrencyCatalog $currencies,
        private readonly MarketRates $market,
        private readonly LocalCalendar $calendar,
    ) {
    }

    public function reset(): void
    {
        $this->rates = [];
    }

    /**
     * @return list<Currency> base first
     */
    public function choices(): array
    {
        return [$this->currencies->base(), ...$this->currencies->foreign()];
    }

    public function isChoice(string $code): bool
    {
        foreach ($this->choices() as $currency) {
            if ($currency->code === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * The chosen currency, or the base one when nothing is chosen or its rate is unknown.
     */
    public function current(): Currency
    {
        $code = (string) $this->requests->getMainRequest()?->cookies->getString(self::COOKIE);

        if ($code === '' || !$this->isChoice($code)) {
            return $this->currencies->base();
        }

        $currency = $this->currencies->get($code);

        return $this->rate($currency) === null ? $this->currencies->base() : $currency;
    }

    public function show(Money $base): Money
    {
        return $this->in($base, $this->current()) ?? $base;
    }

    /**
     * @return Money|null null when there is no rate for that currency yet
     */
    public function in(Money $base, Currency $target): ?Money
    {
        if ($target->equals($base->currency)) {
            return $base;
        }

        return $this->rate($target)?->convertFromBase($base);
    }

    /**
     * @return list<Money> the amount in every other currency that has a rate
     */
    public function equivalents(Money $base): array
    {
        $shown = $this->current();
        $list  = [];

        foreach ($this->choices() as $currency) {
            $converted = $currency->equals($shown) ? null : $this->in($base, $currency);

            if ($converted !== null) {
                $list[] = $converted;
            }
        }

        return $list;
    }

    public function source(): RateSourceChoice
    {
        $code = (string) $this->requests->getMainRequest()?->cookies->getString(self::SOURCE_COOKIE);

        return RateSourceChoice::tryFrom($code) ?? RateSourceChoice::Cba;
    }

    /**
     * The rate from the chosen source, falling back to the official CBA rate while the source has nothing yet.
     */
    public function rate(Currency $currency): ?ExchangeRate
    {
        if (!array_key_exists($currency->code, $this->rates)) {
            $today  = $this->calendar->today();
            $source = $this->source();

            $chosen   = $this->market->rate($source, $currency, $today);
            $fallback = $chosen === null && $source !== RateSourceChoice::Cba
                ? $this->market->rate(RateSourceChoice::Cba, $currency, $today)
                : null;

            $this->rates[$currency->code] = $chosen ?? $fallback;
        }

        return $this->rates[$currency->code];
    }

    /**
     * Every other currency expressed in the display currency, scaled so the value reads well (100 AMD, not 1 AMD).
     *
     * @return list<DisplayQuote>
     */
    public function quotes(): array
    {
        $shown     = $this->current();
        $shownRate = $this->rate($shown);
        $quotes    = [];

        if ($shownRate === null) {
            return [];
        }

        foreach ($this->choices() as $currency) {
            $rate = $currency->equals($shown) ? null : $this->rate($currency);

            if ($rate === null) {
                continue;
            }

            $value   = bcdiv($rate->rate, $shownRate->rate, self::SCALE);
            $nominal = self::NOMINALS[0];

            foreach (self::NOMINALS as $nominal) {
                if (!self::below(bcmul($value, (string) $nominal, self::SCALE), '1')) {
                    break;
                }
            }

            $scaled = bcmul($value, (string) $nominal, self::SCALE);

            $quotes[] = new DisplayQuote(
                $currency->code,
                $nominal,
                bcadd($scaled, '0.005', 2),
                $shown->code,
                max($rate->date, $shownRate->date),
            );
        }

        return $quotes;
    }

    /**
     * @param numeric-string $value
     * @param numeric-string $limit
     */
    private static function below(string $value, string $limit): bool
    {
        return bccomp($value, $limit, self::SCALE) < 0;
    }
}
