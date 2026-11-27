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
     * td::cpu_stat: the process user and system time and the total time of
     * the machine, in microseconds.
     *
     * getrusage() is a single system call; td::cpu_stat's /proc/self/stat and
     * /proc/stat reads went through Swoole's file I/O threads and were most of
     * what an idle server spent. The total is the elapsed time of every CPU,
     * which is what the ticks of /proc/stat add up to.
     *
     * @return array{total: int, user: int, system: int}|null
     */
    public static function cpu(): ?array
    {
        $usage = getrusage();
        if ($usage === false || !isset($usage['ru_utime.tv_sec'], $usage['ru_stime.tv_sec'])) {
            return null;
        }

        return [
            'total' => intdiv(hrtime(true), 1000) * self::cpuCount(),
            'user' => $usage['ru_utime.tv_sec'] * 1_000_000 + $usage['ru_utime.tv_usec'],
            'system' => $usage['ru_stime.tv_sec'] * 1_000_000 + $usage['ru_stime.tv_usec'],
        ];
    }

    public static function cpuCount(): int
    {
        static $count = null;

        return $count ??= function_exists('swoole_cpu_num') ? max(1, swoole_cpu_num()) : 1;
    }
}
