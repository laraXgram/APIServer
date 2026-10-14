<?php

declare(strict_types=1);

namespace LaraGram\Server\Stats;

/**
 * BotStatActor: the statistics of a bot (or of the whole server when it has
 * no parent) over the whole run, 5 seconds, a minute and an hour; events of
 * a bot are also added to its parent.
 */
final class BotStatActor
{
    private const DESCRIPTIONS = ['inf', '5sec', '1min', '1hour'];
    private const DURATIONS = [0, 5, 60, 60 * 60];

    /** @var list<TimedStat<BotStat>> */
    private array $stats = [];

    private float $lastActivityTimestamp = -1e9;

    private int $activeRequestCount = 0;

    private int $activeFileUploadBytes = 0;

    private int $activeFileUploadCount = 0;

    public function __construct(private readonly ?self $parent = null)
    {
        $now = microtime(true);
        foreach (self::DURATIONS as $duration) {
            $this->stats[] = new TimedStat(static fn (): BotStat => new BotStat(), (float) $duration, $now);
        }
    }

    public function addRequest(int $size, int $fileCount, int $filesSize, int $filesMaxSize): void
    {
        $now = microtime(true);
        $this->addEvent(static function (BotStat $stat) use ($size, $fileCount, $filesSize, $filesMaxSize): void {
            $stat->requestCount++;
            $stat->requestBytes += $size;
            $stat->requestFileCount += $fileCount;
            $stat->requestFilesBytes += $filesSize;
            $stat->requestFilesMaxBytes = max($stat->requestFilesMaxBytes, $filesMaxSize);
        }, $now);
        $this->activeRequestCount++;
        $this->activeFileUploadCount += $fileCount;
        $this->activeFileUploadBytes += $filesSize;
        $this->parent?->addRequest($size, $fileCount, $filesSize, $filesMaxSize);
    }

    public function addResponse(bool $isOk, int $size, int $fileCount, int $filesSize): void
    {
        $now = microtime(true);
        $this->addEvent(static function (BotStat $stat) use ($isOk, $size): void {
            $stat->responseCount++;
            if ($isOk) {
                $stat->responseCountOk++;
            } else {
                $stat->responseCountError++;
            }
            $stat->responseBytes += $size;
        }, $now);
        $this->activeRequestCount = max(0, $this->activeRequestCount - 1);
        $this->activeFileUploadCount = max(0, $this->activeFileUploadCount - $fileCount);
        $this->activeFileUploadBytes = max(0, $this->activeFileUploadBytes - $filesSize);
        $this->parent?->addResponse($isOk, $size, $fileCount, $filesSize);
    }

    public function addUpdate(): void
    {
        $this->addEvent(static function (BotStat $stat): void {
            $stat->updateCount++;
        }, microtime(true));
        $this->parent?->addUpdate();
    }

    private function addEvent(callable $apply, float $now): void
    {
        $this->lastActivityTimestamp = $now;
        foreach ($this->stats as $stat) {
            $stat->addEvent($apply, $now);
        }
    }

    /**
     * BotStatActor::as_vector: each counter per second over every duration.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function asVector(float $now): array
    {
        $result = [];
        foreach ($this->stats as $i => $timed) {
            [$stat, $duration] = $timed->statDuration($now);
            $stat->normalize($duration);
            foreach ($stat->asVector() as $j => [$key, $value]) {
                if ($i === 0) {
                    $result[$j] = [$key, $value];
                } else {
                    $result[$j][1] .= "\t".$value;
                }
            }
        }

        return $result;
    }

    public static function getDescription(): string
    {
        return 'DURATION'."\t".implode("\t", self::DESCRIPTIONS);
    }

    public function getScore(float $now): float
    {
        [$minute, $minuteDuration] = $this->stats[2]->statDuration($now);
        $minuteScore = $minute->requestCount + $minute->updateCount;
        if ($minuteDuration != 0.0) {
            $minuteScore /= $minuteDuration;
        }
        [$all, $allDuration] = $this->stats[0]->statDuration($now);
        $allTimeScore = 0.01 * ($all->requestCount + $all->updateCount);
        if ($allDuration != 0.0) {
            $allTimeScore /= $allDuration;
        }

        return $minuteScore + $allTimeScore + (float) max($this->activeRequestCount - 10, 0) + $this->activeFileUploadBytes * 1e-8;
    }

    public function getMinuteUpdateCount(float $now): float
    {
        [$minute, $duration] = $this->stats[2]->statDuration($now);

        return $duration != 0.0 ? $minute->updateCount / $duration : $minute->updateCount;
    }

    public function getActiveRequestCount(): int
    {
        return $this->activeRequestCount;
    }

    public function getActiveFileUploadBytes(): int
    {
        return $this->activeFileUploadBytes;
    }

    public function getActiveFileUploadCount(): int
    {
        return $this->activeFileUploadCount;
    }

    public function isActive(float $now): bool
    {
        return $this->lastActivityTimestamp > $now - 86400;
    }
}
