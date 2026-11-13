# omnistate/eurostat

European statistics from [Eurostat](https://ec.europa.eu/eurostat)'s dissemination API (API
Statistics, JSON-stat 2.0), for [omnisense/omnistate](../core): a dataset's series by its filters,
each observation's flags (provisional, estimated, a break...), the catalogue searched. Open, no key.

```php
$eurostat = new Eurostat($httpClient);
$hicp = $eurostat->series('prc_hicp_manr?freq=M&unit=RCH_A&coicop=CP00&geo=FR', last: 3);
$hicp->latest()->value;   // 0.7 (2025-12, annual rate of change)
$hicp->credit;            // "Eurostat, prc_hicp_manr (https://doi.org/10.2908/PRC_HICP_MANR), accessed on 2026-11-10"
```

No framework needed: the package requires `omnisense/omnistate`, `omnisense/contracts` and
`symfony/http-client`.

## Install

```sh
composer require omnistate/eurostat
```

It brings the family's core, `omnisense/omnistate`, and `omnisense/contracts` with it. No account and no key: Eurostat's API is open ([`policy.json`](policy.json)).

## Usage policy

`policy.json`, read on 2026-11-10: no limit stated (unknown), reuse allowed for commercial and
non-commercial purposes under CC BY 4.0 with the source acknowledged and changes indicated.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Eurostat or the European Commission. It reaches their service on behalf of whoever
runs it, and it does not redistribute their data beyond the short excerpts recorded as test
fixtures. Its MIT licence covers this package's code only and grants no right to the service or
to its data.

Whoever installs and runs this package is responsible for complying with [Eurostat's copyright
notice](https://ec.europa.eu/eurostat/help/copyright-notice), for acknowledging the source, and
for any use made of what it returns. The authors publish it free of charge, make no commercial use
of it, and accept no liability for how it is used (see [LICENSE](LICENSE)).

## Documentation

[docs/](docs/index.md): the requests, the ids, the mapping, errors, verified and not.

## License

MIT.
