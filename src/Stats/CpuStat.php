<?php

declare(strict_types=1);

namespace LaraGram\Server\Stats;

/**
 * CpuStat: the first and the last CPU reading of a time window.
 */
final class CpuStat
{
    private int $count = 0;

    /** @var array{total: int, user: int, system: int} */
    private array $first = ['total' => 0, 'user' => 0, 'system' => 0];

    /** @var array{total: int, user: int, system: int} */
    private array $last = ['total' => 0, 'user' => 0, 'system' => 0];

    /**
     * @param array{total: int, user: int, system: int} $event
     */
    public function onEvent(array $event): void
    {
        if ($this->count === 0) {
            $this->first = $event;
            $this->count = 1;
        } else {
            $this->count = 2;
            $this->last = $event;
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function asVector(): array
    {
        if ($this->count < 2 || $this->first['total'] >= $this->last['total']) {
            return [['total_cpu', 'UNKNOWN'], ['user_cpu', 'UNKNOWN'], ['system_cpu', 'UNKNOWN']];
        }
        $total = $this->last['total'] - $this->first['total'];
        $user = $this->last['user'] - $this->first['user'];
        $system = $this->last['system'] - $this->first['system'];
        $percentage = static fn (int $ticks): string => Stats::double($ticks / $total * 100.0 * Stats::cpuCount()).'%';

        return [['total_cpu', $percentage($user + $system)], ['user_cpu', $percentage($user)], ['system_cpu', $percentage($system)]];
    }
}
