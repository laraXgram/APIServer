<?php

declare(strict_types=1);

namespace LaraGram\Server\Storage;

/**
 * What one clean-up found and deleted.
 */
final class StorageReport
{
    public int $scannedFiles = 0;

    public int $scannedBytes = 0;

    public int $removedFiles = 0;

    public int $removedBytes = 0;

    public int $removedByAge = 0;

    public int $removedByCount = 0;

    public int $removedBySize = 0;

    public int $removedByRequest = 0;

    public int $failed = 0;

    public int $immuneFiles = 0;

    public int $forgottenEntries = 0;

    public float $duration = 0.0;

    /** @var array<string, array{files: int, bytes: int}> the files the policy covers that are left, by bot directory */
    public array $bots = [];

    /** @var array<string, array{files: int, bytes: int}> deleted files by type */
    public array $removedTypes = [];

    /** @var list<array{path: string, size: int, reason: string}> */
    public array $removed = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scanned_files' => $this->scannedFiles,
            'scanned_bytes' => $this->scannedBytes,
            'removed_files' => $this->removedFiles,
            'removed_bytes' => $this->removedBytes,
            'removed_by' => [
                'age' => $this->removedByAge,
                'count' => $this->removedByCount,
                'size' => $this->removedBySize,
                'request' => $this->removedByRequest,
            ],
            'removed_types' => $this->removedTypes,
            'immune_files' => $this->immuneFiles,
            'failed' => $this->failed,
            'forgotten_entries' => $this->forgottenEntries,
            'duration' => round($this->duration, 3),
            'removed' => $this->removed,
        ];
    }
}
