<?php

declare(strict_types=1);

namespace App\Application\Reference;

use App\Domain\Money\Currency;
use App\Infrastructure\Persistence\CurrencyRepository;
use InvalidArgumentException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Currencies the user keeps. Hidden ones still format old entries but are not offered or parsed.
 */
final class CurrencyCatalog implements ResetInterface
{
    /** @var array<string, CurrencyDefinition>|null */
    private ?array $definitions = null;

    public function __construct(
        private readonly CurrencyRepository $repository,
        private readonly string $baseCurrencyCode,
    ) {
    }

    public function get(string $code): Currency
    {
        $definitions = $this->definitions();

        if (!isset($definitions[$code])) {
            throw new InvalidArgumentException(sprintf('Unknown currency "%s".', $code));
        }

        return $definitions[$code]->currency;
    }

    public function base(): Currency
    {
        return $this->get($this->baseCurrencyCode);
    }

    /**
     * @return list<Currency>
     */
    public function foreign(): array
    {
        $foreign = [];

        foreach ($this->definitions() as $code => $definition) {
            if ($code !== $this->baseCurrencyCode && $definition->active) {
                $foreign[] = $definition->currency;
            }
        }

        return $foreign;
    }

    /**
     * @return array<string, Currency> lower-case alias (and code) => currency
     */
    public function aliases(): array
    {
        $aliases = [];

        foreach ($this->definitions() as $code => $definition) {
            if (!$definition->active) {
                continue;
            }

            $aliases[mb_strtolower($code)] = $definition->currency;

            foreach ($definition->aliases as $alias) {
                $aliases[mb_strtolower($alias)] = $definition->currency;
            }
        }

        return $aliases;
    }

    /**
     * @return list<CurrencyDefinition> shown and hidden
     */
    public function all(): array
    {
        return array_values($this->definitions());
    }

    public function isBase(string $code): bool
    {
        return $code === $this->baseCurrencyCode;
    }

    public function reset(): void
    {
        $this->definitions = null;
    }

    /**
     * @return array<string, CurrencyDefinition>
     */
    private function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $this->definitions = [];

        foreach ($this->repository->findAll() as $definition) {
            $this->definitions[$definition->currency->code] = $definition;
        }

        return $this->definitions;
    }
}
