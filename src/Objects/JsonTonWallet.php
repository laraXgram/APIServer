<?php

declare(strict_types=1);

namespace LaraGram\Server\Objects;

use LaraGram\Server\Bot\Client;
use LaraGram\Server\Http\JsonFloat;

/**
 * TON wallet cards: td::TonWalletCard, TonWalletCardSticker, TonNft and
 * MediaAreaCoordinates built from the TL `wallet.Card` and the Bot API
 * `TonWalletCard`, `TonWalletCardSticker`, `TonNft` and `AreaPosition`
 * (Client::JsonTonWalletCard and the classes it uses).
 */
final class JsonTonWallet
{
    /**
     * TonWalletCard::get_ton_wallet_card; null for no card or an invalid one.
     *
     * @param array<string, mixed>|null $card TL wallet.card
     * @return array<string, mixed>|null
     */
    public static function cardFromServer(?array $card): ?array
    {
        if (($card['_'] ?? '') !== 'wallet.card' || (string) ($card['address'] ?? '') === '') {
            return null;
        }

        $stickers = [];
        foreach ($card['stickers'] ?? [] as $sticker) {
            $position = self::areaPositionFromServer($sticker['coordinates'] ?? null);
            $nft = self::nftFromServer($sticker['nft'] ?? null);
            if ($position === null || $nft === null) {
                // an invalid sticker on the card is skipped
                continue;
            }
            $stickers[] = ['position' => $position, 'nft' => $nft];
        }

        $backgroundNftAddress = '';
        if (isset($card['bg_nft_item'])) {
            $address = (string) ($card['bg_nft_item']['address'] ?? '');
            foreach ($stickers as $sticker) {
                if ($sticker['nft']['address'] === $address) {
                    $backgroundNftAddress = $address;
                    break;
                }
            }
        }

        return [
            'address' => (string) $card['address'],
            'gram_amount' => max(0, (int) ($card['balance'] ?? 0)),
            'stickers' => $stickers,
            'background_nft_address' => $backgroundNftAddress,
        ];
    }

    /**
     * TonNft(td, telegram_api::wallet_nftItem); null for an NFT without address.
     *
     * @param array<string, mixed>|null $item TL wallet.nftItem
     * @return array<string, mixed>|null
     */
    private static function nftFromServer(?array $item): ?array
    {
        if (($item['_'] ?? '') !== 'wallet.nftItem' || (string) ($item['address'] ?? '') === '') {
            return null;
        }

        $gift = null;
        if (isset($item['gift'])) {
            $gift = JsonGift::fromServer($item['gift'], true);
            if ($gift !== null && !$gift['is_unique']) {
                $gift = null;
            }
        }

        return [
            'address' => (string) $item['address'],
            'collection_address' => (string) ($item['collection_address'] ?? ''),
            'index' => (string) ($item['index'] ?? ''),
            'name' => (string) ($item['name'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'attributes' => array_map(static fn (array $attribute): array => [
                'trait_type' => (string) ($attribute['trait_type'] ?? ''),
                'value' => (string) ($attribute['value'] ?? ''),
            ], $item['attributes'] ?? []),
            'gift' => $gift,
        ];
    }

    /**
     * MediaAreaCoordinates(telegram_api::mediaAreaCoordinates); null when invalid.
     *
     * @param array<string, mixed>|null $coordinates TL mediaAreaCoordinates
     * @return array<string, float>|null
     */
    public static function areaPositionFromServer(?array $coordinates): ?array
    {
        if ($coordinates === null) {
            return null;
        }
        $fix = static function (mixed $value, float $min = 0.0, float $max = 100.0): float {
            $value = (float) $value;

            return is_finite($value) ? max($min, min($max, $value)) : 0.0;
        };

        $rotation = $fix($coordinates['rotation'] ?? 0.0, -360.0, 360.0);
        $position = [
            'x' => $fix($coordinates['x'] ?? 0.0),
            'y' => $fix($coordinates['y'] ?? 0.0),
            'width' => $fix($coordinates['w'] ?? 0.0),
            'height' => $fix($coordinates['h'] ?? 0.0),
            'rotation_angle' => $rotation < 0 ? $rotation + 360.0 : $rotation,
            'radius' => $fix($coordinates['radius'] ?? 0.0),
        ];

        return $position['width'] > 0.0 && $position['height'] > 0.0 ? $position : null;
    }

    /**
     * The unique gifts on a card, whose sticker sets must be known
     * (Client::get_ton_wallet_card_sticker_set_ids).
     *
     * @param array<string, mixed>|null $card
     * @return list<array<string, mixed>>
     */
    public static function cardGifts(?array $card): array
    {
        $gifts = [];
        foreach ($card['stickers'] ?? [] as $sticker) {
            if ($sticker['nft']['gift'] !== null) {
                $gifts[] = $sticker['nft']['gift'];
            }
        }

        return $gifts;
    }

    /**
     * Client::JsonTonWalletCard.
     *
     * @param array<string, mixed> $card
     * @return array<string, mixed>
     */
    public static function card(array $card, Client $client): array
    {
        $object = [
            'address' => $card['address'],
            'amount' => (string) $card['gram_amount'],
            'stickers' => array_map(static fn (array $sticker): array => [
                'position' => self::areaPosition($sticker['position']),
                'nft' => self::nft($sticker['nft'], $client),
            ], $card['stickers']),
        ];
        if ($card['background_nft_address'] !== '') {
            $object['background_nft_address'] = $card['background_nft_address'];
        }

        return $object;
    }

    /**
     * Client::JsonAreaPosition.
     *
     * @param array<string, float> $position
     * @return array<string, JsonFloat>
     */
    public static function areaPosition(array $position): array
    {
        return [
            'x_percentage' => new JsonFloat($position['x']),
            'y_percentage' => new JsonFloat($position['y']),
            'width_percentage' => new JsonFloat($position['width']),
            'height_percentage' => new JsonFloat($position['height']),
            'rotation_angle' => new JsonFloat($position['rotation_angle']),
            'corner_radius_percentage' => new JsonFloat($position['radius']),
        ];
    }

    /**
     * Client::JsonTonNft.
     *
     * @param array<string, mixed> $nft
     * @return array<string, mixed>
     */
    private static function nft(array $nft, Client $client): array
    {
        $object = ['address' => $nft['address']];
        if ($nft['collection_address'] !== '') {
            $object['collection_address'] = $nft['collection_address'];
        }
        $object['index'] = $nft['index'];
        $object['name'] = $nft['name'];
        $object['description'] = $nft['description'];
        if ($nft['attributes'] !== []) {
            $object['attributes'] = $nft['attributes'];
        }
        if ($nft['gift'] !== null) {
            $object['gift'] = JsonGift::uniqueGift($nft['gift'], $client);
        }

        return $object;
    }
}
