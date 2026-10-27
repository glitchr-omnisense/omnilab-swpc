# omnilab/swpc

NOAA's Space Weather Prediction Center, through its public files
(`https://services.swpc.noaa.gov/`), for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab):
the aurora probability at a point (OVATION's nowcast) as a series; outside the contract, the
planetary Kp index, the solar wind at L1, the alerts and the NOAA scales. No key.

## Installation

```sh
composer require omnilab/swpc
```

```php
use Omnilab\Swpc\SwpcSourceFactory;

$swpc = (new SwpcSourceFactory($httpClient))->create(['mailto' => 'support@glitchr.io']);
```

With the Symfony bundle, the factory is registered as `swpc` when the bundle knows it; otherwise
register `SwpcSourceFactory` as a service.

## Credentials

None: SWPC's files are public, no key, no account.

## Options

The factory's options, with the defaults it applies (`SwpcSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: SWPC publishes no figure (its files change every minute to every three hours: keep what is read) |
| `base_uri` | `string` | `https://services.swpc.noaa.gov/` | no | SWPC's service (`json/...` and `products/...` are joined to it) |

## Capabilities

`capabilities()` answers `series`, `forecast`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | no | nothing here is an entity with an identifier |
| `search` | no |  |
| `series` | yes | the variable `aurora` only, at a `Point`: the nearest cell of OVATION's latest nowcast, one point at its forecast time, unit `%`; a period without that time gives a series without points |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | no |  |
| `forecast` | yes | the nowcast is a forecast (`forecast` true) |
| `historical` | no | only the latest files are published |
| `area` | no | a box is refused before any call |
| `station` | no | a station is refused before any call |
| `cloud_cover` | no |  |

Outside the contract, on `Omnilab\Swpc\SwpcSource` ([above](#its-shapes-and-what-stays-outside-the-contract)):
`kp(): Timeline`, `solarWind(string ...$variables): list<Timeline>` (`speed`, `density`,
`temperature`, `bt`, `bz`; another refused before any call), `alerts(): list<Alert>` and
`scales(): array`. An `Alert` holds `product`, `issued`, `code`, `serial`, `kind`, `title` and
`message`.

## Which API

SWPC's public files at `https://services.swpc.noaa.gov/` (JSON, no key, no query: each is the
latest the service keeps):

| File | For |
|---|---|
| `json/ovation_aurora_latest.json` | `series()`: OVATION's aurora nowcast, a 1° grid `[longitude 0-359, latitude, %]` at its forecast time |
| `products/noaa-planetary-k-index-forecast.json` | `kp()`: three-hourly Kp, `observed`, `estimated` then `predicted` |
| `json/rtsw/rtsw_wind_1m.json`, `json/rtsw/rtsw_mag_1m.json` | `solarWind()`: a day of one-minute solar wind and magnetic field, per spacecraft |
| `products/alerts.json` | `alerts()` |
| `products/noaa-scales.json` | `scales()` |

The older `products/solar-wind/plasma-7-day.json` and `mag-7-day.json` answer 404 (checked on
2026-10-15); `products/noaa-planetary-k-index.json` now gives objects (`time_tag`, `Kp`), not
arrays.

## Its shapes, and what stays outside the contract

The contract's `series()` takes a place and answers series at it. Of space weather, only the
aurora is at a place: `series()` serves the variable `aurora` at a `Point` - the nearest cell of
OVATION's grid (its latitude and longitude rounded to the degree, a west longitude read as 360
plus it: -147.72 is 212), one point at the nowcast's forecast time, `forecast` true, unit `%`. A period that does
not hold that time gives a series without points (only the latest nowcast is published). A box,
a station or another variable are refused before any call. `capabilities()`: `series`,
`forecast`.

The rest is not at a place on the Earth, so the source gives it by its own methods:

| Method | Answers |
|---|---|
| `kp()` | a `Timeline` `kp`: the planetary K-index every three hours from seven days back to three days ahead, in time order; `forecastFrom` the first predicted point |
| `solarWind(...$variables)` | a `Timeline` per variable - `speed` (km/s), `density` (cm⁻³), `temperature` (K), `bt` and `bz` (nT, GSM) - minute by minute over the last day, from the rows marked `active` (the spacecraft SWPC uses at the time: SOLAR1 on 2026-10-16; the ACE and IMAP rows inactive), its name in the label |
| `alerts()` | `Alert`s, the latest first: product id, issue time, message code (`WARK04`), serial number, kind (`ALERT`, `WARNING`, `WATCH`, `SUMMARY`, `EXTENDED WARNING`, `CANCEL WATCH`...), title (the line after the issue time), the message |
| `scales()` | rows `[time, R, S, G, R minor %, R major %, S %]` in the file's order: the day before, the latest observed, then today and the next two days (probabilities, and the G scale predicted) |

`Timeline` holds `variable`, `unit`, `points` (`[\DateTimeImmutable, value]`), `label`,
`forecastFrom`, with `values()`, `times()`, `min()`, `max()`, like a `Series` without a place.
Should the core one day carry series without a place, these would become series.

## The Collector

No path: nothing here is an entity with an identifier.

## What the files do not give here

The GOES X-ray, proton and electron fluxes, the solar regions and flares, the F10.7 flux, the
Boulder K-index, the one-minute Kp estimate, the 27-day and 45-day outlooks, the WSA-Enlil runs,
the D-region absorption, the aurora's 30-minute forecast images and the coronagraph images are not
read.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Model\Point;
use Omnilab\Source\SeriesQuery;
use Omnilab\Swpc\SwpcSourceFactory;
use Symfony\Component\HttpClient\HttpClient;

$swpc = (new SwpcSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org']);

[$aurora] = $swpc->series(new SeriesQuery(['aurora'], new Point(64.84, -147.72)));   // Fairbanks
foreach ($aurora as [$time, $value]) {
    echo 'aurora over Fairbanks at ', $time->format('Y-m-d H:i'), ' UTC: ', $value, ' ', $aurora->unit, "\n";
}

$kp = $swpc->kp();
printf("Kp: %d points, max %s, predicted from %s\n", count($kp->points), $kp->max(), $kp->forecastFrom?->format('Y-m-d H:i'));

[$speed] = $swpc->solarWind('speed');
printf("solar wind: %d minutes, %s to %s %s (%s)\n", count($speed->points), $speed->min(), $speed->max(), $speed->unit, $speed->label);

$alerts = $swpc->alerts();
printf("%d alerts, the latest: %s %s\n", count($alerts), $alerts[0]->code ?? '?', $alerts[0]->title ?? '');
```

```
aurora over Fairbanks at 2026-10-27 01:44 UTC: 0 %
Kp: 81 points, max 4.67, predicted from 2026-10-28 00:00
solar wind: 1018 minutes, 313.6 to 400.2 km/s (Solar wind speed (SOLAR1))
88 alerts, the latest: ALTEF3 CONTINUED ALERT: Electron 2MeV Integral Flux exceeded 1,000pfu
```

(as answered on 2026-10-27: five files read)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        swpc: { factory: swpc, options: { mailto: '%env(OMNILAB_MAILTO)%' } }
```

`kp()`, `solarWind()`, `alerts()` and `scales()` are methods of `SwpcSource`, not of the interface
the bundle injects: check the instance before calling them.

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

Before any call (`NotSupportedException`): a box or a station in `series()`, a variable other than
`aurora` (the message names `kp()`, `solarWind()`, `alerts()`, `scales()`), a solar wind variable
not served. A file the service no longer keeps is its 404: an empty answer.

## Verified, and not

Against the real files on 2026-10-16 (about 22:00 UTC on the 15th): the aurora over Fairbanks
(0 %, the nowcast of 22:49 UTC observed at 21:47) and over 67° N 8° E (16 %); the Kp index from
2026-10-08 (81 points, a maximum of 7 on 2026-10-10, G3, predicted from 2026-10-16 00:00 UTC);
a day of solar wind from SOLAR1 (1,142 minutes, 445 to 563 km/s; Bz -5.1 to 5.0 nT); 79 alerts,
the latest an extended warning of K 4 (WARK04, serial 5435); the scales (G0, R minor 20 %, major
5 %, S 1 %). The fixtures in `Tests/Fixtures/` are its answers, the nowcast cut to four
meridians north of 40°, the solar wind to its last hour.

Again on 2026-10-27: the aurora over Fairbanks (0 %), the Kp index (81 points), a day of solar
wind from SOLAR1 (1,018 minutes) and 88 alerts (the example above).

Not verified: the files' behaviour under heavy polling (no rate published, no refusal met).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by NOAA or its Space Weather Prediction Center. SWPC's products are in the U.S. public
domain; credit NOAA's Space Weather Prediction Center where they are shown, and do not poll faster
than the files change, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-swpc#terms-of-use) says. Whoever runs
the package is responsible for any use made of what it returns: see the README's
[Responsibility](https://github.com/glitchr-omnisense/omnilab-swpc#responsibility) section.
