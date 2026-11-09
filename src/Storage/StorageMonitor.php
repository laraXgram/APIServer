<?php

declare(strict_types=1);

namespace LaraGram\Server\Storage;

use LaraGram\Filesystem\Filesystem;
use LaraGram\Server\Foundation\ServerParameters;
use LaraGram\Server\Support\Units;
use Swoole\Coroutine;

/**
 * Keeps the downloaded files within the retention policy while the server
 * runs: a clean-up on a schedule (td::StorageManager) and, as soon as
 * downloads push a bot over a limit, one for that bot.
 */
final class StorageMonitor
{
    private const STATE_FILE = 'storage_gc.json';

    /** StorageManager::GC_DELAY and GC_RAND_DELAY */
    private const START_DELAY = 60;
    private const START_RANDOM_DELAY = 60 * 15;

    /** Limits can stay exceeded by recent files, which can't be deleted yet. */
    private const PRESSURE_DELAY = 60;
    private const FRUITLESS_PRESSURE_DELAY = 60 * 15;

    private StorageCleaner $cleaner;

    private float $nextRunAt = 0.0;

    private float $nextPressureRunAt = 0.0;

    private bool $running = false;

    private int $lastRun = 0;

    private int $removedFiles = 0;

    private int $removedBytes = 0;

    /** @var array<string, array{files: int, bytes: int}> bot directory => what the policy covers */
    private array $tallies = [];

    /** @var array<string, true> bots over a limit */
    private array $pressured = [];

    /**
     * @param (\Closure(int): bool)|null $botFilter
     */
    public function __construct(
        private readonly ServerParameters $parameters,
        private readonly RetentionPolicy $policy,
        private readonly int $interval,
        ?\Closure $botFilter = null,
    ) {
        $this->cleaner = new StorageCleaner(
            $parameters->filesDirectory,
            $parameters->workingDirectory,
            $botFilter,
            static fn () => Coroutine::sleep(0.001),
        );

        $this->lastRun = $this->loadLastRun();
        $now = time();
        $nextRun = min(max($this->lastRun + $interval, $now), $now + $interval);
        $this->nextRunAt = microtime(true) + ($nextRun - $now) + mt_rand(self::START_DELAY, self::START_DELAY + self::START_RANDOM_DELAY);
    }

    /**
     * Called once a second.
     */
    public function tick(): void
    {
        if ($this->running) {
            return;
        }

        $now = microtime(true);
        if ($now >= $this->nextRunAt) {
            $this->start(null, true);
        } elseif ($this->pressured !== [] && $now >= $this->nextPressureRunAt) {
            $bots = $this->policy->scope === RetentionPolicy::SCOPE_SERVER ? null : array_keys($this->pressured);
            $this->pressured = [];
            $this->start($bots, false);
        }
    }

    /**
     * A file was downloaded into a bot's directory.
     */
    public function recordDownload(string $bot, int $size): void
    {
        $tally = &$this->tallies[$bot];
        $tally ??= ['files' => 0, 'bytes' => 0];
        $tally['files']++;
        $tally['bytes'] += $size;
        unset($tally);

        if ($this->isOverLimit($bot)) {
            $this->pressured[$bot] = true;
        }
    }

    /**
     * @return list<array{0: string, 1: string|int}> lines of the statistics page
     */
    public function getStats(): array
    {
        return [
            ['storage_files', array_sum(array_column($this->tallies, 'files'))],
            ['storage_size', Units::formatSize(array_sum(array_column($this->tallies, 'bytes')))],
            ['storage_last_cleanup', $this->lastRun],
            ['storage_removed_files', $this->removedFiles],
            ['storage_removed_size', Units::formatSize($this->removedBytes)],
        ];
    }

    private function isOverLimit(string $bot): bool
    {
        if ($this->policy->scope === RetentionPolicy::SCOPE_SERVER) {
            $files = array_sum(array_column($this->tallies, 'files'));
            $bytes = array_sum(array_column($this->tallies, 'bytes'));
        } else {
            ['files' => $files, 'bytes' => $bytes] = $this->tallies[$bot];
        }

        return ($this->policy->maxFiles !== null && $files > $this->policy->maxFiles)
            || ($this->policy->maxSize !== null && $bytes > $this->policy->maxSize);
    }

    /**
     * @param list<string>|null $bots
     */
    private function start(?array $bots, bool $scheduled): void
    {
        $this->running = true;

        Coroutine::create(function () use ($bots, $scheduled): void {
            try {
                $report = $this->cleaner->run($this->policy, false, $bots);
                $this->onFinished($report, $scheduled);
            } catch (\Throwable $e) {
                $this->parameters->logger->error("Storage clean-up failed: {$e->getMessage()}");
                $this->nextPressureRunAt = microtime(true) + self::FRUITLESS_PRESSURE_DELAY;
            } finally {
                if ($scheduled) {
                    $this->nextRunAt = microtime(true) + $this->interval + mt_rand(self::START_DELAY, self::START_DELAY + self::START_RANDOM_DELAY);
                }
                $this->running = false;
            }
        });
    }

    private function onFinished(StorageReport $report, bool $scheduled): void
    {
        foreach ($report->bots as $bot => $tally) {
            $this->tallies[$bot] = $tally;
        }
        $this->removedFiles += $report->removedFiles;
        $this->removedBytes += $report->removedBytes;
        $this->nextPressureRunAt = microtime(true) + ($report->removedFiles > 0 ? self::PRESSURE_DELAY : self::FRUITLESS_PRESSURE_DELAY);

        $summary = sprintf(
            'Storage clean-up: %d of %d files removed (%s), %d by age, %d by count, %d by size, %d recent, %d failed, in %.2fs',
            $report->removedFiles, $report->scannedFiles, Units::formatSize($report->removedBytes),
            $report->removedByAge, $report->removedByCount, $report->removedBySize, $report->immuneFiles, $report->failed, $report->duration,
        );
        if ($report->duration > 1.0 || $report->failed > 0) {
            $this->parameters->logger->warning($summary);
        } else {
            $this->parameters->logger->info($summary);
        }

        if ($scheduled) {
            $this->lastRun = time();
            $this->saveLastRun();
        }
    }

    private function loadLastRun(): int
    {
        $state = json_decode((string) @file_get_contents($this->parameters->workingDirectory.self::STATE_FILE), true);

        return is_array($state) ? max(0, min((int) ($state['last_run'] ?? 0), time())) : 0;
    }

    private function saveLastRun(): void
    {
        (new Filesystem())->replace($this->parameters->workingDirectory.self::STATE_FILE, (string) json_encode(['last_run' => $this->lastRun]), 0600);
    }
}
