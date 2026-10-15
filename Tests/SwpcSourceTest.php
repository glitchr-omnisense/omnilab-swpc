<?php

namespace Omnilab\Swpc\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\BBox;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Source\Capability;
use Omnilab\Source\SeriesQuery;
use Omnilab\Swpc\SwpcSource;
use Omnilab\Swpc\SwpcSourceFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from services.swpc.noaa.gov on 2026-10-16 (about 22:00
 * UTC on the 15th): OVATION's nowcast (cut to four meridians, 40° N and
 * up), the planetary Kp index from 2026-10-08 to 10-18, the solar wind and
 * the magnetic field at L1 (cut to their last hour), the alerts and the
 * NOAA scales.
 */
final class SwpcSourceTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    private function source(?MockResponse $response = null): SwpcSource
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = $path;
            if ($response) {
                return $response;
            }

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.match ($path) {
                '/json/ovation_aurora_latest.json' => 'ovation.json',
                '/products/noaa-planetary-k-index-forecast.json' => 'kp-forecast.json',
                '/json/rtsw/rtsw_wind_1m.json' => 'rtsw-wind.json',
                '/json/rtsw/rtsw_mag_1m.json' => 'rtsw-mag.json',
                '/products/alerts.json' => 'alerts.json',
                '/products/noaa-scales.json' => 'scales.json',
            }));
        });
        $source = (new SwpcSourceFactory($http))->create([]);
        self::assertInstanceOf(SwpcSource::class, $source);

        return $source;
    }

    public function testCapabilities(): void
    {
        self::assertSame([Capability::SERIES, Capability::FORECAST], $this->source()->capabilities());
        self::assertSame('swpc', $this->source()->getName());
    }

    public function testTheAuroraOverAPoint(): void
    {
        $source = $this->source();
        [$aurora] = $source->series(new SeriesQuery(['aurora'], new Point(66.8, 8.3)));

        self::assertSame(['aurora', '%', true, 'swpc', null], [$aurora->variable, $aurora->unit, $aurora->forecast, $aurora->source, $aurora->step]);
        self::assertSame([16], $aurora->values());
        self::assertSame('2026-10-15T22:49:00+00:00', $aurora->times()[0]->format(\DATE_ATOM));
        self::assertSame('Aurora probability overhead (OVATION nowcast, observed 2026-10-15 21:47 UTC)', $aurora->label);

        // West of Greenwich: the grid's longitudes run 0 to 359.
        [$fairbanks] = $source->series(new SeriesQuery(['aurora'], new Point(64.84, -147.72)));
        self::assertSame([0], $fairbanks->values());

        // Only the latest nowcast is published.
        [$past] = $source->series(new SeriesQuery(['aurora'], new Point(66.8, 8.3), new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-02')));
        self::assertCount(0, $past);
        [$day] = $source->series(new SeriesQuery(['aurora'], new Point(66.8, 8.3), new \DateTimeImmutable('2026-10-15'), new \DateTimeImmutable('2026-10-15')));
        self::assertCount(1, $day);
    }

    public function testWhatIsNotAtAPlaceIsRefusedBeforeAnyCall(): void
    {
        $source = $this->source();
        foreach ([new SeriesQuery(['kp'], new Point(0, 0)), new SeriesQuery(['aurora'], new BBox(0, 60, 10, 70)), new SeriesQuery(['aurora'], Identifier::of('wmo', '07190'))] as $query) {
            try {
                $source->series($query);
                self::fail('Refused.');
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testThePlanetaryKpObservedThenPredicted(): void
    {
        $kp = $this->source()->kp();

        self::assertCount(81, $kp);
        self::assertSame('2026-10-08T00:00:00+00:00', $kp->times()[0]->format(\DATE_ATOM));
        self::assertSame('2026-10-16T00:00:00+00:00', $kp->forecastFrom->format(\DATE_ATOM));
        self::assertEquals(7, $kp->max());
        self::assertSame(1.67, $kp->values()[80]);
    }

    public function testTheSolarWindOfTheActiveSpacecraft(): void
    {
        [$speed, $bz] = $this->source()->solarWind('speed', 'bz');

        self::assertSame(['speed', 'km/s'], [$speed->variable, $speed->unit]);
        self::assertSame('Solar wind speed (SOLAR1)', $speed->label);
        self::assertCount(51, $speed);
        self::assertSame('2026-10-15T20:55:00+00:00', $speed->times()[0]->format(\DATE_ATOM));
        self::assertSame(456.7, $speed->values()[50]);
        self::assertSame(['bz', 'nT'], [$bz->variable, $bz->unit]);
        self::assertCount(59, $bz);
        self::assertSame(['/json/rtsw/rtsw_wind_1m.json', '/json/rtsw/rtsw_mag_1m.json'], $this->calls);
    }

    public function testTheSolarWindsVariablesAreChecked(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->source()->solarWind('pressure');
    }

    public function testTheAlertsLatestFirst(): void
    {
        $alerts = $this->source()->alerts();

        self::assertCount(79, $alerts);
        $first = $alerts[0];
        self::assertSame(['K04W', 'WARK04', 5435, 'EXTENDED WARNING', 'EXTENDED WARNING: Geomagnetic K-index of 4 expected'], [$first->product, $first->code, $first->serial, $first->kind, $first->title]);
        self::assertSame('2026-10-13T20:58:58+00:00', $first->issued->format(\DATE_ATOM));
        self::assertStringStartsWith('Space Weather Message Code: WARK04', $first->message);
    }

    public function testTheScales(): void
    {
        $scales = $this->source()->scales();

        self::assertCount(5, $scales);
        self::assertSame(['2026-10-14T21:59:00+00:00', 0, 0, 0], [$scales[0][0]->format(\DATE_ATOM), $scales[0][1], $scales[0][2], $scales[0][3]]);
        self::assertSame([null, null, 0, 20, 5, 1], \array_slice($scales[2], 1));
    }

    public function testADownServiceIsUnavailable(): void
    {
        $this->expectException(UnavailableException::class);
        $this->source(new MockResponse('', ['http_code' => 503]))->kp();
    }
}
