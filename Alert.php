<?php

namespace Omnilab\Swpc;

/**
 * A message of the Space Weather Prediction Center: an alert, a warning, a
 * watch or a summary, as issued.
 */
final readonly class Alert
{
    /**
     * @param string      $product the product id of the list (K04W, XM5S...)
     * @param string|null $code    the message's code (WARK04, SUMXM5...)
     * @param string|null $kind    ALERT, WARNING, WATCH, SUMMARY, EXTENDED WARNING, CANCEL WATCH...
     * @param string|null $title   its first line after the issue time: "WARNING: Geomagnetic K-index of 4 expected"
     */
    public function __construct(
        public string $product,
        public \DateTimeImmutable $issued,
        public ?string $code,
        public ?int $serial,
        public ?string $kind,
        public ?string $title,
        public string $message,
    ) {
    }
}
