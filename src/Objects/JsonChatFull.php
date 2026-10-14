<?php

declare(strict_types=1);

namespace LaraGram\Server\Objects;

use LaraGram\Server\Bot\Client;
use LaraGram\Server\Files\FileType;
use LaraGram\Server\Files\PhotoSizeSource;
use LaraGram\Server\Files\RemoteFileLocation;
use LaraGram\Server\Messages\MediaConverter;
use LaraGram\Server\Messages\MessageConverter;
use LaraGram\Server\Td\ParticipantStatus;

/**
 * The `ChatFullInfo` of getChat (Client::JsonChat with is_full = true): the
 * short chat fields, then the type-specific and common full fields in the
 * official order. The values follow TDLib's td_api::chat and *FullInfo,
 * built here from the TL objects and their full information.
 */
final class JsonChatFull
{
    /** reactions_uniq_max (TDLib's default; bots don't load the app config) */
    private const REACTIONS_UNIQ_MAX = 11;

    /**
     * @param array<string, mixed> $full TL userFull, chatFull or channelFull
     * @return array<string, mixed>
     */
    public static function make(int $chatId, Client $client, array $full, int $pinnedMessageId): array
    {
        $object = JsonChat::make($chatId, $client);
        $photo = null;
        $dialogAccessHash = 0;

        switch (ChatId::type($chatId)) {
            case ChatId::TYPE_PRIVATE:
                $user = $client->getUser($chatId) ?? [];
                $dialogAccessHash = (int) ($user['access_hash'] ?? 0);
                if (empty($user['bot']) && empty($user['deleted']) && $user !== []) {
                    $object['can_send_gift'] = true;
                }
                $usernames = JsonUser::activeUsernames($user);
                if ($usernames !== []) {
                    $object['active_usernames'] = $usernames;
                }
                // UserManager::get_user_full_info_object: bots have bot info instead of a bio;
                // voice restrictions and business information are shown for premium users only
                $isPremium = !empty($user['premium']);
                if (empty($user['bot']) && ($full['about'] ?? '') !== '') {
                    $object['bio'] = $full['about'];
                }
                if (isset($full['private_forward_name'])) {
                    $object['has_private_forwards'] = true;
                }
                if ($isPremium && !empty($full['voice_messages_forbidden'])) {
                    $object['has_restricted_voice_and_video_messages'] = true;
                }
                if (!$isPremium) {
                    unset($full['business_intro'], $full['business_location'], $full['business_work_hours']);
                }
                if (isset($full['business_intro'])) {
                    $object['business_intro'] = self::businessIntro($full['business_intro'], $client);
                }
                if (isset($full['business_location'])) {
                    $object['business_location'] = self::businessLocation($full['business_location']);
                }
                if (isset($full['business_work_hours'])) {
                    $object['business_opening_hours'] = self::businessOpeningHours($full['business_work_hours']);
                }
                $disallowed = $full['disallowed_gifts'] ?? [];
                if (!empty($user['bot']) || !empty($user['deleted'])) {
                    // StarGiftSettings::allow_nothing
                    $disallowed = array_fill_keys(['disallow_unlimited_stargifts', 'disallow_limited_stargifts',
                        'disallow_unique_stargifts', 'disallow_premium_gifts', 'disallow_stargifts_from_channels'], true);
                }
                $object['accepted_gift_types'] = [
                    'unlimited_gifts' => empty($disallowed['disallow_unlimited_stargifts']),
                    'limited_gifts' => empty($disallowed['disallow_limited_stargifts']),
                    'unique_gifts' => empty($disallowed['disallow_unique_stargifts']),
                    'premium_subscription' => empty($disallowed['disallow_premium_gifts']),
                    'gifts_from_channels' => empty($disallowed['disallow_stargifts_from_channels']),
                ];
                if (isset($full['birthday'])) {
                    $birthdate = ['day' => (int) $full['birthday']['day'], 'month' => (int) $full['birthday']['month']];
                    if (!empty($full['birthday']['year'])) {
                        $birthdate['year'] = (int) $full['birthday']['year'];
                    }
                    $object['birthdate'] = $birthdate;
                }
                if (!empty($full['personal_channel_id'])) {
                    $object['personal_chat'] = JsonChat::make(ChatId::fromChannel((int) $full['personal_channel_id']), $client);
                }
                if (isset($full['stars_rating'])) {
                    $rating = $full['stars_rating'];
                    $object['rating'] = [
                        'level' => (int) $rating['level'],
                        'rating' => (int) $rating['stars'],
                        'current_level_rating' => (int) $rating['current_level_stars'],
                    ];
                    if (isset($rating['next_level_stars'])) {
                        $object['rating']['next_level_rating'] = (int) $rating['next_level_stars'];
                    }
                }
                if (isset($full['saved_music']) && ($audio = MediaConverter::document($full['saved_music'], false, false)) !== null) {
                    $object['first_profile_audio'] = JsonMedia::audio($audio);
                }
                if (!empty($full['send_paid_messages_stars'])) {
                    $object['paid_message_star_count'] = (int) $full['send_paid_messages_stars'];
                }
                $photo = $user['photo'] ?? null;
                break;

            case ChatId::TYPE_GROUP:
                $chat = $client->getBasicGroup(ChatId::toBasicGroupId($chatId)) ?? [];
                if (($full['about'] ?? '') !== '') {
                    $object['description'] = $full['about'];
                }
                $inviteLink = (string) ($full['exported_invite']['link'] ?? '');
                if ($inviteLink !== '') {
                    $object['invite_link'] = $inviteLink;
                }
                $object['permissions'] = JsonChatPermissions::make(ChatPermissions::fromBannedRights($chat['default_banned_rights'] ?? null));
                $object['accepted_gift_types'] = [
                    'unlimited_gifts' => false, 'limited_gifts' => false, 'unique_gifts' => false,
                    'premium_subscription' => false, 'gifts_from_channels' => false,
                ];
                $photo = $chat['photo'] ?? null;
                break;

            case ChatId::TYPE_SUPERGROUP:
                $channel = $client->getChannel(ChatId::toChannelId($chatId)) ?? [];
                $dialogAccessHash = (int) ($channel['access_hash'] ?? 0);
                $isSupergroup = !empty($channel['megagroup']);
                $usernames = JsonUser::activeUsernames($channel);
                if ($usernames !== []) {
                    $object['active_usernames'] = $usernames;
                }
                if (($full['about'] ?? '') !== '') {
                    $object['description'] = $full['about'];
                }
                $inviteLink = (string) ($full['exported_invite']['link'] ?? '');
                if ($inviteLink !== '') {
                    $object['invite_link'] = $inviteLink;
                }
                if (($name = (string) ($full['stickerset']['short_name'] ?? '')) !== '') {
                    $object['sticker_set_name'] = $name;
                }
                if (($name = (string) ($full['emojiset']['short_name'] ?? '')) !== '') {
                    $object['custom_emoji_sticker_set_name'] = $name;
                }
                $canSendGift = !empty($full['stargifts_available']);
                if ($canSendGift) {
                    $object['can_send_gift'] = true;
                }
                if (!empty($full['can_set_stickers'])) {
                    $object['can_set_sticker_set'] = true;
                }
                if (empty($full['hidden_prehistory'])) {
                    $object['has_visible_history'] = true;
                }
                if ($isSupergroup) {
                    $object['permissions'] = JsonChatPermissions::make(ChatPermissions::fromBannedRights($channel['default_banned_rights'] ?? null));
                    // ChatManager::get_channel_join_to_send / get_channel_join_request
                    if ((!empty($channel['join_to_send']) || empty($channel['megagroup']) || empty($channel['has_link'])) && empty($channel['monoforum'])) {
                        $object['join_to_send_messages'] = true;
                    }
                    if (!empty($channel['join_request']) && !empty($channel['megagroup']) && empty($channel['monoforum']) && empty($channel['gigagroup'])) {
                        $object['join_by_request'] = true;
                    }
                    if (!empty($full['participants_hidden'])) {
                        $object['has_hidden_members'] = true;
                    }
                }
                if (!empty($full['antispam'])) {
                    $object['has_aggressive_anti_spam_enabled'] = true;
                }
                if (!empty($full['slowmode_seconds'])) {
                    $object['slow_mode_delay'] = (int) $full['slowmode_seconds'];
                }
                if (!empty($full['boosts_unrestrict'])) {
                    $object['unrestrict_boost_count'] = (int) $full['boosts_unrestrict'];
                }
                if (!empty($full['linked_chat_id'])) {
                    $object['linked_chat_id'] = ChatId::fromChannel((int) $full['linked_chat_id']);
                }
                if (!empty($channel['monoforum']) && !empty($channel['linked_monoforum_id'])) {
                    $object['parent_chat'] = JsonChat::make(ChatId::fromChannel((int) $channel['linked_monoforum_id']), $client);
                }
                if (!empty($full['guard_bot_id'])) {
                    $object['guard_bot'] = JsonUser::make((int) $full['guard_bot_id'], $client);
                }
                if (($full['location']['_'] ?? '') === 'channelLocation' && ($location = MessageConverter::location($full['location']['geo_point'] ?? [])) !== null) {
                    $object['location'] = ['location' => JsonMedia::location($location), 'address' => (string) $full['location']['address']];
                }
                if (!empty($full['paid_media_allowed']) && !$isSupergroup) {
                    $object['can_send_paid_media'] = true;
                }
                $object['accepted_gift_types'] = [
                    'unlimited_gifts' => $canSendGift, 'limited_gifts' => $canSendGift, 'unique_gifts' => $canSendGift,
                    'premium_subscription' => $canSendGift, 'gifts_from_channels' => $canSendGift,
                ];
                if (!empty($channel['send_paid_messages_stars'])) {
                    $object['paid_message_star_count'] = (int) $channel['send_paid_messages_stars'];
                }
                $photo = $channel['photo'] ?? null;
                break;
        }

        $photoInfo = self::chatPhotoInfo($chatId, $dialogAccessHash, $photo);
        if ($photoInfo !== null) {
            $object['photo'] = $photoInfo;
        }
        if ($pinnedMessageId !== 0) {
            $pinned = $client->getMessage($chatId, $pinnedMessageId, true);
            if ($pinned !== null) {
                $object['pinned_message'] = JsonMessage::make($pinned, false, $client);
            }
        }
        if (!empty($full['ttl_period'])) {
            $object['message_auto_delete_time'] = (int) $full['ttl_period'];
        }

        $entity = match (ChatId::type($chatId)) {
            ChatId::TYPE_PRIVATE => $client->getUser($chatId) ?? [],
            ChatId::TYPE_SUPERGROUP => $client->getChannel(ChatId::toChannelId($chatId)) ?? [],
            default => [],
        };
        $emojiStatus = $entity['emoji_status'] ?? null;
        if (in_array($emojiStatus['_'] ?? '', ['emojiStatus', 'emojiStatusCollectible'], true) && !empty($emojiStatus['document_id'])) {
            $object['emoji_status_custom_emoji_id'] = (string) $emojiStatus['document_id'];
            if (!empty($emojiStatus['until'])) {
                $object['emoji_status_expiration_date'] = (int) $emojiStatus['until'];
            }
        }

        [$availableReactions, $maxReactionCount] = self::availableReactions($chatId, $client, $full);
        if ($availableReactions !== null) {
            $object['available_reactions'] = $availableReactions;
        }
        $object['max_reaction_count'] = $maxReactionCount;

        [$accentColorId, $backgroundEmojiId] = self::accentColor($chatId, $entity);
        $object['accent_color_id'] = $accentColorId;
        if ($backgroundEmojiId !== 0) {
            $object['background_custom_emoji_id'] = (string) $backgroundEmojiId;
        }
        if (($entity['color']['_'] ?? '') === 'peerColorCollectible') {
            $color = $entity['color'];
            $object['unique_gift_colors'] = [
                'model_custom_emoji_id' => (string) $color['gift_emoji_id'],
                'symbol_custom_emoji_id' => (string) $color['background_emoji_id'],
                'light_theme_main_color' => (int) $color['accent_color'],
                'light_theme_other_colors' => array_map('intval', $color['colors'] ?? []),
                'dark_theme_main_color' => (int) ($color['dark_accent_color'] ?? $color['accent_color']),
                'dark_theme_other_colors' => array_map('intval', $color['dark_colors'] ?? $color['colors'] ?? []),
            ];
        }
        $profileColor = $entity['profile_color'] ?? null;
        if (($profileColor['_'] ?? '') === 'peerColor' && isset($profileColor['color'])) {
            $object['profile_accent_color_id'] = (int) $profileColor['color'];
        }
        if (($profileColor['_'] ?? '') === 'peerColor' && !empty($profileColor['background_emoji_id'])) {
            $object['profile_background_custom_emoji_id'] = (string) $profileColor['background_emoji_id'];
        }
        if ($client->getChatHasProtectedContent($chatId)) {
            $object['has_protected_content'] = true;
        }

        return $object;
    }

    /**
     * get_dialog_photo/get_profile_photo → chatPhotoInfo: the small ('a') and big ('c') photos.
     *
     * @param array<string, mixed>|null $photo TL UserProfilePhoto or ChatPhoto
     * @return array<string, string>|null
     */
    public static function chatPhotoInfo(int $chatId, int $dialogAccessHash, ?array $photo): ?array
    {
        if (!in_array($photo['_'] ?? '', ['userProfilePhoto', 'chatPhoto'], true)) {
            return null;
        }
        $photoId = (int) $photo['photo_id'];
        if ($photoId === 0 || $photoId === -2) {
            return null;
        }
        $dcId = (int) $photo['dc_id'];
        $small = RemoteFileLocation::photo(FileType::PROFILE_PHOTO, $dcId, $photoId, 0, '', PhotoSizeSource::dialogPhoto($chatId, $dialogAccessHash, false));
        $big = RemoteFileLocation::photo(FileType::PROFILE_PHOTO, $dcId, $photoId, 0, '', PhotoSizeSource::dialogPhoto($chatId, $dialogAccessHash, true));

        return [
            'small_file_id' => $small->toFileId(),
            'small_file_unique_id' => $small->toUniqueId(),
            'big_file_id' => $big->toFileId(),
            'big_file_unique_id' => $big->toUniqueId(),
        ];
    }

    /**
     * get_dialog_active_reactions + get_chat_available_reactions_object: the
     * listed reactions (null for "all") and max_reaction_count.
     *
     * @param array<string, mixed> $full
     * @return array{0: list<array<string, mixed>>|null, 1: int}
     */
    private static function availableReactions(int $chatId, Client $client, array $full): array
    {
        $type = ChatId::type($chatId);
        if ($type === ChatId::TYPE_PRIVATE) {
            return [null, self::REACTIONS_UNIQ_MAX];
        }

        $status = $type === ChatId::TYPE_GROUP
            ? ParticipantStatus::fromServerChat($client->getBasicGroup(ChatId::toBasicGroupId($chatId)) ?? ['_' => 'chatForbidden'])
            : ParticipantStatus::fromServerChannel($client->getChannel(ChatId::toChannelId($chatId)) ?? ['_' => 'channelForbidden']);
        $maxCount = self::REACTIONS_UNIQ_MAX;
        $limit = (int) ($full['reactions_limit'] ?? 0);
        if ($status->type === ParticipantStatus::BANNED) {
            return [[], $maxCount];
        }
        if ($limit > 0 && $limit < $maxCount) {
            $maxCount = $limit;
        }

        $reactions = $full['available_reactions'] ?? null;
        if (($reactions['_'] ?? '') === 'chatReactionsAll') {
            // ChatReactions::fix_broadcast_reactions: the active reactions, which bots never load
            if (!$client->isBroadcastChannel($chatId)) {
                return [null, $maxCount];
            }
            $reactions = null;
        }

        $list = [];
        foreach ($reactions['reactions'] ?? [] as $reaction) {
            $list[] = match ($reaction['_'] ?? '') {
                'reactionEmoji' => ['type' => 'emoji', 'emoji' => (string) $reaction['emoticon']],
                'reactionCustomEmoji' => ['type' => 'custom_emoji', 'custom_emoji_id' => (string) $reaction['document_id']],
                'reactionPaid' => ['type' => 'paid'],
                default => null,
            };
        }
        if (!empty($full['paid_reactions_available']) && !in_array(['type' => 'paid'], $list, true)) {
            array_unshift($list, ['type' => 'paid']);
        }

        return [array_values(array_filter($list)), $maxCount];
    }

    /**
     * get_*_accent_color_id_object and the background custom emoji.
     *
     * @param array<string, mixed> $entity TL user or channel
     * @return array{0: int, 1: int}
     */
    private static function accentColor(int $chatId, array $entity): array
    {
        $color = $entity['color'] ?? null;
        $fallback = match (ChatId::type($chatId)) {
            ChatId::TYPE_PRIVATE => $chatId % 7,
            ChatId::TYPE_GROUP => ChatId::toBasicGroupId($chatId) % 7,
            default => ChatId::toChannelId($chatId) % 7,
        };
        $accent = $fallback;
        $background = 0;
        if (($color['_'] ?? '') === 'peerColor') {
            if (isset($color['color']) && (int) $color['color'] >= 0) {
                $accent = (int) $color['color'];
            }
            $background = (int) ($color['background_emoji_id'] ?? 0);
        } elseif (($color['_'] ?? '') === 'peerColorCollectible') {
            $background = (int) ($color['background_emoji_id'] ?? 0);
        }

        return [$accent, $background];
    }

    /**
     * @param array<string, mixed> $intro TL businessIntro
     * @return array<string, mixed>
     */
    private static function businessIntro(array $intro, Client $client): array
    {
        $object = [];
        if (($intro['title'] ?? '') !== '') {
            $object['title'] = $intro['title'];
        }
        if (($intro['description'] ?? '') !== '') {
            $object['message'] = $intro['description'];
        }
        if (isset($intro['sticker']) && ($sticker = MediaConverter::document($intro['sticker'])) !== null) {
            $object['sticker'] = JsonSticker::make($sticker, $client);
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $location TL businessLocation
     * @return array<string, mixed>
     */
    private static function businessLocation(array $location): array
    {
        $object = [];
        if (isset($location['geo_point']) && ($point = MessageConverter::location($location['geo_point'])) !== null) {
            $object['location'] = JsonMedia::location($point);
        }
        $object['address'] = (string) ($location['address'] ?? '');

        return $object;
    }

    /**
     * @param array<string, mixed> $hours TL businessWorkHours
     * @return array<string, mixed>
     */
    private static function businessOpeningHours(array $hours): array
    {
        $intervals = [];
        foreach ($hours['weekly_open'] ?? [] as $interval) {
            $intervals[] = ['opening_minute' => (int) $interval['start_minute'], 'closing_minute' => (int) $interval['end_minute']];
        }

        return ['opening_hours' => $intervals, 'time_zone_name' => (string) ($hours['timezone_id'] ?? '')];
    }
}
