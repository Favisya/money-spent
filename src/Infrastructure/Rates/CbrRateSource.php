<?php

declare(strict_types=1);

namespace App\Infrastructure\Rates;

use App\Application\Rates\RateQuote;
use App\Application\Rates\RateSnapshot;
use App\Application\Rates\RateSource;
use App\Application\Rates\RateSourceException;
use DateTimeImmutable;
use SimpleXMLElement;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Central Bank of Russia daily XML: RUB per "Nominal" units. Re-based to AMD per unit through the AMD quote, so the
 * snapshot speaks the same language as the CBA source.
 */
final readonly class CbrRateSource implements RateSource
{
    private const string ENDPOINT = '/scripts/XML_daily.asp';
    private const string BASE     = 'AMD';
    private const string RUB      = 'RUB';
    private const int MAX_BYTES   = 1_048_576;
    private const int SCALE       = 8;

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function name(): string
    {
        return 'cbr';
    }

    public function fetch(DateTimeImmutable $date): RateSnapshot
    {
        try {
            $content = $this->httpClient->request('GET', self::ENDPOINT, [
                'query' => ['date_req' => $date->format('d/m/Y')],
            ])->getContent();
        } catch (ExceptionInterface $exception) {
            throw new RateSourceException('CBR request failed: ' . $exception->getMessage(), 0, $exception);
        }

        if (strlen($content) > self::MAX_BYTES) {
            throw new RateSourceException('CBR response is too large.');
        }

        return $this->parse($content);
    }

    public function parse(string $xml): RateSnapshot
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false) {
            throw new RateSourceException('CBR response is not valid XML.');
        }

        $date = DateTimeImmutable::createFromFormat('!d.m.Y', (string) $document['Date']);

        if ($date === false) {
            throw new RateSourceException('CBR response has no date.');
        }

        $rubPerUnit = [];

        foreach ($document->Valute as $valute) {
            $code    = strtoupper(trim((string) $valute->CharCode));
            $value   = str_replace(',', '.', trim((string) $valute->Value));
            $nominal = trim((string) $valute->Nominal);

            if (preg_match('/^[A-Z]{3}$/', $code) !== 1 || !is_numeric($value) || !ctype_digit($nominal)) {
                continue;
            }

            if ((int) $nominal > 0 && bccomp($value, '0', self::SCALE) > 0) {
                $rubPerUnit[$code] = bcdiv($value, $nominal, 12);
            }
        }

        $rubPerBase = $rubPerUnit[self::BASE] ?? null;

        if ($rubPerBase === null) {
            throw new RateSourceException('CBR response has no AMD rate.');
        }

        $quotes = [new RateQuote(self::RUB, bcdiv('1', $rubPerBase, self::SCALE))];

        foreach ($rubPerUnit as $code => $rub) {
            if ($code !== self::BASE) {
                $quotes[] = new RateQuote($code, bcdiv($rub, $rubPerBase, self::SCALE));
            }
        }

        return new RateSnapshot($date, $quotes);
    }
}
