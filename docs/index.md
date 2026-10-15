# omnilab/swpc

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
minus it), one point at the nowcast's forecast time, `forecast` true, unit `%`. A period that does
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

## Verified, and not

Against the real files on 2026-10-16 (about 22:00 UTC on the 15th): the aurora over Fairbanks
(0 %, the nowcast of 22:49 UTC observed at 21:47) and over 67° N 8° E (16 %); the Kp index from
2026-10-08 (81 points, a maximum of 7 on 2026-10-10, G3, predicted from 2026-10-16 00:00 UTC);
a day of solar wind from SOLAR1 (1,142 minutes, 445 to 563 km/s; Bz -5.1 to 5.0 nT); 79 alerts,
the latest an extended warning of K 4 (WARK04, serial 5435); the scales (G0, R minor 20 %, major
5 %, S 1 %). The fixtures in `Tests/Fixtures/` are its answers, the nowcast cut to four
meridians north of 40°, the solar wind to its last hour.

Not verified: the files' behaviour under heavy polling (no rate published, no refusal met).
