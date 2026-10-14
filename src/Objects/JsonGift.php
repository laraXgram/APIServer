<?php

declare(strict_types=1);

namespace LaraGram\Server\Objects;

use LaraGram\Server\Bot\Client;
use LaraGram\Server\Messages\MediaConverter;
use LaraGram\Server\Objects\ChatId;

/**
 * Star gifts: td::StarGift built from the TL `StarGift` and the Bot API
 * `Gift` (Client::JsonGift) and `UniqueGift` (Client::JsonUniqueGift).
 */
final class JsonGift
{
    /**
     * StarGift(td, telegram_api::StarGift, allow_unique_gift); null when invalid.
     *
     * @param array<string, mixed> $gift TL StarGift
     * @return array<string, mixed>|null
     */
    public static function fromServer(array $gift, bool $allowUnique): ?array
    {
        if ($allowUnique && ($gift['_'] ?? '') === 'starGiftUnique') {
            if ((int) ($gift['id'] ?? 0) === 0) {
                return null;
            }
            $result = [
                'is_unique' => true,
                'id' => (int) $gift['id'],
                'regular_gift_id' => (int) ($gift['gift_id'] ?? 0),
                'title' => (string) ($gift['title'] ?? ''),
                'slug' => (string) ($gift['slug'] ?? ''),
                'num' => (int) ($gift['num'] ?? 0),
                'host_id' => isset($gift['host_id']) ? ChatId::fromPeer($gift['host_id']) : 0,
                'owner_id' => isset($gift['owner_id']) ? ChatId::fromPeer($gift['owner_id']) : 0,
                'is_burned' => !empty($gift['burned']),
                'released_by' => isset($gift['released_by']) ? ChatId::fromPeer($gift['released_by']) : 0,
                'is_premium' => !empty($gift['require_premium']),
                'model' => null,
                'pattern' => null,
                'backdrop' => null,
                'colors' => null,
            ];
            foreach ($gift['attributes'] ?? [] as $attribute) {
                switch ($attribute['_'] ?? '') {
                    case 'starGiftAttributeModel':
                    case 'starGiftAttributePattern':
                        $sticker = isset($attribute['document']) ? MediaConverter::document($attribute['document']) : null;
                        $rarity = self::rarity($attribute['rarity'] ?? []);
                        $value = $sticker === null || $rarity === null ? null : ['name' => (string) $attribute['name'], 'sticker' => $sticker, 'rarity' => $rarity];
                        $result[$attribute['_'] === 'starGiftAttributeModel' ? 'model' : 'pattern'] = $value;
                        break;
                    case 'starGiftAttributeBackdrop':
                        $rarity = self::rarity($attribute['rarity'] ?? []);
                        $colors = [(int) $attribute['center_color'], (int) $attribute['edge_color'], (int) $attribute['pattern_color'], (int) $attribute['text_color']];
                        $valid = $rarity !== null && count(array_filter($colors, static fn (int $c): bool => $c >= 0 && $c <= 0xFFFFFF)) === 4;
                        $result['backdrop'] = $valid ? ['name' => (string) $attribute['name'], 'colors' => $colors, 'rarity' => $rarity] : null;
                        break;
                }
            }
            $peerColor = $gift['peer_color'] ?? null;
            if (($peerColor['_'] ?? '') === 'peerColorCollectible') {
                $light = $peerColor['colors'] ?? [];
                if (count($light) > 3) {
                    $light = [];
                }
                $dark = array_key_exists('dark_colors', $peerColor) ? $peerColor['dark_colors'] : $light;
                if (count($dark) > 3) {
                    $dark = [];
                }
                $result['colors'] = [
                    'model_custom_emoji_id' => (int) ($peerColor['gift_emoji_id'] ?? 0) > 0 ? (int) $peerColor['gift_emoji_id'] : 0,
                    'symbol_custom_emoji_id' => (int) ($peerColor['background_emoji_id'] ?? 0) > 0 ? (int) $peerColor['background_emoji_id'] : 0,
                    'light_theme_main_color' => (int) $peerColor['accent_color'],
                    'light_theme_other_colors' => array_map('intval', $light),
                    'dark_theme_main_color' => array_key_exists('dark_accent_color', $peerColor) ? (int) $peerColor['dark_accent_color'] : (int) $peerColor['accent_color'],
                    'dark_theme_other_colors' => array_map('intval', $dark),
                ];
            }
            if ($result['model'] === null || $result['pattern'] === null || $result['backdrop'] === null) {
                return null;
            }

            return $result;
        }

        if (($gift['_'] ?? '') !== 'starGift' || (int) ($gift['id'] ?? 0) === 0) {
            return null;
        }
        $sticker = isset($gift['sticker']) ? MediaConverter::document($gift['sticker']) : null;
        if ($sticker === null) {
            return null;
        }
        [$availabilityTotal, $availabilityRemains] = self::fixAvailability((int) ($gift['availability_total'] ?? 0), (int) ($gift['availability_remains'] ?? 0));
        [$perUserTotal, $perUserRemains] = self::fixAvailability((int) ($gift['per_user_total'] ?? 0), (int) ($gift['per_user_remains'] ?? 0));
        $background = $gift['background'] ?? null;

        return [
            'is_unique' => false,
            'id' => (int) $gift['id'],
            'sticker' => $sticker,
            'star_count' => self::starCount((int) ($gift['stars'] ?? 0)),
            'default_sell_star_count' => self::starCount((int) ($gift['convert_stars'] ?? 0)),
            'upgrade_star_count' => self::starCount((int) ($gift['upgrade_stars'] ?? 0)),
            'upgrade_variants' => max(0, (int) ($gift['upgrade_variants'] ?? 0)),
            'availability_total' => $availabilityTotal,
            'availability_remains' => $availabilityRemains,
            'per_user_total' => $perUserTotal,
            'per_user_remains' => $perUserRemains,
            'has_colors' => !empty($gift['peer_color_available']),
            'released_by' => isset($gift['released_by']) ? ChatId::fromPeer($gift['released_by']) : 0,
            'is_premium' => !empty($gift['require_premium']),
            'background' => $background === null ? null : [
                'center_color' => (int) $background['center_color'],
                'edge_color' => (int) $background['edge_color'],
                'text_color' => (int) $background['text_color'],
            ],
        ];
    }

    /**
     * Client::JsonGift.
     *
     * @param array<string, mixed> $gift
     * @return array<string, mixed>
     */
    public static function gift(array $gift, Client $client): array
    {
        $object = [
            'id' => (string) $gift['id'],
            'sticker' => JsonSticker::make($gift['sticker'], $client),
            'star_count' => $gift['star_count'],
        ];
        if ($gift['upgrade_star_count'] > 0) {
            $object['upgrade_star_count'] = $gift['upgrade_star_count'];
        }
        if ($gift['availability_total'] > 0) {
            $object['remaining_count'] = $gift['availability_remains'];
            $object['total_count'] = $gift['availability_total'];
        }
        if ($gift['per_user_total'] > 0) {
            $object['personal_remaining_count'] = $gift['per_user_remains'];
            $object['personal_total_count'] = $gift['per_user_total'];
        }
        if ($gift['released_by'] !== 0) {
            $object['publisher_chat'] = JsonChat::make($gift['released_by'], $client);
        }
        if ($gift['is_premium']) {
            $object['is_premium'] = true;
        }
        if ($gift['has_colors']) {
            $object['has_colors'] = true;
        }
        if ($gift['upgrade_variants'] > 0) {
            $object['unique_gift_variant_count'] = $gift['upgrade_variants'];
        }
        if ($gift['background'] !== null) {
            $object['background'] = $gift['background'];
        }

        return $object;
    }

    /**
     * Client::JsonUniqueGift.
     *
     * @param array<string, mixed> $gift
     * @return array<string, mixed>
     */
    public static function uniqueGift(array $gift, Client $client): array
    {
        $object = [
            'gift_id' => (string) $gift['regular_gift_id'],
            'base_name' => $gift['title'],
            'name' => $gift['slug'],
            'number' => $gift['num'],
            'model' => ['name' => $gift['model']['name'], 'sticker' => JsonSticker::make($gift['model']['sticker'], $client)] + self::rarityObject($gift['model']['rarity']),
            'symbol' => ['name' => $gift['pattern']['name'], 'sticker' => JsonSticker::make($gift['pattern']['sticker'], $client)] + self::rarityObject($gift['pattern']['rarity']),
            'backdrop' => [
                'name' => $gift['backdrop']['name'],
                'colors' => [
                    'center_color' => $gift['backdrop']['colors'][0],
                    'edge_color' => $gift['backdrop']['colors'][1],
                    'symbol_color' => $gift['backdrop']['colors'][2],
                    'text_color' => $gift['backdrop']['colors'][3],
                ],
            ] + self::rarityObject($gift['backdrop']['rarity']),
        ];
        if ($gift['released_by'] !== 0) {
            $object['publisher_chat'] = JsonChat::make($gift['released_by'], $client);
        }
        if ($gift['is_premium']) {
            $object['is_premium'] = true;
        }
        if ($gift['colors'] !== null) {
            $colors = $gift['colors'];
            $colors['model_custom_emoji_id'] = (string) $colors['model_custom_emoji_id'];
            $colors['symbol_custom_emoji_id'] = (string) $colors['symbol_custom_emoji_id'];
            $object['colors'] = $colors;
        }
        if ($gift['host_id'] !== 0) {
            $object['is_from_blockchain'] = true;
        }
        if ($gift['is_burned']) {
            $object['is_burned'] = true;
        }

        return $object;
    }

    /**
     * StarGiftAttributeRarity; null when invalid.
     *
     * @param array<string, mixed> $rarity
     * @return array{type: string, per_mille: int}|null
     */
    private static function rarity(array $rarity): ?array
    {
        return match ($rarity['_'] ?? '') {
            'starGiftAttributeRarity' => (int) $rarity['permille'] >= 0 && (int) $rarity['permille'] <= 1000 ? ['type' => '', 'per_mille' => (int) $rarity['permille']] : null,
            'starGiftAttributeRarityUncommon' => ['type' => 'uncommon', 'per_mille' => 0],
            'starGiftAttributeRarityRare' => ['type' => 'rare', 'per_mille' => 0],
            'starGiftAttributeRarityEpic' => ['type' => 'epic', 'per_mille' => 0],
            'starGiftAttributeRarityLegendary' => ['type' => 'legendary', 'per_mille' => 0],
            default => null,
        };
    }

    /**
     * Client::json_store_rarity.
     *
     * @param array{type: string, per_mille: int} $rarity
     * @return array<string, mixed>
     */
    private static function rarityObject(array $rarity): array
    {
        $object = [];
        if ($rarity['type'] !== '') {
            $object['rarity'] = $rarity['type'];
        }
        $object['rarity_per_mille'] = $rarity['per_mille'];

        return $object;
    }

    /**
     * StarGift::fix_availability.
     *
     * @return array{0: int, 1: int}
     */
    private static function fixAvailability(int $total, int $remains): array
    {
        if ($total < 0) {
            $total = 0;
        }
        if (($total !== 0 || $remains !== 0) && ($remains < 0 || $remains > $total)) {
            $remains = $remains < 0 ? 0 : $total;
        }

        return [$total, $remains];
    }

    private static function starCount(int $amount): int
    {
        return $amount < 0 ? 0 : min($amount, 1 << 51);
    }
}
