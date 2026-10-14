<?php

declare(strict_types=1);

namespace LaraGram\Server\Stats;

/**
 * Formatting and process readings used by the statistics page
 * (td::StringBuilder, td::format::as_size, td::mem_stat, td::cpu_stat).
 */
final class Stats
{
    /**
     * A double written by td::StringBuilder: fixed notation with 6 digits.
     */
    public static function double(float $value): string
    {
        return sprintf('%.6F', $value);
    }

    /**
     * td::format::as_size.
     */
    public static function size(int $size): string
    {
        $units = [['B', 1], ['KB', 1 << 10], ['MB', 1 << 20], ['GB', 1 << 30]];
        $i = 0;
        while ($i + 1 < count($units) && $size >= 100000 * $units[$i][1]) {
            $i++;
        }

        return intdiv($size, $units[$i][1]).$units[$i][0];
    }

    /**
     * td::mem_stat on Linux: resident and virtual sizes and their peaks, in bytes.
     *
     * @return array{rss: int, vm: int, rss_peak: int, vm_peak: int}|null
     */
    public static function memory(): ?array
    {
        $status = @file_get_contents('/proc/self/status');
        if ($status === false) {
            return null;
        }
        $result = ['rss' => 0, 'vm' => 0, 'rss_peak' => 0, 'vm_peak' => 0];
        $fields = ['VmRSS' => 'rss', 'VmSize' => 'vm', 'VmHWM' => 'rss_peak', 'VmPeak' => 'vm_peak'];
        foreach (explode("\n", $status) as $line) {
            if (preg_match('/^([A-Za-z]+):\s*(\d+)/', $line, $match) === 1 && isset($fields[$match[1]])) {
                $result[$fields[$match[1]]] = (int) $match[2] * 1024;
            }
        }

        return $result;
    }

    /**
     * td::cpu_stat on Linux: the process user and system ticks and the total ticks of the machine.
     *
     * @return array{total: int, user: int, system: int}|null
     */
    public static function cpu(): ?array
    {
        $self = @file_get_contents('/proc/self/stat');
        $total = @file_get_contents('/proc/stat');
        if ($self === false || $total === false) {
            return null;
        }
        $fields = explode(' ', $self);
        if (count($fields) < 16) {
            return null;
        }
        $line = strstr($total, "\n", true);
        $sum = 0;
        preg_match_all('/\d+/', $line === false ? $total : $line, $numbers);
        foreach ($numbers[0] as $number) {
            $sum += (int) $number;
        }

        return ['total' => $sum, 'user' => (int) $fields[13], 'system' => (int) $fields[14]];
    }

    public static function cpuCount(): int
    {
        return function_exists('swoole_cpu_num') ? max(1, swoole_cpu_num()) : 1;
    }
}
