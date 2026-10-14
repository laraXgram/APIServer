<?php

declare(strict_types=1);

namespace LaraGram\Server\Stats;

/**
 * ServerCpuStat: the CPU usage of the server process, read every second.
 */
final class ServerCpuStat
{
    private const DURATIONS = [0, 5, 60, 60 * 60];

    private static ?self $instance = null;

    /** @var list<TimedStat<CpuStat>> */
    private array $stats = [];

    private function __construct()
    {
        $now = microtime(true);
        foreach (self::DURATIONS as $i => $duration) {
            // the "inf" window starts with TimedStat's default constructor
            $this->stats[] = new TimedStat(static fn (): CpuStat => new CpuStat(), (float) $duration, $i === 0 ? 0.0 : $now);
        }
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function update(float $now): void
    {
        $cpu = Stats::cpu();
        if ($cpu === null) {
            return;
        }
        foreach (self::instance()->stats as $stat) {
            $stat->addEvent(static fn (CpuStat $stat) => $stat->onEvent($cpu), $now);
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function asVector(float $now): array
    {
        $result = [];
        foreach ($this->stats as $i => $timed) {
            foreach ($timed->getStat($now)->asVector() as $j => [$key, $value]) {
                if ($i === 0) {
                    $result[$j] = [$key, $value];
                } else {
                    $result[$j][1] .= "\t".$value;
                }
            }
        }

        return $result;
    }
}
