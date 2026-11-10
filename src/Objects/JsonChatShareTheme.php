<?php

declare(strict_types=1);

namespace LaraGram\Server\Objects;

use LaraGram\Server\Bot\Client;

/**
 * The theme of the screen for sharing a link to a chat: td::ProfileShareTheme
 * built from the TL `ShareTheme` and the Bot API `ChatShareTheme`
 * (Client::JsonChatShareTheme), with the backdrops it can use
 * (Client::JsonChatShareThemeBackdrops).
 */
final class JsonChatShareTheme
{
    /** ProfileShareTheme::MAX_EMOJI_COUNT */
    private const MAX_EMOJI_COUNT = 6;

    /**
     * ProfileShareTheme::get_profile_share_theme; null for the default theme or an invalid one.
     *
     * @param array<string, mixed>|null $theme TL ShareTheme
     * @return array<string, mixed>|null
     */
    public static function fromServer(?array $theme): ?array
    {
        switch ($theme['_'] ?? '') {
            case 'shareThemeEmojiBackdrop':
                $emojis = [];
                foreach ($theme['emojis'] ?? [] as $reaction) {
                    if (($reaction['_'] ?? '') === 'reactionEmoji' && (string) ($reaction['emoticon'] ?? '') !== '') {
                        $emojis[] = ['emoji' => (string) $reaction['emoticon'], 'custom_emoji_id' => 0];
                    } elseif (($reaction['_'] ?? '') === 'reactionCustomEmoji' && (int) ($reaction['document_id'] ?? 0) !== 0) {
                        $emojis[] = ['emoji' => '', 'custom_emoji_id' => (int) $reaction['document_id']];
                    }
                }
                if (count($emojis) > self::MAX_EMOJI_COUNT) {
                    return null;
                }
                $useDefaultBackground = !isset($theme['backdrop_id']);
                if ($emojis === [] && $useDefaultBackground) {
                    return null;
                }

                return [
                    'type' => 'emoji',
                    'emojis' => $emojis,
                    'use_default_background' => $useDefaultBackground,
                    'background_backdrop_id' => (int) ($theme['backdrop_id'] ?? 0),
                ];

            case 'shareThemeGift':
                $gift = JsonGift::fromServer($theme['stargift'] ?? [], true);
                if ($gift === null || !$gift['is_unique']) {
                    return null;
                }

                return ['type' => 'unique_gift', 'gift' => $gift];
        }

        return null;
    }

    /**
     * Client::JsonChatShareTheme.
     *
     * @param array<string, mixed> $theme
     * @return array<string, mixed>
     */
    public static function make(array $theme, Client $client): array
    {
        if ($theme['type'] === 'unique_gift') {
            return ['type' => 'unique_gift', 'gift' => JsonGift::uniqueGift($theme['gift'], $client)];
        }

        // Client::JsonEmojiType
        $object = ['type' => 'emoji', 'emojis' => array_map(static fn (array $emoji): array => $emoji['custom_emoji_id'] !== 0
            ? ['type' => 'custom_emoji', 'custom_emoji_id' => (string) $emoji['custom_emoji_id']]
            : ['type' => 'emoji', 'emoji' => $emoji['emoji']], $theme['emojis'])];
        if (!$theme['use_default_background']) {
            $object['background_backdrop_id'] = $theme['background_backdrop_id'];
        }

        return $object;
    }

    /**
     * The unique gift of a theme, whose sticker sets must be known
     * (Client::get_profile_share_theme_sticker_set_ids).
     *
     * @param array<string, mixed>|null $theme
     * @return list<array<string, mixed>>
     */
    public static function gifts(?array $theme): array
    {
        return ($theme['type'] ?? '') === 'unique_gift' ? [$theme['gift']] : [];
    }

    /**
     * ThemeManager::ShareThemeBackdrop + Client::JsonChatShareThemeBackdrop.
     *
     * @param array<string, mixed> $backdrop TL shareThemeBackdropOption
     * @return array<string, mixed>
     */
    public static function backdrop(array $backdrop): array
    {
        return [
            'id' => (int) ($backdrop['backdrop_id'] ?? 0),
            'name' => (string) ($backdrop['name'] ?? ''),
            'colors' => [
                'center_color' => (int) ($backdrop['center_color'] ?? 0),
                'edge_color' => (int) ($backdrop['edge_color'] ?? 0),
                'symbol_color' => (int) ($backdrop['pattern_color'] ?? 0),
                'text_color' => (int) ($backdrop['text_color'] ?? 0),
            ],
        ];
    }
}
