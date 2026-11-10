<?php

namespace Omnistate\Eurostat\Tests;

use Omnistate\Eurostat\Eurostat;
use Omnistate\Testing\RegistryContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Omnistate's conformance suite, on Eurostat's answers recorded on 2026-11-10 (Tests/Fixtures). */
final class EurostatContractTest extends TestCase
{
    use RegistryContractTests;

    protected function registry(HttpClientInterface $http): object
    {
        return new Eurostat($http);
    }

    protected function recordedAnswers(): array
    {
        $file = static fn (string $file, string $type) => static fn () => new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$file), ['response_headers' => ['content-type' => $type]]);

        return [
            'GET /eurostat/api/dissemination/statistics/1.0/data/prc_hicp_manr' => $file('hicp-fr.json', 'application/json'),
            'GET /eurostat/api/dissemination/catalogue/toc/txt' => $file('toc.txt', 'text/plain;charset=UTF-8'),
        ];
    }

    protected function seriesIdentifier(): string
    {
        return 'prc_hicp_manr?freq=M&unit=RCH_A&coicop=CP00&geo=FR';
    }

    protected function datasetQuery(): string
    {
        return 'consumer prices';
    }
}
