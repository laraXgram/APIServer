<?php

declare(strict_types=1);

namespace LaraGram\Server\Generator;

use LaraGram\Filesystem\Filesystem;
use LaraGram\Http\Client\Factory as HttpFactory;

/**
 * The pinned Bot API spec (resources/spec/telegram-api.json): the scraped
 * documentation the generator reads methods and types from.
 */
final class SpecRepository
{
    public const SPEC_URL = 'https://raw.githubusercontent.com/laraxgram/telegram-api-data/main/telegram-api.json';

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $directory,
        private readonly HttpFactory $http = new HttpFactory(),
    ) {
    }

    public function specPath(): string
    {
        return $this->directory.'/telegram-api.json';
    }

    /**
     * Download the latest scraped documentation over the pinned copy.
     */
    public function fetchSpec(string $url = self::SPEC_URL): void
    {
        $response = $this->http->timeout(30)->withUserAgent('LaraGram-Server-Generator')->get($url);
        if (!$response->successful()) {
            throw new \RuntimeException("Failed to download the Bot API spec from {$url} (HTTP {$response->status()})");
        }

        $json = $response->body();
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['methods'], $decoded['types'])) {
            throw new \RuntimeException('The downloaded Bot API spec is not valid');
        }

        $this->files->ensureDirectoryExists($this->directory);
        $this->files->put($this->specPath(), $json);
    }

    /**
     * @return array{version: string, methods: list<array<string, mixed>>, types: array<string, array<string, mixed>>}
     */
    public function spec(): array
    {
        return $this->json($this->specPath());
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $path): array
    {
        if (!$this->files->exists($path)) {
            throw new \RuntimeException("Missing generator input {$path}");
        }

        return json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
