# omnilab/swpc

NOAA's Space Weather Prediction Center for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab):
the probability of seeing an aurora overhead at a point, from OVATION's nowcast, as a series - the
one thing of space weather that is at a place on the Earth - and, outside the contract, the
planetary Kp index observed and predicted, the solar wind at L1 (speed, density, temperature, the
magnetic field's strength and Bz), the alerts, warnings and watches SWPC issues, and the NOAA
scales. Through SWPC's public JSON files, no key.

```php
$swpc = (new SwpcSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
[$aurora] = $swpc->series(new SeriesQuery(['aurora'], new Point(64.84, -147.72)));
$aurora->values();                   // [0] (%, OVATION's latest nowcast over Fairbanks, a forecast)

$kp = $swpc->kp();                   // a Timeline, not a Series: the Kp index is the whole Earth's
$kp->max();                          // 7 (2026-10-10, G3)
$kp->forecastFrom;                   // 2026-10-16 00:00 UTC: the predicted points from there

[$speed, $bz] = $swpc->solarWind('speed', 'bz');   // a day, minute by minute, km/s and nT
$swpc->alerts()[0]->title;           // EXTENDED WARNING: Geomagnetic K-index of 4 expected
$swpc->scales();                     // R, S, G now; the probabilities of the next three days
```

```yaml
omnilab:
    sources:
        swpc:
            factory: swpc
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
```

**What stays outside the contract, and why**: a core `Series` is at a place on the Earth (a
`Point`, a `BBox` or a station) and a `SeriesQuery` names one. The Kp index, the solar wind at the
L1 point, the alerts and the scales are at none, so they are not series of the contract: the
source gives them by its own methods (`kp()`, `solarWind()`, `alerts()`, `scales()`), as
`Timeline` and `Alert` objects of this package. Only the aurora, a probability over a point,
answers `series()`.

See [docs/](docs/index.md) for what is read, how it maps, and what the files do not give here.

## Terms of use

SWPC's products are NOAA's, in the U.S. public domain; credit NOAA's Space Weather Prediction
Center where you show them. The files are the latest the service keeps (the Kp index of the last
week and the next three days, a day of solar wind, the alerts of about two weeks, the last aurora
nowcast); SWPC publishes no rate: the source reads a file once per call, and a caller should keep
what it reads rather than poll faster than the files change (a minute for the solar wind, five
for the aurora, three hours for the Kp index).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by NOAA's Space Weather Prediction Center. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
