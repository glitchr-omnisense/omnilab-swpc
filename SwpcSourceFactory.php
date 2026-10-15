<?php

namespace Omnilab\Swpc;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * NOAA's Space Weather Prediction Center: the aurora at a point as a
 * series; the Kp index, the solar wind, the alerts and the scales outside
 * the contract - no key.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     throttle: 0                      # seconds between two calls: SWPC publishes no figure
 *     base_uri: 'https://services.swpc.noaa.gov/'
 */
final class SwpcSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'swpc',
            'omnilab.required_options' => [],
            'throttle' => 0.0,
            'base_uri' => SwpcSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new SwpcSource(
            $this->http ?? HttpClient::create(),
            (string) $c->get('base_uri'),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
