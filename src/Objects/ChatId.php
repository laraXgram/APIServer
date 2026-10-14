<?php

declare(strict_types=1);

namespace LaraGram\Server\Objects;

/**
 * Bot API chat identifiers (TDLib DialogId): users keep their id, basic
 * groups are negated, supergroups and channels are -100… (−10^12 − id).
 */
final class ChatId
{
    public const TYPE_PRIVATE = 0;
    public const TYPE_GROUP = 1;
    public const TYPE_SUPERGROUP = 2;

    private const ZERO_CHANNEL_ID = -1000000000000;

    public static function fromUser(int $userId): int
    {
        return $userId;
    }

    public static function fromBasicGroup(int $chatId): int
    {
        return -$chatId;
    }

    public static function fromChannel(int $channelId): int
    {
        return self::ZERO_CHANNEL_ID - $channelId;
    }

    /**
     * The chat id of a TL `peerUser`/`peerChat`/`peerChannel`, or 0.
     *
     * @param array<string, mixed>|null $peer
     */
    public static function fromPeer(?array $peer): int
    {
        return match ($peer['_'] ?? '') {
            'peerUser' => (int) $peer['user_id'],
            'peerChat' => -(int) $peer['chat_id'],
            'peerChannel' => self::ZERO_CHANNEL_ID - (int) $peer['channel_id'],
            default => 0,
        };
    }

    public static function type(int $chatId): ?int
    {
        return match (true) {
            $chatId > 0 => self::TYPE_PRIVATE,
            $chatId < self::ZERO_CHANNEL_ID => self::TYPE_SUPERGROUP,
            $chatId < 0 => self::TYPE_GROUP,
            default => null,
        };
    }

    public static function toChannelId(int $chatId): int
    {
        return self::ZERO_CHANNEL_ID - $chatId;
    }

    public static function toBasicGroupId(int $chatId): int
    {
        return -$chatId;
    }
}
