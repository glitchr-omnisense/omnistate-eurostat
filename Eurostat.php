<?php

namespace Omnistate\Eurostat;

use Omnisense\Contracts\Http\Answer;
use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnistate\Exception\NotSupportedException;
use Omnistate\Exception\OmnistateException;
use Omnistate\Exception\UnavailableException;
use Omnistate\Http\JsonClient;
use Omnistate\Model\Dataset;
use Omnistate\Model\Frequency;
use Omnistate\Model\Observation;
use Omnistate\Model\Series;
use Omnistate\Model\TimePeriod;
use Omnistate\Registry\StatisticsRegistryInterface;
use Omnistate\Sdmx\Datasets;
use Omnistate\Sdmx\ObservationFlags;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Eurostat's statistics, through its dissemination API (API Statistics,
 * JSON-stat 2.0): open, no key. A series is a dataset's code and one code
 * for each of its dimensions but time, written as the API's filters:
 *
 *     $eurostat->series('prc_hicp_manr?freq=M&unit=RCH_A&coicop=CP00&geo=FR', last: 12);
 *
 * A filter left out keeps every code of that dimension: the id must select
 * one series (an OmnistateException names the dimensions left open). Its
 * flags (p provisional, e estimated, b break...) are each observation's
 * status. Eurostat keeps no history of revised values: history is not
 * supported.
 */
final class Eurostat implements StatisticsRegistryInterface, DeclaresUsagePolicyInterface
{
    public const URL = 'https://ec.europa.eu/eurostat/api/dissemination';

    private readonly JsonClient $client;

    /**
     * @param string      $language  "EN", "FR" or "DE": the labels' language
     * @param string|null $userAgent sent with every call; the package's own when null
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly float $timeout = 30,
        private readonly string $language = 'EN',
        ?string $userAgent = null,
        private readonly string $url = self::URL,
    ) {
        $this->client = new JsonClient($http, 'Eurostat', ['User-Agent' => $userAgent ?? 'omnistate-eurostat (+https://github.com/glitchr-omnisense/omnistate-eurostat)']);
    }

    public function usagePolicy(): UsagePolicy
    {
        return UsagePolicy::fromFile(__DIR__.'/policy.json');
    }

    public function name(): string
    {
        return 'eurostat';
    }

    public function supports(string $series): bool
    {
        return null !== self::parse($series);
    }

    public function series(string $series, ?TimePeriod $from = null, ?TimePeriod $to = null, ?int $last = null, bool $history = false): ?Series
    {
        [$code, $filters] = self::parse($series) ?? throw new NotSupportedException(\sprintf('"%s" is not a Eurostat series: a dataset code and its filters ("prc_hicp_manr?freq=M&unit=RCH_A&coicop=CP00&geo=FR").', $series));
        if ($history) {
            throw new NotSupportedException('Eurostat keeps no history of revised values.');
        }
        // One time parameter at most, but for sinceTimePeriod with untilTimePeriod (the API's guide).
        $time = null !== $from || null !== $to
            ? array_filter(['sinceTimePeriod' => null === $from ? null : (string) $from, 'untilTimePeriod' => null === $to ? null : (string) $to])
            : (null === $last ? [] : ['lastTimePeriod' => (string) max(1, $last)]);
        $query = http_build_query($filters + ['lang' => $this->language] + $time, '', '&', \PHP_QUERY_RFC3986);
        $answer = $this->client->answer('GET', rtrim($this->url, '/').'/statistics/1.0/data/'.$code.'?'.$query, ['timeout' => $this->timeout], ['Accept' => 'application/json'], [400, 413]);
        if (null === $answer || $this->noResults($answer)) {
            return null;
        }

        return $this->toSeries($answer, $code, $filters, null !== $from || null !== $to ? $last : null);
    }

    public function datasets(string $query, int $limit = 10): array
    {
        $answer = $this->client->answer('GET', rtrim($this->url, '/').'/catalogue/toc/txt', ['query' => ['lang' => strtolower($this->language)], 'timeout' => $this->timeout]);

        return null === $answer ? [] : Datasets::search($this->toc($answer->body), $query, $limit);
    }

    /**
     * A dataset's code and its filters, by lower-case dimension.
     *
     * @return array{string, array<string, string>}|null
     */
    private static function parse(string $series): ?array
    {
        if (1 !== preg_match('/^([A-Za-z0-9_$]+)(?:\?(.*))?$/', trim($series), $match)) {
            return null;
        }
        $filters = [];
        foreach ('' === ($match[2] ?? '') ? [] : explode('&', $match[2]) as $pair) {
            if (1 !== preg_match('/^([A-Za-z0-9_]+)=([A-Za-z0-9_.\-]+)$/', $pair, $one) || \in_array(strtolower($one[1]), ['lang', 'format', 'time', 'time_period', 'sincetimeperiod', 'untiltimeperiod', 'lasttimeperiod', 'geolevel'], true)) {
                return null;
            }
            $filters[strtolower($one[1])] = $one[2];
        }

        return [strtolower($match[1]), $filters];
    }

    /** Eurostat's "no results": a 400 or 404 with its fault code 100, or a dataset with no value. */
    private function noResults(Answer $answer): bool
    {
        $body = $answer->json();
        // {"error": [{"id": 100, ...}]} or {"error": {"id": 140, ...}}
        $error = [] !== $body->data('error')->data(0)->keys() ? $body->data('error')->data(0) : $body->data('error');
        if ([] !== $error->keys()) {
            if (100 === $error->int('id')) {
                return true;
            }

            throw new OmnistateException(\sprintf('Eurostat refused the question (%d): %s', $answer->status, $error->string('label') ?? 'no reason given.'), $answer->status);
        }
        if ($body->has('warning')) {
            throw new UnavailableException('Eurostat is preparing this extraction asynchronously: ask again later.', 60);
        }
        if (400 === $answer->status || 413 === $answer->status) {
            throw new OmnistateException(\sprintf('Eurostat refused the question (%d).', $answer->status), $answer->status);
        }
        if ([] === $body->strings('id') || !$body->has('size') || !$body->has('dimension')) {
            throw new UnavailableException('Eurostat answered something that is not a JSON-stat dataset.');
        }
        $sizes = (array) $body->get('size');

        return \in_array(0, $sizes, true) || [] === $body->data('value')->keys();
    }

    /** @param array<string, string> $filters */
    private function toSeries(Answer $answer, string $code, array $filters, ?int $last): Series
    {
        $data = $answer->json();
        $ids = array_values(array_map(static fn (mixed $id): string => \is_scalar($id) ? (string) $id : '', (array) $data->get('id')));
        $sizes = array_values(array_map(static fn (mixed $size): int => is_numeric($size) ? (int) $size : 0, (array) $data->get('size')));
        $time = array_search('time', $ids, true);
        if (false === $time) {
            throw new UnavailableException('Eurostat answered a dataset without its time dimension.');
        }
        $open = [];
        $dimensions = [];
        $labels = [];
        foreach ($ids as $position => $dimension) {
            if ($position === $time) {
                continue;
            }
            if (($sizes[$position] ?? 0) > 1) {
                $open[] = $dimension;
                continue;
            }
            $category = $data->data('dimension')->data($dimension)->data('category');
            $codeOf = (string) ($category->data('index')->keys()[0] ?? '');
            $dimensions[$dimension] = $codeOf;
            $labels[$dimension] = $category->data('label')->string($codeOf) ?? $codeOf;
        }
        if ([] !== $open) {
            throw new OmnistateException(\sprintf('"%s" selects several Eurostat series: name one code for %s.', $code.([] === $filters ? '' : '?'.http_build_query($filters)), implode(', ', $open)));
        }
        $index = $data->data('dimension')->data('time')->data('category')->data('index');
        $periods = [];
        foreach ($index->keys() as $period) {
            $periods[(int) $index->int($period)] = (string) $period;
        }
        ksort($periods);
        $frequency = Frequency::fromCode($dimensions['freq'] ?? null);
        $values = $data->data('value');
        $statuses = $data->data('status');
        $observations = [];
        foreach ($periods as $position => $period) {
            // One series: a value's position is its period's (every other dimension has one code).
            $value = $values->float($position);
            $flags = ObservationFlags::letters($statuses->string($position) ?? '');
            if (null === $value && [] === $flags) {
                continue;
            }
            $observations[] = new Observation(
                period: TimePeriod::parse($period, $frequency),
                value: $value,
                status: null === $value && !\in_array('c', $flags, true) ? ObservationFlags::status([...$flags, 'M']) : ObservationFlags::status($flags),
                revised: \in_array('r', $flags, true),
                flags: $flags,
            );
        }
        usort($observations, static fn (Observation $a, Observation $b): int => $a->period->compare($b->period));
        if (null !== $last) {
            $observations = \array_slice($observations, -max(1, $last));
        }
        $updated = $data->string('updated');
        $date = $answer->header('date');
        $accessed = null !== $date ? new \DateTimeImmutable($date) : new \DateTimeImmutable();
        $doi = 'https://doi.org/10.2908/'.strtoupper($code);
        $title = $data->string('label') ?? $code;
        $detail = implode(', ', array_diff_key($labels, ['freq' => true, 'unit' => true]));

        return new Series(
            source: $this->name(),
            id: $code.([] === $filters ? '' : '?'.http_build_query($filters, '', '&', \PHP_QUERY_RFC3986)),
            title: '' !== $detail ? $title.' - '.$detail : $title,
            frequency: $frequency,
            unit: $labels['unit'] ?? null,
            dataset: $code,
            updated: null === $updated ? null : new \DateTimeImmutable($updated),
            credit: \sprintf('Eurostat, %s (%s), accessed on %s', $code, $doi, $accessed->format('Y-m-d')),
            licence: 'CC-BY-4.0',
            url: 'https://ec.europa.eu/eurostat/databrowser/view/'.$code.'/default/table',
            titles: [strtolower($this->language) => $title],
            dimensions: $dimensions,
            observations: $observations,
        );
    }

    /**
     * The table of contents (a tab-separated text): its datasets and tables, not its folders.
     *
     * @return list<Dataset>
     */
    private function toc(string $text): array
    {
        $lines = preg_split('/\r?\n/', $text) ?: [];
        if (!str_starts_with(ltrim((string) ($lines[0] ?? '')), '"title"')) {
            throw new UnavailableException('Eurostat answered something that is not its table of contents.');
        }
        $datasets = [];
        $seen = [];
        foreach (\array_slice($lines, 1) as $line) {
            $cells = str_getcsv($line, "\t", '"', '');
            if (\count($cells) < 4 || !\in_array($cells[2], ['dataset', 'table'], true) || isset($seen[$cells[1]])) {
                continue;
            }
            $seen[$cells[1]] = true;
            $updated = \DateTimeImmutable::createFromFormat('!d.m.Y', trim((string) $cells[3]), new \DateTimeZone('Europe/Luxembourg')) ?: null;
            $datasets[] = new Dataset($this->name(), (string) $cells[1], trim((string) $cells[0]), $updated, 'https://ec.europa.eu/eurostat/databrowser/view/'.$cells[1].'/default/table');
        }

        return $datasets;
    }
}
