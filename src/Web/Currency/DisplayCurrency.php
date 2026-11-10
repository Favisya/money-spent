<?php

declare(strict_types=1);

namespace App\Web\Currency;

use App\Application\Clock\LocalCalendar;
use App\Application\Reference\CurrencyCatalog;
use App\Domain\Money\Currency;
use App\Domain\Money\ExchangeRate;
use App\Domain\Money\ExchangeRateProvider;
use App\Domain\Money\Money;
use App\Domain\Money\RateNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The currency the viewer wants base (AMD) amounts shown in. Uses the latest known CBA rate; amounts recorded in their
 * own currency are never touched, only totals and converted columns.
 */
final class DisplayCurrency implements ResetInterface
{
    public const string COOKIE = 'display_currency';

    /** @var array<string, ExchangeRate|null> */
    private array $rates = [];

    public function __construct(
        private readonly RequestStack $requests,
        private readonly CurrencyCatalog $currencies,
        private readonly ExchangeRateProvider $provider,
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

    public function rate(Currency $currency): ?ExchangeRate
    {
        if (!array_key_exists($currency->code, $this->rates)) {
            try {
                $this->rates[$currency->code] = $this->provider->rateFor($currency, $this->calendar->today());
            } catch (RateNotFoundException) {
                $this->rates[$currency->code] = null;
            }
        }

        return $this->rates[$currency->code];
    }
}
