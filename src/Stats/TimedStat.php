<?php

declare(strict_types=1);

namespace LaraGram\Server\Stats;

/**
 * td::TimedStat: the events of the last $duration seconds, kept as a
 * current and a next window (a duration of 0 keeps every event).
 *
 * @template T of object
 */
final class TimedStat
{
    /** @var T */
    private object $current;

    private float $currentTimestamp;

    /** @var T */
    private object $next;

    private float $nextTimestamp;

    /**
     * @param \Closure(): T $factory
     */
    public function __construct(private readonly \Closure $factory, private readonly float $duration = 0.0, float $now = 0.0)
    {
        $this->current = ($this->factory)();
        $this->next = ($this->factory)();
        $this->currentTimestamp = $now - 1;
        $this->nextTimestamp = $now;
    }

    public function addEvent(callable $apply, float $now): void
    {
        $this->update($now);
        $apply($this->current);
        $apply($this->next);
    }

    /**
     * @return T
     */
    public function getStat(float $now): object
    {
        $this->update($now);

        return $this->current;
    }

    /**
     * @return array{0: T, 1: float} a copy of the stat and the duration it covers
     */
    public function statDuration(float $now): array
    {
        $this->update($now);

        return [clone $this->current, $now - $this->currentTimestamp];
    }

    private function update(float &$now): void
    {
        if ($now < $this->nextTimestamp) {
            $now = $this->nextTimestamp;
        }
        if ($this->duration == 0.0) {
            return;
        }
        if ($this->nextTimestamp + 2 * $this->duration < $now) {
            $this->current = ($this->factory)();
            $this->currentTimestamp = $now - $this->duration;
            $this->next = ($this->factory)();
            $this->nextTimestamp = $now;
        } elseif ($this->nextTimestamp + $this->duration < $now) {
            $this->current = $this->next;
            $this->currentTimestamp = $this->nextTimestamp;
            $this->next = ($this->factory)();
            $this->nextTimestamp = $now;
        }
    }
}
