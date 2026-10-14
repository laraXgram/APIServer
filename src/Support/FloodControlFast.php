<?php

declare(strict_types=1);

namespace LaraGram\Server\Support;

/**
 * Leaky-bucket flood control: a port of td::FloodControlFast.
 *
 * Every limit is a bucket of `count` events per `duration` seconds; an event
 * that does not fit moves the wakeup time forward.
 */
final class FloodControlFast
{
    /** @var list<array{capacity: float, speed: float, volume: float, volume_at: float, wakeup_at: float}> */
    private array $buckets = [];

    private float $wakeupAt = 0.0;

    public function addLimit(float $duration, float $count): self
    {
        $this->buckets[] = [
            'capacity' => $count - 1,
            'speed' => $count / $duration,
            'volume' => $count - 1,
            'volume_at' => 0.0,
            'wakeup_at' => 0.0,
        ];

        return $this;
    }

    public function addEvent(float $now): void
    {
        foreach ($this->buckets as &$bucket) {
            $bucket['volume'] = min($bucket['volume'] + ($now - $bucket['volume_at']) * $bucket['speed'], $bucket['capacity']);
            $bucket['volume_at'] = $now;

            if ($bucket['volume'] >= 1.0) {
                $bucket['volume'] -= 1.0;
            } else {
                $size = 1.0 - $bucket['volume'];
                $bucket['volume'] = 0.0;
                $bucket['wakeup_at'] = $bucket['volume_at'] + $size / $bucket['speed'];
                $bucket['volume_at'] = $bucket['wakeup_at'];
            }

            $this->wakeupAt = max($this->wakeupAt, $bucket['wakeup_at']);
        }
    }

    public function getWakeupAt(): float
    {
        return $this->wakeupAt;
    }

    public function clearEvents(): void
    {
        foreach ($this->buckets as &$bucket) {
            $bucket['volume'] = $bucket['capacity'];
            $bucket['volume_at'] = 0.0;
            $bucket['wakeup_at'] = 0.0;
        }

        $this->wakeupAt = 0.0;
    }
}
