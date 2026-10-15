<?php

namespace Omnilab\Swpc;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Point;
use Omnilab\Model\Series;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\SeriesQuery;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * NOAA's Space Weather Prediction Center, through its public JSON files
 * (services.swpc.noaa.gov): the probability of seeing an aurora overhead
 * at a point (OVATION's nowcast) as a series - the one thing of space
 * weather that is at a place on the Earth - and, outside the contract
 * (a core Series is at a place), the planetary Kp index observed and
 * predicted, the solar wind at L1 (speed, density, temperature, the
 * magnetic field's strength and Bz), the alerts, warnings and watches, and
 * the NOAA scales of today.
 *
 * No key. Each file is the service's latest: the Kp index of the last
 * seven days and the next three, a day of solar wind, the alerts of about
 * two weeks, the last aurora nowcast.
 */
final class SwpcSource extends HttpSource
{
    public const BASE_URI = 'https://services.swpc.noaa.gov/';

    /** The solar wind's variables: the file, its field, the unit, the label. */
    public const SOLAR_WIND = [
        'speed' => ['json/rtsw/rtsw_wind_1m.json', 'proton_speed', 'km/s', 'Solar wind speed'],
        'density' => ['json/rtsw/rtsw_wind_1m.json', 'proton_density', 'cm⁻³', 'Solar wind proton density'],
        'temperature' => ['json/rtsw/rtsw_wind_1m.json', 'proton_temperature', 'K', 'Solar wind proton temperature'],
        'bt' => ['json/rtsw/rtsw_mag_1m.json', 'bt', 'nT', 'Interplanetary magnetic field strength (Bt)'],
        'bz' => ['json/rtsw/rtsw_mag_1m.json', 'bz_gsm', 'nT', 'Interplanetary magnetic field Bz (GSM)'],
    ];

    public function __construct(HttpClientInterface $http, string $baseUri = self::BASE_URI, array $headers = [], float $throttle = 0.0)
    {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'swpc';
    }

    public function capabilities(): array
    {
        return [Capability::SERIES, Capability::FORECAST];
    }

    /**
     * "aurora": the probability, in percent, of an aurora seen overhead at
     * the point, from OVATION's latest nowcast (one point, at its forecast
     * time, about half an hour to an hour ahead; the nearest cell of its
     * one-degree grid). Only the latest is published: a period that does
     * not hold its time answers a series without points.
     */
    public function series(SeriesQuery $query): array
    {
        $this->station($query);
        if (!$query->place instanceof Point) {
            throw NotSupportedException::operation('swpc', 'serve the aurora over an area (a Point)');
        }
        foreach ($query->variables as $variable) {
            if ('aurora' !== $variable) {
                throw NotSupportedException::operation('swpc', \sprintf('serve "%s" at a place (it serves "aurora"; the Kp index, the solar wind, the alerts and the scales are not at a place: kp(), solarWind(), alerts(), scales())', $variable));
            }
        }
        $data = $this->getJson('json/ovation_aurora_latest.json') ?? [];
        $time = self::time($data['Forecast Time'] ?? null);
        $longitude = (int) round($query->place->longitude < 0 ? $query->place->longitude + 360 : $query->place->longitude) % 360;
        $latitude = (int) round($query->place->latitude);
        $value = null;
        foreach ($data['coordinates'] ?? [] as $cell) {
            if (\is_array($cell) && ($cell[0] ?? null) === $longitude && ($cell[1] ?? null) === $latitude && is_numeric($cell[2] ?? null)) {
                $value = 0 + $cell[2];
                break;
            }
        }
        $points = [];
        if (null !== $time && (null === $query->from || $time >= $query->from) && (null === $query->to || $time <= self::end($query->to))) {
            $points[] = [$time, $value];
        }

        return array_map(static fn () => new Series('aurora', '%', $query->place, $points, null, null, 'Aurora probability overhead (OVATION nowcast'.(null === ($observed = self::time($data['Observation Time'] ?? null)) ? '' : ', observed '.$observed->format('Y-m-d H:i').' UTC').')', true, 'swpc'), $query->variables);
    }

    /**
     * The planetary Kp index, every three hours, from seven days back to
     * three days ahead: the observed and estimated values, then the
     * predicted ones (from forecastFrom). Outside the contract: the Kp
     * index is the whole Earth's.
     */
    public function kp(): Timeline
    {
        $points = [];
        $forecastFrom = null;
        foreach ($this->getJson('products/noaa-planetary-k-index-forecast.json') ?? [] as $row) {
            $time = \is_array($row) ? self::time(isset($row['time_tag']) ? $row['time_tag'].'Z' : null) : null;
            if (null === $time) {
                continue;
            }
            $points[] = [$time, is_numeric($row['kp'] ?? null) ? 0 + $row['kp'] : null];
            if ('predicted' === ($row['observed'] ?? null)) {
                $forecastFrom ??= $time;
            }
        }
        usort($points, static fn (array $a, array $b) => $a[0] <=> $b[0]);

        return new Timeline('kp', null, $points, 'Planetary K-index (Kp), three-hourly', $forecastFrom);
    }

    /**
     * The solar wind at L1, minute by minute over the last day, from the
     * spacecraft SWPC uses at the time (its "active" rows): one Timeline per
     * variable asked (SOLAR_WIND: speed, density, temperature, bt, bz).
     * Outside the contract: the solar wind is not at a place on the Earth.
     *
     * @return list<Timeline>
     */
    public function solarWind(string ...$variables): array
    {
        $variables = $variables ?: ['speed', 'density', 'bz'];
        foreach ($variables as $variable) {
            if (!isset(self::SOLAR_WIND[$variable])) {
                throw NotSupportedException::operation('swpc', \sprintf('serve the solar wind\'s "%s" (it serves %s)', $variable, implode(', ', array_keys(self::SOLAR_WIND))));
            }
        }
        $files = [];
        $timelines = [];
        foreach ($variables as $variable) {
            [$file, $field, $unit, $label] = self::SOLAR_WIND[$variable];
            $files[$file] ??= $this->getJson($file) ?? [];
            $points = [];
            $spacecraft = [];
            foreach ($files[$file] as $row) {
                if (!\is_array($row) || true !== ($row['active'] ?? null) || null === ($time = self::time(isset($row['time_tag']) ? $row['time_tag'].'Z' : null))) {
                    continue;
                }
                $points[$time->getTimestamp()] = [$time, is_numeric($row[$field] ?? null) ? 0 + $row[$field] : null];
                $spacecraft[(string) ($row['source'] ?? '')] = true;
            }
            ksort($points);
            $timelines[] = new Timeline($variable, $unit, array_values($points), $label.(array_filter(array_keys($spacecraft)) ? ' ('.implode(', ', array_filter(array_keys($spacecraft))).')' : ''));
        }

        return $timelines;
    }

    /**
     * The alerts, warnings, watches and summaries SWPC issued, the latest
     * first.
     *
     * @return list<Alert>
     */
    public function alerts(): array
    {
        $alerts = [];
        foreach ($this->getJson('products/alerts.json') ?? [] as $row) {
            $issued = \is_array($row) && \is_string($row['issue_datetime'] ?? null) ? \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', $row['issue_datetime'], new \DateTimeZone('UTC')) : false;
            if (false === $issued || !\is_string($row['message'] ?? null)) {
                continue;
            }
            $message = str_replace("\r", '', $row['message']);
            $title = preg_match('~^Issue Time:[^\n]*\n+([^\n]+)~m', $message, $m) ? trim($m[1]) : null;
            $alerts[] = new Alert(
                (string) ($row['product_id'] ?? ''),
                $issued,
                preg_match('~Space Weather Message Code:\s*(\S+)~', $message, $m) ? $m[1] : null,
                preg_match('~Serial Number:\s*(\d+)~', $message, $m) ? (int) $m[1] : null,
                null !== $title && preg_match('~^([A-Z][A-Z ]+?):~', $title, $m) ? $m[1] : null,
                $title,
                trim($message),
            );
        }
        usort($alerts, static fn (Alert $a, Alert $b) => $b->issued <=> $a->issued);

        return $alerts;
    }

    /**
     * The NOAA space weather scales - R (radio blackouts), S (solar
     * radiation storms), G (geomagnetic storms), each 0 to 5 - as SWPC's
     * file gives them, in its order: the day before and the latest
     * observed (their scales), then today and the next two days (the
     * probabilities of R minor and major and of S, in percent, and the G
     * scale predicted). A row: [time, R, S, G, R minor %, R major %, S %].
     *
     * @return list<array{\DateTimeImmutable, int|null, int|null, int|null, int|null, int|null, int|null}>
     */
    public function scales(): array
    {
        $rows = [];
        foreach ($this->getJson('products/noaa-scales.json') ?? [] as $key => $row) {
            if (!is_numeric($key) || !\is_array($row) || null === ($time = self::time(($row['DateStamp'] ?? '').'T'.($row['TimeStamp'] ?? '00:00:00').'Z'))) {
                continue;
            }
            $int = static fn (mixed $v): ?int => is_numeric($v) ? (int) $v : null;
            $rows[(int) $key] = [$time, $int($row['R']['Scale'] ?? null), $int($row['S']['Scale'] ?? null), $int($row['G']['Scale'] ?? null), $int($row['R']['MinorProb'] ?? null), $int($row['R']['MajorProb'] ?? null), $int($row['S']['Prob'] ?? null)];
        }
        ksort($rows);

        return array_values($rows);
    }

    /** A period's end: a date without a time is its whole day. */
    private static function end(\DateTimeInterface $to): \DateTimeImmutable
    {
        $end = \DateTimeImmutable::createFromInterface($to);

        return '00:00:00' === $end->format('H:i:s') ? $end->modify('+1 day -1 second') : $end;
    }
}
