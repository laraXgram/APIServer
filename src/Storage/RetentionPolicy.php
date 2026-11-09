<?php

declare(strict_types=1);

namespace LaraGram\Server\Storage;

use LaraGram\Server\Support\Units;

/**
 * Which downloaded files may be deleted, and when (td::FileGcParameters plus
 * the filters of the storage:clean command).
 *
 * A filter narrows the files the policy looks at: files outside it neither
 * count towards the limits nor get deleted.
 */
final class RetentionPolicy
{
    public const AGE_FROM_ACCESS = 'access';
    public const AGE_FROM_DOWNLOAD = 'download';

    public const SCOPE_BOT = 'bot';
    public const SCOPE_SERVER = 'server';

    /** The directories of the downloaded files, by td::get_file_type_name. */
    public const TYPES = [
        'animations', 'documents', 'music', 'notification_sounds', 'passport', 'photos', 'profile_photos', 'secret',
        'secret_thumbnails', 'stickers', 'stories', 'temp', 'thumbnails', 'video_notes', 'videos', 'voice', 'wallpapers',
    ];

    /** The default storage_max_files_size, storage_max_file_count, storage_max_time_from_last_access and storage_immunity_delay of TDLib. */
    private const DEFAULT_MAX_SIZE = 100 << 20;
    private const DEFAULT_MAX_FILES = 40000;
    private const DEFAULT_MAX_AGE = 60 * 60 * 23;
    private const DEFAULT_IMMUNITY = 60 * 60;

    /**
     * @param list<string> $types only these types; every type when empty
     * @param list<string> $excludeTypes
     * @param list<string> $extensions only these extensions, lower-case; every extension when empty
     * @param list<string> $excludeExtensions
     * @param list<int> $bots only these bot identifiers; every bot when empty
     * @param list<int> $excludeBots
     */
    public function __construct(
        public readonly ?int $maxFiles = null,
        public readonly ?int $maxSize = null,
        public readonly ?int $maxAge = null,
        public readonly string $ageFrom = self::AGE_FROM_ACCESS,
        public readonly int $immunity = self::DEFAULT_IMMUNITY,
        public readonly string $scope = self::SCOPE_BOT,
        public readonly array $types = [],
        public readonly array $excludeTypes = [],
        public readonly ?int $minFileSize = null,
        public readonly ?int $maxFileSize = null,
        public readonly array $extensions = [],
        public readonly array $excludeExtensions = [],
        public readonly array $bots = [],
        public readonly array $excludeBots = [],
        public readonly bool $all = false,
    ) {
    }

    /**
     * What the official server applies to every bot (use_storage_optimizer).
     */
    public static function defaults(): self
    {
        return new self(self::DEFAULT_MAX_FILES, self::DEFAULT_MAX_SIZE, self::DEFAULT_MAX_AGE);
    }

    /**
     * A policy from option values, keyed by option name without any prefix.
     * Options that are absent keep the value of $base; 0 turns a limit off.
     *
     * @param array<string, mixed> $options
     *
     * @throws \InvalidArgumentException
     */
    public static function fromOptions(array $options, ?self $base = null, bool $all = false): self
    {
        $base ??= new self();

        $ageFrom = (string) ($options['age-from'] ?? $base->ageFrom);
        if ($ageFrom !== self::AGE_FROM_ACCESS && $ageFrom !== self::AGE_FROM_DOWNLOAD) {
            throw new \InvalidArgumentException("Wrong value \"{$ageFrom}\" of age-from: expected access or download");
        }
        $scope = (string) ($options['scope'] ?? $base->scope);
        if ($scope !== self::SCOPE_BOT && $scope !== self::SCOPE_SERVER) {
            throw new \InvalidArgumentException("Wrong value \"{$scope}\" of scope: expected bot or server");
        }

        return new self(
            maxFiles: self::limit($options['max-files'] ?? null, $base->maxFiles, self::count(...)),
            maxSize: self::limit($options['max-size'] ?? null, $base->maxSize, Units::parseSize(...)),
            maxAge: self::limit($options['max-age'] ?? null, $base->maxAge, Units::parseDuration(...)),
            ageFrom: $ageFrom,
            immunity: isset($options['immunity']) ? Units::parseDuration((string) $options['immunity']) : $base->immunity,
            scope: $scope,
            types: self::types($options['types'] ?? null, $base->types),
            excludeTypes: self::types($options['exclude-types'] ?? null, $base->excludeTypes),
            minFileSize: self::limit($options['min-file-size'] ?? null, $base->minFileSize, Units::parseSize(...)),
            maxFileSize: self::limit($options['max-file-size'] ?? null, $base->maxFileSize, Units::parseSize(...)),
            extensions: self::extensions($options['extensions'] ?? null, $base->extensions),
            excludeExtensions: self::extensions($options['exclude-extensions'] ?? null, $base->excludeExtensions),
            bots: self::bots($options['bots'] ?? null, $base->bots),
            excludeBots: self::bots($options['exclude-bots'] ?? null, $base->excludeBots),
            all: $all,
        );
    }

    public function hasLimits(): bool
    {
        return $this->all || $this->maxFiles !== null || $this->maxSize !== null || $this->maxAge !== null;
    }

    public function coversBot(int $botId): bool
    {
        return !in_array($botId, $this->excludeBots, true) && ($this->bots === [] || in_array($botId, $this->bots, true));
    }

    public function coversFile(string $type, string $extension, int $size): bool
    {
        return !in_array($type, $this->excludeTypes, true)
            && ($this->types === [] || in_array($type, $this->types, true))
            && !in_array($extension, $this->excludeExtensions, true)
            && ($this->extensions === [] || in_array($extension, $this->extensions, true))
            && ($this->minFileSize === null || $size >= $this->minFileSize)
            && ($this->maxFileSize === null || $size <= $this->maxFileSize);
    }

    /**
     * @param \Closure(string): int $parse
     */
    private static function limit(mixed $value, ?int $base, \Closure $parse): ?int
    {
        if ($value === null) {
            return $base;
        }
        $parsed = $parse((string) $value);

        return $parsed === 0 ? null : $parsed;
    }

    private static function count(string $value): int
    {
        $value = trim($value);
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException("Wrong number of files \"{$value}\"");
        }

        return (int) $value;
    }

    /**
     * @param list<string> $base
     * @return list<string>
     */
    private static function types(mixed $value, array $base): array
    {
        if ($value === null) {
            return $base;
        }
        $types = self::split($value);
        foreach ($types as $type) {
            if (!in_array($type, self::TYPES, true)) {
                throw new \InvalidArgumentException("Unknown file type \"{$type}\": expected any of ".implode(', ', self::TYPES));
            }
        }

        return $types;
    }

    /**
     * @param list<string> $base
     * @return list<string>
     */
    private static function extensions(mixed $value, array $base): array
    {
        if ($value === null) {
            return $base;
        }

        return array_map(static fn (string $extension): string => strtolower(ltrim($extension, '.')), self::split($value));
    }

    /**
     * @param list<int> $base
     * @return list<int>
     */
    private static function bots(mixed $value, array $base): array
    {
        if ($value === null) {
            return $base;
        }

        return array_map(static function (string $bot): int {
            if (!ctype_digit($bot)) {
                throw new \InvalidArgumentException("Wrong bot identifier \"{$bot}\"");
            }

            return (int) $bot;
        }, self::split($value));
    }

    /**
     * @return list<string>
     */
    private static function split(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $item): bool => $item !== ''));
    }
}
