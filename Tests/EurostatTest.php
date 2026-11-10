<?php

namespace Omnistate\Eurostat\Tests;

use Omnisense\Contracts\Testing\MockHttp;
use Omnistate\Eurostat\Eurostat;
use Omnistate\Exception\NotSupportedException;
use Omnistate\Exception\OmnistateException;
use Omnistate\Exception\UnavailableException;
use Omnistate\Model\Frequency;
use Omnistate\Model\ObservationStatus;
use Omnistate\Model\TimePeriod;
use Omnistate\Omnistate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/** Eurostat's API Statistics, on its answers recorded on 2026-11-10 (Tests/Fixtures). */
final class EurostatTest extends TestCase
{
    public function testASeriesByItsDatasetAndFilters(): void
    {
        $http = $this->http('hicp-fr.json');
        $hicp = (new Eurostat($http->client))->series('prc_hicp_manr?freq=M&unit=RCH_A&coicop=CP00&geo=FR', TimePeriod::month(2025, 10));

        self::assertNotNull($hicp);
        self::assertSame('https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/prc_hicp_manr?freq=M&unit=RCH_A&coicop=CP00&geo=FR&lang=EN&sinceTimePeriod=2025-10', $http->request()['url']);
        self::assertSame(['eurostat', 'prc_hicp_manr?freq=M&unit=RCH_A&coicop=CP00&geo=FR'], [$hicp->source, $hicp->id]);
        self::assertSame('HICP - monthly data (annual rate of change) - All-items HICP, France', $hicp->title);
        self::assertSame(Frequency::MONTHLY, $hicp->frequency);
        self::assertSame('Annual rate of change', $hicp->unit);
        self::assertSame('prc_hicp_manr', $hicp->dataset);
        self::assertSame(['freq' => 'M', 'unit' => 'RCH_A', 'coicop' => 'CP00', 'geo' => 'FR'], $hicp->dimensions);
        self::assertSame('2026-02-06', $hicp->updated?->format('Y-m-d'));
        self::assertStringStartsWith('Eurostat, prc_hicp_manr (https://doi.org/10.2908/PRC_HICP_MANR), accessed on ', $hicp->credit);
        self::assertSame('CC-BY-4.0', $hicp->licence);
        self::assertSame(['2025-10', '2025-11', '2025-12'], array_map(static fn ($o) => (string) $o->period, $hicp->observations));
        self::assertSame(0.7, $hicp->latest()?->value);
    }

    public function testProvisionalValuesAreFlagged(): void
    {
        $gdp = (new Eurostat($this->http('gdp-fr.json')->client))->series('nama_10_gdp?freq=A&unit=CP_MEUR&na_item=B1GQ&geo=FR', TimePeriod::year(2021));

        self::assertNotNull($gdp);
        self::assertSame('Current prices, million euro', $gdp->unit);
        self::assertSame(ObservationStatus::NORMAL, $gdp->observation('2023')?->status);
        $latest = $gdp->observation('2025');
        self::assertSame(2991055.9, $latest?->value);
        self::assertSame(ObservationStatus::PROVISIONAL, $latest->status);
        self::assertTrue($latest->isProvisional());
        self::assertSame(['p'], $latest->flags);
    }

    public function testLastAsksTheLatestPeriodsAndCombinesWithARangeAfterTheCall(): void
    {
        $http = $this->http('gdp-fr.json');
        $eurostat = new Eurostat($http->client);

        $eurostat->series('nama_10_gdp?freq=A&unit=CP_MEUR&na_item=B1GQ&geo=FR', last: 2);
        self::assertStringEndsWith('&lang=EN&lastTimePeriod=2', $http->request()['url']);
        $two = $eurostat->series('nama_10_gdp?freq=A&unit=CP_MEUR&na_item=B1GQ&geo=FR', TimePeriod::year(2021), TimePeriod::year(2025), 2);
        self::assertStringEndsWith('&sinceTimePeriod=2021&untilTimePeriod=2025', $http->request()['url'], 'one time parameter, but for since with until');
        self::assertSame(['2024', '2025'], array_map(static fn ($o) => (string) $o->period, $two->observations ?? []));
    }

    public function testAnIdThatSelectsSeveralSeriesIsRefused(): void
    {
        $this->expectException(OmnistateException::class);
        $this->expectExceptionMessage('selects several Eurostat series: name one code for geo.');
        (new Eurostat($this->http('gdp-fr-de.json')->client))->series('nama_10_gdp?freq=A&unit=CP_MEUR&na_item=B1GQ');
    }

    public function testNoResultsIsNull(): void
    {
        self::assertNull((new Eurostat($this->http('empty.json')->client))->series('nama_10_gdp?geo=ZZ&unit=CP_MEUR&na_item=B1GQ'), 'a code with no data: an empty dataset');
        self::assertNull((new Eurostat(MockHttp::routes(['~^GET ~' => [404, '@'.__DIR__.'/Fixtures/not-found.json']])->client))->series('nope_xx'));
        self::assertNull((new Eurostat(MockHttp::routes(['~^GET ~' => [400, '{"error":[{"status":400,"id":100,"label":"No results found"}]}']])->client))->series('nama_10_gdp?geo=ZZ'), 'fault code 100: no results');
    }

    public function testAQuestionEurostatRefusesIsAnError(): void
    {
        $this->expectException(OmnistateException::class);
        $this->expectExceptionMessage('Eurostat refused the question (400): \'geo\' parameter and \'geoLevel\' parameter cannot be set at the same time.');
        (new Eurostat(MockHttp::routes(['~^GET ~' => [400, '{"error":{"status":"400","id":140,"label":"\'geo\' parameter and \'geoLevel\' parameter cannot be set at the same time."}}']])->client))->series('nama_10_gdp?geo=FR');
    }

    public function testAnAsynchronousExtractionIsAskedAgainLater(): void
    {
        $this->expectException(UnavailableException::class);
        (new Eurostat(MockHttp::routes(['~^GET ~' => [200, '{"warning":{"status":413,"label":"ASYNCHRONOUS_RESPONSE. Your request will be treated asynchronously. Please try again later."}}']])->client))->series('nama_10_gdp');
    }

    public function testHistoryAndWhatIsNoSeriesAreNotSupported(): void
    {
        $eurostat = new Eurostat(new MockHttpClient());

        self::assertTrue($eurostat->supports('nama_10_gdp'));
        self::assertTrue($eurostat->supports('prc_hicp_manr?geo=FR&coicop=CP00'));
        self::assertFalse($eurostat->supports('prc_hicp_manr?geo=FR&lastTimePeriod=3'), 'time is series()\' arguments');
        self::assertFalse($eurostat->supports('HICP France'));
        $this->expectException(NotSupportedException::class);
        $eurostat->series('nama_10_gdp?geo=FR', history: true);
    }

    public function testTheCatalogueIsSearched(): void
    {
        $http = $this->http('toc.txt');
        $found = (new Eurostat($http->client))->datasets('consumer prices');

        self::assertSame('https://ec.europa.eu/eurostat/api/dissemination/catalogue/toc/txt?lang=en', $http->request()['url']);
        self::assertSame(['ei_cphi_m'], array_map(static fn ($d) => $d->id, $found), 'datasets and tables, each once, not the folders');
        self::assertSame('Harmonised index of consumer prices - monthly data', $found[0]->title);
        self::assertSame('2026-11-04', $found[0]->updated?->format('Y-m-d'));
        self::assertSame(['prc_hicp_midx', 'prc_hicp_manr'], array_map(static fn ($d) => $d->id, (new Eurostat($this->http('toc.txt')->client))->datasets('hicp monthly')));
    }

    public function testTheFacadeFindsItByItsRef(): void
    {
        $omnistate = new Omnistate(statistics: [new Eurostat($this->http('hicp-fr.json')->client)]);

        self::assertSame(0.7, $omnistate->series('eurostat:prc_hicp_manr?freq=M&unit=RCH_A&coicop=CP00&geo=FR')?->latest()?->value);
        self::assertSame('Source: Eurostat', $omnistate->policy('eurostat')->rights->attribution->text);
        self::assertNull($omnistate->policy('eurostat')->rateLimits, 'none stated: unknown');
    }

    private function http(string $fixture): MockHttp
    {
        return MockHttp::routes(['~^GET https://ec\.europa\.eu/eurostat/~' => [200, '@'.__DIR__.'/Fixtures/'.$fixture, ['content-type' => str_ends_with($fixture, '.txt') ? 'text/plain;charset=UTF-8' : 'application/json', 'date' => 'Tue, 10 Nov 2026 06:30:00 GMT']]]);
    }
}
