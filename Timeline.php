<?php

namespace Omnilab\Swpc;

/**
 * A variable of space weather over time - the planetary Kp index, the
 * solar wind's speed at L1 - which the core's Series cannot hold, a Series
 * being at a place on the Earth. Points in time order, a value null where
 * none; $forecastFrom is the first predicted point's time (null when every
 * point is observed or estimated).
 *
 * @implements \IteratorAggregate<int, array{\DateTimeImmutable, int|float|null}>
 */
final readonly class Timeline implements \IteratorAggregate, \Countable
{
    /** @param list<array{\DateTimeImmutable, int|float|null}> $points */
    public function __construct(
        public string $variable,
        public ?string $unit,
        public array $points,
        public ?string $label = null,
        public ?\DateTimeImmutable $forecastFrom = null,
        public string $source = 'swpc',
    ) {
    }

    /** @return list<int|float|null> */
    public function values(): array
    {
        return array_map(static fn (array $point) => $point[1], $this->points);
    }

    /** @return list<\DateTimeImmutable> */
    public function times(): array
    {
        return array_map(static fn (array $point) => $point[0], $this->points);
    }

    public function max(): int|float|null
    {
        $values = array_filter($this->values(), static fn ($v) => null !== $v);

        return $values ? max($values) : null;
    }

    public function min(): int|float|null
    {
        $values = array_filter($this->values(), static fn ($v) => null !== $v);

        return $values ? min($values) : null;
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->points);
    }

    public function count(): int
    {
        return \count($this->points);
    }
}
