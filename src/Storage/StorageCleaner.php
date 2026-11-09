<?php

declare(strict_types=1);

namespace LaraGram\Server\Storage;

/**
 * Deletes downloaded files according to a {@see RetentionPolicy}: the order
 * of td::FileGcWorker. Files past the age limit go first, then the least
 * recently used ones until the file count and the total size fit.
 *
 * Recent files (the immunity delay) count towards the limits but are never
 * deleted. Only files the server itself created are looked at: known type
 * directories inside a bot's directory, and names of the server's own pattern.
 */
final class StorageCleaner
{
    private const YIELD_EVERY = 200;

    private const BOT_DIRECTORY = '/^(\d+)[:~][A-Za-z0-9_-]+(?:[:~]T)?$/';
    private const DOWNLOADED_FILE = '/^file_\d+(?:\.[^\/]+)?$/';
    private const TEMP_FILE = '/^[0-9a-f]{16}$/';

    private const FILES_DB = 'files_db';

    /** A file in temp/ this fresh may be a download in progress, whatever the policy says. */
    private const TEMP_FILE_MIN_IMMUNITY = 300;

    private int $operations = 0;

    /**
     * @param string $filesDirectory the --files-dir directory, with a trailing slash
     * @param string $workingDirectory the --dir directory, with a trailing slash
     * @param (\Closure(int): bool)|null $botFilter whether this server serves a bot (--filter)
     * @param (\Closure(): void)|null $yield lets other work run during a long clean-up
     */
    public function __construct(
        private readonly string $filesDirectory,
        private readonly string $workingDirectory,
        private readonly ?\Closure $botFilter = null,
        private readonly ?\Closure $yield = null,
    ) {
    }

    /**
     * @param list<string>|null $botDirectories only these bots
     */
    public function run(RetentionPolicy $policy, bool $dryRun = false, ?array $botDirectories = null): StorageReport
    {
        $startedAt = microtime(true);
        $report = new StorageReport();
        $now = time();

        if ($policy->scope === RetentionPolicy::SCOPE_SERVER) {
            $files = [];
            $directories = $this->botDirectories($policy, $botDirectories);
            foreach ($directories as $directory) {
                $report->bots[$directory] = ['files' => 0, 'bytes' => 0];
                foreach ($this->scan($directory, $policy, $report) as $file) {
                    $files[] = $file;
                }
            }
            $this->apply($files, $policy, $report, $dryRun, $now);
            $this->forgetRemoved($directories, $report, $dryRun);
        } else {
            foreach ($this->botDirectories($policy, $botDirectories) as $directory) {
                $report->bots[$directory] = ['files' => 0, 'bytes' => 0];
                $this->apply($this->scan($directory, $policy, $report), $policy, $report, $dryRun, $now);
                $this->forgetRemoved([$directory], $report, $dryRun);
            }
        }

        $report->duration = microtime(true) - $startedAt;

        return $report;
    }

    /**
     * @param list<string>|null $only
     * @return list<string>
     */
    private function botDirectories(RetentionPolicy $policy, ?array $only): array
    {
        $directories = [];
        foreach (@scandir($this->filesDirectory) ?: [] as $name) {
            if (preg_match(self::BOT_DIRECTORY, $name, $matches) !== 1) {
                continue;
            }
            $botId = (int) $matches[1];
            if (($only !== null && !in_array($name, $only, true)) || !$policy->coversBot($botId)
                || ($this->botFilter !== null && !($this->botFilter)($botId))) {
                continue;
            }
            $path = $this->filesDirectory.$name;
            if (is_dir($path) && !is_link($path)) {
                $directories[] = $name;
            }
        }

        return $directories;
    }

    /**
     * The files of one bot the policy covers.
     *
     * @return list<array{bot: string, path: string, type: string, size: int, mtime: int, last_use: int}>
     */
    private function scan(string $bot, RetentionPolicy $policy, StorageReport $report): array
    {
        $files = [];
        foreach (RetentionPolicy::TYPES as $type) {
            $directory = $this->filesDirectory.$bot.'/'.$type.'/';
            if (is_link(rtrim($directory, '/')) || !is_dir($directory)) {
                continue;
            }

            $pattern = $type === 'temp' ? self::TEMP_FILE : self::DOWNLOADED_FILE;
            foreach (@scandir($directory) ?: [] as $name) {
                if (preg_match($pattern, $name) !== 1) {
                    continue;
                }
                $path = $directory.$name;
                $stat = @lstat($path);
                if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
                    continue;
                }
                $this->pause();

                $report->scannedFiles++;
                $report->scannedBytes += $stat['size'];

                $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
                if (!$policy->coversFile($type, $extension, $stat['size'])) {
                    continue;
                }

                $files[] = [
                    'bot' => $bot,
                    'path' => $path,
                    'type' => $type,
                    'size' => $stat['size'],
                    'mtime' => $stat['mtime'],
                    'last_use' => max($stat['atime'], $stat['mtime']),
                ];
                $report->bots[$bot]['files']++;
                $report->bots[$bot]['bytes'] += $stat['size'];
            }
        }

        return $files;
    }

    /**
     * FileGcWorker::run_gc.
     *
     * @param list<array{bot: string, path: string, type: string, size: int, mtime: int, last_use: int}> $files
     */
    private function apply(array $files, RetentionPolicy $policy, StorageReport $report, bool $dryRun, int $now): void
    {
        $count = count($files);
        $size = array_sum(array_column($files, 'size'));

        $removable = [];
        foreach ($files as $file) {
            $immunity = $file['type'] === 'temp' ? max($policy->immunity, self::TEMP_FILE_MIN_IMMUNITY) : $policy->immunity;
            if ($file['mtime'] > $now - $immunity) {
                $report->immuneFiles++;
                continue;
            }
            if ($policy->all) {
                $this->remove($file, 'request', $report, $dryRun);
                $count--;
                $size -= $file['size'];
                continue;
            }

            $age = $now - ($policy->ageFrom === RetentionPolicy::AGE_FROM_DOWNLOAD ? $file['mtime'] : $file['last_use']);
            if ($policy->maxAge !== null && $age > $policy->maxAge) {
                $this->remove($file, 'age', $report, $dryRun);
                $count--;
                $size -= $file['size'];
                continue;
            }
            $removable[] = $file;
        }

        usort($removable, static fn (array $a, array $b): int => $a['last_use'] <=> $b['last_use']);

        $removeCount = $policy->maxFiles === null ? 0 : max(0, $count - $policy->maxFiles);
        $removeSize = $policy->maxSize === null ? 0 : $size - $policy->maxSize;

        foreach ($removable as $file) {
            if ($removeCount <= 0 && $removeSize <= 0) {
                break;
            }
            $this->remove($file, $removeCount > 0 ? 'count' : 'size', $report, $dryRun);
            $removeCount--;
            $removeSize -= $file['size'];
        }
    }

    /**
     * @param array{bot: string, path: string, type: string, size: int, mtime: int, last_use: int} $file
     */
    private function remove(array $file, string $reason, StorageReport $report, bool $dryRun): void
    {
        $this->pause();
        if (!$dryRun && !@unlink($file['path'])) {
            $report->failed++;

            return;
        }

        $report->removedFiles++;
        $report->removedBytes += $file['size'];
        match ($reason) {
            'age' => $report->removedByAge++,
            'count' => $report->removedByCount++,
            'size' => $report->removedBySize++,
            default => $report->removedByRequest++,
        };
        $type = &$report->removedTypes[$file['type']];
        $type ??= ['files' => 0, 'bytes' => 0];
        $type['files']++;
        $type['bytes'] += $file['size'];
        unset($type);

        $report->bots[$file['bot']]['files']--;
        $report->bots[$file['bot']]['bytes'] -= $file['size'];
        $report->removed[] = ['path' => $file['path'], 'size' => $file['size'], 'reason' => $reason];
    }

    /**
     * A file_unique_id → path entry whose file is gone would only be a miss
     * for getFile, but left alone these entries would pile up forever.
     *
     * @param list<string> $directories
     */
    private function forgetRemoved(array $directories, StorageReport $report, bool $dryRun): void
    {
        if ($dryRun || $report->removedFiles === 0) {
            return;
        }

        clearstatcache();
        foreach ($directories as $bot) {
            $database = $this->workingDirectory.$bot.'/'.self::FILES_DB.'/';
            foreach (@scandir($database) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $this->pause();
                $relative = @file_get_contents($database.$name);
                if ($relative !== false && !is_file($this->filesDirectory.$bot.'/'.$relative) && @unlink($database.$name)) {
                    $report->forgottenEntries++;
                }
            }
        }
    }

    private function pause(): void
    {
        if ($this->yield !== null && ++$this->operations % self::YIELD_EVERY === 0) {
            ($this->yield)();
        }
    }
}
