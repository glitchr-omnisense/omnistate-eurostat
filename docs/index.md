---
title: omnistate/eurostat
---

# omnistate/eurostat

Eurostat's API Statistics (JSON-stat 2.0) as an Omnistate statistics source
(`Registry\StatisticsRegistryInterface`).

| Method | Request |
|---|---|
| `series($id, $from, $to, $last)` | `GET https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/<dataset>?<filters>&lang=EN&sinceTimePeriod=…&untilTimePeriod=…` or `&lastTimePeriod=…` |
| `datasets($query, $limit)` | `GET https://ec.europa.eu/eurostat/api/dissemination/catalogue/toc/txt?lang=en` (about 2 MB), searched here |

## Installation

```sh
composer require omnisense/omnistate omnistate/eurostat
```

## Credentials

None.

## Options

| Argument | Type | Default | |
|---|---|---|---|
| `http` | `HttpClientInterface` | required | the client to call with |
| `timeout` | float | `30` | seconds Eurostat has to answer |
| `language` | string | `EN` | `EN`, `FR` or `DE`: the labels' language |
| `userAgent` | `?string` | the package's | the User-Agent sent |
| `url` | string | `https://ec.europa.eu/eurostat/api/dissemination` | the API's base address |

## Ids

A dataset's code, then a code for each of its dimensions but time, as the API's filters:
`prc_hicp_manr?freq=M&unit=RCH_A&coicop=CP00&geo=FR`. The codes are those of Eurostat's data
browser (each dataset's page lists its dimensions). A dimension left out keeps all its codes:
when the id selects several series, `series()` throws an `OmnistateException` naming the
dimensions to fix. Time is `series()`' own arguments: an id with `time`, `sinceTimePeriod`,
`lastTimePeriod`, `lang`, `format` or `geoLevel` is not supported.

The API takes one time parameter, but for `sinceTimePeriod` with `untilTimePeriod`: `$last`
alone is `lastTimePeriod`; `$last` with `$from` or `$to` is applied after the answer.

## Behaviour

| Answer | Series / Observation |
|---|---|
| `label` and the codes' labels but freq and unit | `title` ("HICP - monthly data (annual rate of change) - All-items HICP, France") |
| the `freq` code | `frequency` |
| the `unit` code's label | `unit` |
| `updated` | `updated` |
| the codes, by dimension | `dimensions` |
| the dataset's DOI (10.2908/<CODE>) and the answer's date | `credit` |
| `time` | `period` |
| `value` | `value` (a period with neither value nor flag is left out) |
| `status` (`p`, `e`, `s`, `f`, `b`, `c`, `u`, `n`, `r`, `d`, `z`, one letter each) | `status`, `flags`; `r` is `revised`; no value and no `c` is missing |

Eurostat keeps no history of revised values: `history: true` is a `NotSupportedException`.

**Datasets.** The table of contents' datasets and tables (not its folders), each once, searched
for every word of the query in the code or title: `datasets('consumer prices')` gives
`ei_cphi_m`. `Dataset::$updated` is the "last update of data", `$url` the data browser's page.
The file is large: keep the answer (the facade's cache).

## Errors

| What happens | What it does |
|---|---|
| an unknown dataset (404), "no results" (fault code 100), a code with no data (an empty dataset) | `null` |
| an id it does not read | `NotSupportedException`, no request |
| another 400 (a syntax or semantic error) | `OmnistateException` with Eurostat's label |
| the asynchronous answer (`warning`, status 413) | `UnavailableException`, `retryAfter` 60 |
| 401, 403, 5xx, no answer, a body that is not JSON-stat | `UnavailableException` |
| 429 | `UnavailableException` with its Retry-After, `status` 429 |

## Usage policy

`policy.json`, read on 2026-11-10 from the copyright notice and the API guide: limits unknown (none
stated), commercial use, storage, caching and redistribution allowed (CC BY 4.0), the source
acknowledged ("Source: Eurostat"; each series' `credit` with the DOI and the date).

## Verified, and not

Recorded on 2026-11-10 (Tests/Fixtures): the HICP's annual rate for France from 2025-10, GDP for
France from 2021 (provisional values) and for France and Germany (two series), an unknown dataset
(404), an unknown code (an empty dataset), lines of the table of contents. Not verified: the
asynchronous answer's HTTP status (its JSON is the guide's), a 429.

## Responsibility

An independent, unofficial client, not affiliated with Eurostat. Whoever runs it complies with
Eurostat's copyright notice: see the README's Responsibility section.
