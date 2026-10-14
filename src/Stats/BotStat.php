<?php

declare(strict_types=1);

namespace LaraGram\Server\Stats;

/**
 * ServerBotStat: request, response and update counters of a time window.
 */
final class BotStat
{
    public float $requestCount = 0.0;
    public float $requestBytes = 0.0;
    public float $requestFileCount = 0.0;
    public float $requestFilesBytes = 0.0;
    public int $requestFilesMaxBytes = 0;
    public float $responseCount = 0.0;
    public float $responseCountOk = 0.0;
    public float $responseCountError = 0.0;
    public float $responseBytes = 0.0;
    public float $updateCount = 0.0;

    public function normalize(float $duration): void
    {
        if ($duration == 0.0) {
            return;
        }
        $this->requestCount /= $duration;
        $this->requestBytes /= $duration;
        $this->requestFileCount /= $duration;
        $this->requestFilesBytes /= $duration;
        $this->responseCount /= $duration;
        $this->responseCountOk /= $duration;
        $this->responseCountError /= $duration;
        $this->responseBytes /= $duration;
        $this->updateCount /= $duration;
    }

    /**
     * ServerBotStat::as_vector.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function asVector(): array
    {
        return [
            ['request_count', Stats::double($this->requestCount)],
            ['request_bytes', Stats::double($this->requestBytes)],
            ['request_file_count', Stats::double($this->requestFileCount)],
            ['request_files_bytes', Stats::double($this->requestFilesBytes)],
            ['request_max_bytes', (string) $this->requestFilesMaxBytes],
            ['response_count', Stats::double($this->responseCount)],
            ['response_count_ok', Stats::double($this->responseCountOk)],
            ['response_count_error', Stats::double($this->responseCountError)],
            ['response_bytes', Stats::double($this->responseBytes)],
            ['update_count', Stats::double($this->updateCount)],
        ];
    }
}
