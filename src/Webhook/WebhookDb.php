<?php

declare(strict_types=1);

namespace LaraGram\Server\Webhook;

use LaraGram\Filesystem\Filesystem;

/**
 * The webhooks database (webhooks_db.binlog): bot token with DC → the
 * serialized webhook settings, read back on start-up to restore webhooks.
 */
final class WebhookDb
{
    /** @var array<string, string> */
    private array $values = [];

    private readonly Filesystem $files;

    public function __construct(private readonly string $path)
    {
        $this->files = new Filesystem();
        if ($this->files->exists($path)) {
            $values = json_decode((string) $this->files->get($path), true);
            if (is_array($values)) {
                foreach ($values as $key => $value) {
                    if (is_string($value)) {
                        $this->values[(string) $key] = $value;
                    }
                }
            }
        }
    }

    public function get(string $key): string
    {
        return $this->values[$key] ?? '';
    }

    public function set(string $key, string $value): void
    {
        if (($this->values[$key] ?? null) === $value) {
            return;
        }
        $this->values[$key] = $value;
        $this->save();
    }

    public function erase(string $key): void
    {
        if (!isset($this->values[$key])) {
            return;
        }
        unset($this->values[$key]);
        $this->save();
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->values;
    }

    private function save(): void
    {
        $this->files->replace($this->path, (string) json_encode($this->values, JSON_UNESCAPED_SLASHES), 0600);
    }
}
