<?php

declare(strict_types=1);

namespace App\Application\Currency;

use App\Application\Clock\LocalCalendar;
use App\Application\Rates\MarketRates;
use App\Application\Rates\PendingRateResolver;
use App\Application\Rates\RateUpdater;
use App\Application\Reference\CurrencyCatalog;
use App\Application\Reference\CurrencyDefinition;
use App\Application\UserError;
use App\Infrastructure\Persistence\CurrencyRepository;

/**
 * Which currencies are offered: any ISO 4217 code can be added, hidden and shown again.
 */
final readonly class CurrencyAdmin
{
    private const string CODE      = '/^[A-Z]{3}$/';
    private const int MAX_EXPONENT = 8;

    public function __construct(
        private CurrencyRepository $currencies,
        private CurrencyCatalog $catalog,
        private CurrencyStandard $standard,
        private RateUpdater $rates,
        private PendingRateResolver $pending,
        private MarketRates $market,
        private LocalCalendar $calendar,
    ) {
    }

    public function add(string $code, string $aliases): void
    {
        $code = strtoupper(trim($code));

        if (preg_match(self::CODE, $code) !== 1 || !$this->standard->exists($code)) {
            throw new UserError('Нет валюты с кодом «%code%».', ['%code%' => mb_substr($code, 0, 10)]);
        }

        $known    = $this->find($code);
        $words    = $aliases === '' && $known !== null ? $known->aliases : CurrencyAliases::parse($aliases);
        $exponent = $known?->currency->exponent ?? min(self::MAX_EXPONENT, $this->standard->fractionDigits($code));

        $this->assertFree($code, $words);
        $this->currencies->add($code, $exponent, $words);
        $this->catalog->reset();
        $this->fetchRates();
    }

    public function changeAliases(string $code, string $aliases): void
    {
        $this->known($code);
        $words = CurrencyAliases::parse($aliases);

        $this->assertFree($code, $words);
        $this->currencies->updateAliases($code, $words);
        $this->catalog->reset();
    }

    public function setActive(string $code, bool $active): void
    {
        $this->known($code);

        if (!$active && $this->catalog->isBase($code)) {
            throw new UserError('Основную валюту учёта скрыть нельзя: в ней считаются все итоги.');
        }

        $this->currencies->setActive($code, $active);
        $this->catalog->reset();

        if ($active) {
            $this->fetchRates();
        }
    }

    /**
     * Today's rates for a newly shown currency; the daily schedule fills in the rest.
     */
    private function fetchRates(): void
    {
        $today = $this->calendar->today();

        $this->rates->updateDays($today, 1);
        $this->pending->resolve();
        $this->market->refresh($today);
    }

    private function known(string $code): CurrencyDefinition
    {
        return $this->find($code) ?? throw new UserError('Такой валюты нет.');
    }

    private function find(string $code): ?CurrencyDefinition
    {
        foreach ($this->catalog->all() as $definition) {
            if ($definition->currency->code === $code) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * An alias names one currency only, or the entry line could not tell them apart.
     *
     * @param list<string> $words
     */
    private function assertFree(string $code, array $words): void
    {
        foreach ($this->catalog->all() as $definition) {
            $other = $definition->currency->code;

            if ($other === $code || !$definition->active) {
                continue;
            }

            $taken = array_intersect($words, [mb_strtolower($other), ...$definition->aliases]);

            if ($taken !== []) {
                throw new UserError(
                    'Слово «%word%» уже означает %code%.',
                    ['%word%' => reset($taken), '%code%' => $other],
                );
            }
        }
    }
}
