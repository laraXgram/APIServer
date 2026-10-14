<?php

declare(strict_types=1);

namespace LaraGram\Server\Messages;

use LaraGram\Server\Bot\Client;
use LaraGram\Server\Entities\FormattedText;
use LaraGram\Server\Entities\TextUtil;
use LaraGram\Server\Files\FileType;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Objects\JsonChecklist;
use LaraGram\Server\Td\PollManager;

/**
 * Builds a {@see MessageInfo} from a TL `message`/`messageService`: the part
 * of TDLib (MessagesManager / MessageContent) the Bot API layer relies on,
 * followed by Client::create_message.
 */
final class MessageConverter
{
    private const DEFAULT_DICE_EMOJI = "\u{1F3B2}";

    public function __construct(private readonly Client $client)
    {
    }

    /** get_action_message_content: actions a business message can't have */
    private const NON_BUSINESS_ACTIONS = [
        'messageActionEmpty', 'messageActionChatCreate', 'messageActionChatEditTitle', 'messageActionChatEditPhoto',
        'messageActionChatDeletePhoto', 'messageActionChatAddUser', 'messageActionChatJoinedByLink',
        'messageActionChatDeleteUser', 'messageActionChatMigrateTo', 'messageActionChannelCreate',
        'messageActionChannelMigrateFrom', 'messageActionSecureValuesSentMe', 'messageActionGroupCall',
        'messageActionInviteToGroupCall', 'messageActionGroupCallScheduled', 'messageActionChatJoinedByRequest',
        'messageActionWebViewDataSentMe', 'messageActionGiveawayLaunch', 'messageActionGiveawayResults',
        'messageActionBoostApply', 'messageActionPaidMessagesPrice', 'messageActionSuggestedPostApproval',
        'messageActionSuggestedPostSuccess', 'messageActionSuggestedPostRefund', 'messageActionNewCreatorPending',
        'messageActionChangeCreator', 'messageActionChatJoinedViaCommunity',
    ];

    /**
     * @param array<string, mixed> $message
     * @param bool $isBusiness a message of a business connection (MessagesManager::get_guest_message_object)
     */
    public function convert(array $message, bool $isBusiness = false): ?MessageInfo
    {
        $type = $message['_'] ?? '';
        if ($type !== 'message' && $type !== 'messageService') {
            return null;
        }

        $chatId = ChatId::fromPeer($message['peer_id'] ?? null);
        if ($chatId === 0) {
            return null;
        }

        $info = new MessageInfo();
        $info->id = (int) $message['id'];
        $info->chatId = $chatId;
        $info->date = (int) ($message['date'] ?? 0);
        // TDLib ignores edit_hide for bots (MessagesManager::create_message)
        $info->editDate = (int) ($message['edit_date'] ?? 0);
        $info->mediaAlbumId = (int) ($message['grouped_id'] ?? 0);
        $info->viaBotUserId = (int) ($message['via_bot_id'] ?? 0);
        $info->senderBusinessBotUserId = (int) ($message['via_business_bot_id'] ?? 0);
        $info->isOutgoing = !empty($message['out']);
        $info->isFromOffline = !empty($message['offline']);
        $info->authorSignature = (string) ($message['post_author'] ?? '');
        $info->senderBoostCount = (int) ($message['from_boosts_applied'] ?? 0);
        $info->senderTag = (string) ($message['from_rank'] ?? '');
        $info->paidMessageStarCount = (int) ($message['paid_message_stars'] ?? 0);
        $info->effectId = (int) ($message['effect'] ?? 0);
        $info->isPaidPost = !empty($message['paid_suggested_post_stars']) || !empty($message['paid_suggested_post_ton']);
        $info->canBeSaved = empty($message['noforwards']);
        $info->suggestedPostInfo = $type === 'message' ? self::suggestedPost($message['suggested_post'] ?? null) : null;

        $this->setForwardInfo($info, $message['fwd_from'] ?? null);
        $this->setSender($info, $message);
        $this->setReplyTo($info, $message['reply_to'] ?? null);

        if ($type === 'messageService') {
            // the reply header of a service message only builds its content (MessagesManager::parse_telegram_api_message)
            $reply = $message['reply_to'] ?? null;
            $replyToMessageId = ($reply['_'] ?? '') === 'messageReplyHeader' && !isset($reply['reply_to_peer_id'])
                ? (int) ($reply['reply_to_msg_id'] ?? 0) : 0;
            $info->replyToMessage = null;
            $info->replyToStory = null;
            $info->content = $isBusiness && in_array($message['action']['_'] ?? '', self::NON_BUSINESS_ACTIONS, true)
                ? ['_' => 'messageUnsupported']
                : (new ServiceConverter($this->client))->convert($message, $chatId, $replyToMessageId);
        } else {
            $info->content = $this->content($message);
        }
        $info->isSelfDestruct = isset($message['media']['ttl_seconds']);
        // MessagesManager::fix_message_topic: a topic starts with its creation message
        if ($info->content['_'] === 'messageForumTopicCreated' && $info->topicId === null && ChatId::type($chatId) === ChatId::TYPE_SUPERGROUP) {
            $info->topicId = ['_' => 'messageTopicForum', 'forum_topic_id' => $info->id];
        }

        if ($type === 'message' && isset($message['reply_markup'])) {
            $info->replyMarkup = $message['reply_markup'];
        }

        return $info;
    }

    /**
     * SuggestedPost::get_suggested_post + get_suggested_post_info_object.
     *
     * @param array<string, mixed>|null $post TL suggestedPost
     * @return array{price: array{currency: string, amount: int}|null, send_date: int, state: string}|null
     */
    private static function suggestedPost(?array $post): ?array
    {
        if ($post === null) {
            return null;
        }
        // SuggestedPostPrice
        $price = null;
        $amount = $post['price'] ?? null;
        if (($amount['_'] ?? '') === 'starsAmount') {
            $stars = max(0, min((int) $amount['amount'], 1 << 51));
            if ($stars !== 0) {
                $price = ['currency' => 'XTR', 'amount' => $stars];
            }
        } elseif (($amount['_'] ?? '') === 'starsTonAmount') {
            $cents = intdiv(max(0, min((int) $amount['amount'], 1 << 51)), 10000000);
            if ($cents !== 0) {
                $price = ['currency' => 'TON', 'amount' => $cents * 10000000];
            }
        }

        return [
            'price' => $price,
            'send_date' => (int) ($post['schedule_date'] ?? 0),
            'state' => !empty($post['accepted']) ? 'approved' : (!empty($post['rejected']) ? 'declined' : 'pending'),
        ];
    }

    /**
     * @param array<string, mixed> $message
     */
    private function setSender(MessageInfo $info, array $message): void
    {
        $from = $message['from_id'] ?? null;
        $chatType = ChatId::type($info->chatId);

        if ($from === null) {
            if ($chatType === ChatId::TYPE_PRIVATE) {
                $info->senderUserId = $info->isOutgoing ? $this->client->getMyId() : $info->chatId;
            } else {
                $info->senderChatId = $info->chatId;
            }
        } elseif (($from['_'] ?? '') === 'peerUser') {
            $info->senderUserId = (int) $from['user_id'];
        } else {
            $info->senderChatId = ChatId::fromPeer($from);
        }

        if ($info->senderChatId !== 0 && !$this->client->isBroadcastChannel($info->chatId)) {
            if ($info->senderChatId === $info->chatId) {
                $info->senderUserId = $this->client->getGroupAnonymousBotUserId();
            } elseif ($info->isAutomaticForward) {
                $info->senderUserId = $this->client->getServiceNotificationsUserId();
            } else {
                $info->senderUserId = $this->client->getChannelBotUserId();
            }
        }
    }

    /**
     * TDLib's MessageForwardInfo → td_api messageForwardInfo (origin + date),
     * and import info for imported messages.
     *
     * @param array<string, mixed>|null $forward TL messageFwdHeader
     */
    private function setForwardInfo(MessageInfo $info, ?array $forward): void
    {
        if ($forward === null) {
            return;
        }

        $info->initialSendDate = (int) ($forward['date'] ?? 0);

        if (!empty($forward['imported'])) {
            $info->isImported = true;
            $info->forwardOrigin = ['_' => 'messageOriginHiddenUser', 'sender_name' => (string) ($forward['from_name'] ?? '')];

            return;
        }

        $info->forwardOrigin = self::forwardOrigin($forward, $this->client);

        $source = $forward['saved_from_peer'] ?? null;
        $info->isAutomaticForward = $source !== null
            && ChatId::type($info->chatId) === ChatId::TYPE_SUPERGROUP
            && !$this->client->isBroadcastChannel($info->chatId)
            && $this->client->isBroadcastChannel(ChatId::fromPeer($source));
    }

    /**
     * @param array<string, mixed> $forward TL messageFwdHeader
     * @return array<string, mixed>
     */
    public static function forwardOrigin(array $forward, Client $client): array
    {
        $from = $forward['from_id'] ?? null;
        $signature = (string) ($forward['post_author'] ?? '');

        if ($from === null) {
            return ['_' => 'messageOriginHiddenUser', 'sender_name' => (string) ($forward['from_name'] ?? '')];
        }

        if (($from['_'] ?? '') === 'peerUser') {
            return ['_' => 'messageOriginUser', 'sender_user_id' => (int) $from['user_id']];
        }

        $chatId = ChatId::fromPeer($from);
        if (!empty($forward['channel_post']) && $client->isBroadcastChannel($chatId)) {
            return [
                '_' => 'messageOriginChannel',
                'chat_id' => $chatId,
                'message_id' => (int) $forward['channel_post'],
                'author_signature' => $signature,
            ];
        }

        return ['_' => 'messageOriginChat', 'sender_chat_id' => $chatId, 'author_signature' => $signature];
    }

    /**
     * @param array<string, mixed>|null $reply TL MessageReplyHeader
     */
    private function setReplyTo(MessageInfo $info, ?array $reply): void
    {
        if ($reply === null) {
            return;
        }

        if (($reply['_'] ?? '') === 'messageReplyStoryHeader') {
            $info->replyToStory = [
                '_' => 'messageReplyToStory',
                'story_poster_chat_id' => ChatId::fromPeer($reply['peer'] ?? null),
                'story_id' => (int) ($reply['story_id'] ?? 0),
            ];

            return;
        }

        if (!empty($reply['forum_topic'])) {
            $info->topicId = [
                '_' => 'messageTopicForum',
                'forum_topic_id' => (int) ($reply['reply_to_top_id'] ?? $reply['reply_to_msg_id'] ?? 0),
            ];
        }

        $replyChatId = isset($reply['reply_to_peer_id']) ? ChatId::fromPeer($reply['reply_to_peer_id']) : $info->chatId;
        $origin = isset($reply['reply_from']) ? self::forwardOrigin($reply['reply_from'], $this->client) : null;
        $messageId = (int) ($reply['reply_to_msg_id'] ?? 0);

        if ($messageId === 0 && $origin === null && !isset($reply['quote_text'])) {
            return;
        }

        $quote = null;
        if (isset($reply['quote_text'])) {
            $quote = [
                'text' => FormattedText::fromServer((string) $reply['quote_text'], $reply['quote_entities'] ?? []),
                'position' => (int) ($reply['quote_offset'] ?? 0),
                'is_manual' => !empty($reply['quote']),
            ];
        }

        $replyTo = [
            '_' => 'messageReplyToMessage',
            'chat_id' => $origin !== null && !isset($reply['reply_to_peer_id']) && $messageId === 0 ? 0 : $replyChatId,
            'message_id' => $messageId,
            'quote' => $quote,
            'checklist_task_id' => (int) ($reply['todo_item_id'] ?? 0),
            'poll_option_id' => (string) ($reply['poll_option'] ?? ''),
            'origin' => $origin,
            'origin_send_date' => $origin !== null ? (int) ($reply['reply_from']['date'] ?? 0) : 0,
            'content' => $origin !== null ? $this->replyContent($reply['reply_media'] ?? null, (int) ($reply['reply_from']['date'] ?? 0)) : null,
        ];

        if ($replyTo['chat_id'] !== $info->chatId && $origin === null) {
            // Client::create_message drops replies to other chats without an origin.
            return;
        }

        $info->replyToMessage = $replyTo;
    }

    /**
     * The content of an external reply (RepliedMessageInfo): supported types only.
     *
     * @param array<string, mixed>|null $media TL MessageMedia
     * @return array<string, mixed>|null
     */
    private function replyContent(?array $media, int $originDate): ?array
    {
        if ($media === null || $media['_'] === 'messageMediaEmpty') {
            return null;
        }

        $content = $this->mediaContent(['media' => $media, 'date' => $originDate]);
        switch ($content['_']) {
            case 'messageExpiredPhoto':
            case 'messageExpiredVideo':
            case 'messageExpiredVideoNote':
            case 'messageExpiredVoiceNote':
            case 'messageLiveLocation':
            case 'messageUnsupported':
                return null;
            case 'messageText':
                return $content['link_preview_options'] === null ? null : $content;
            default:
                return $content;
        }
    }

    /**
     * td_api::linkPreviewOptions of a received text message, or null when
     * every option has its default value.
     *
     * @return array<string, mixed>|null
     */
    private static function linkPreviewOptions(bool $isDisabled, string $url, bool $small, bool $large, bool $aboveText): ?array
    {
        if (!$isDisabled && $url === '' && !$small && !$large && !$aboveText) {
            return null;
        }

        $options = [];
        if ($isDisabled) {
            $options['is_disabled'] = true;
        }
        if ($url !== '') {
            $options['url'] = $url;
        }
        if ($small) {
            $options['prefer_small_media'] = true;
        }
        if ($large) {
            $options['prefer_large_media'] = true;
        }
        if ($aboveText) {
            $options['show_above_text'] = true;
        }

        return $options;
    }

    /**
     * The alternative qualities of a video: every alternative document that
     * has a matching HLS playlist named "mtproto:<document id>".
     *
     * @param list<array<string, mixed>> $altDocuments TL documents
     * @return list<array<string, mixed>>
     */
    private static function alternativeVideos(array $altDocuments): array
    {
        $hlsNames = [];
        $candidates = [];
        foreach ($altDocuments as $document) {
            if (($document['_'] ?? '') !== 'document') {
                continue;
            }
            $mime = (string) ($document['mime_type'] ?? '');
            if ($mime === 'application/x-mpegurl') {
                $parsed = MediaConverter::document($document);
                if ($parsed !== null && $parsed['kind'] === MediaConverter::DOCUMENT_GENERAL) {
                    $hlsNames[$parsed['file_name']] = true;
                }
                continue;
            }
            if ($mime === 'application/x-tgstoryboard' || $mime === 'application/x-tgstoryboardmap') {
                continue;
            }
            $parsed = MediaConverter::document($document, false, true);
            if ($parsed !== null && $parsed['kind'] === MediaConverter::DOCUMENT_VIDEO) {
                $candidates[] = [(int) $document['id'], $parsed];
            }
        }

        $videos = [];
        foreach ($candidates as [$id, $video]) {
            if (isset($hlsNames['mtproto:'.$id])) {
                $videos[] = [
                    'width' => $video['width'],
                    'height' => $video['height'],
                    'codec' => (string) ($video['video']['video_codec'] ?? ''),
                    'file' => $video['file'],
                ];
            }
        }

        return $videos;
    }

    /**
     * td::Location from a TL geoPoint; null for geoPointEmpty or invalid coordinates.
     *
     * @param array<string, mixed> $geo
     * @return array{latitude: float, longitude: float, horizontal_accuracy: float}|null
     */
    public static function location(array $geo): ?array
    {
        if (($geo['_'] ?? '') !== 'geoPoint') {
            return null;
        }

        $latitude = (float) $geo['lat'];
        $longitude = (float) $geo['long'];
        if (!is_finite($latitude) || !is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180) {
            return null;
        }

        $accuracy = (float) ($geo['accuracy_radius'] ?? 0);

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'horizontal_accuracy' => $accuracy < 0 ? 0.0 : min($accuracy, 1500.0),
        ];
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function content(array $message): array
    {
        return $this->mediaContent($message);
    }

    /**
     * get_message_content of a media without a text (poll options, explanations, …).
     *
     * @param array<string, mixed> $media TL MessageMedia
     * @return array<string, mixed>
     */
    public function contentFromMedia(array $media): array
    {
        return $this->mediaContent(['media' => $media]);
    }

    /**
     * td::get_message_content.
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function mediaContent(array $message): array
    {
        $text = FormattedText::fromServer((string) ($message['message'] ?? ''), $message['entities'] ?? []);
        $media = $message['media'] ?? null;
        $aboveMedia = !empty($message['invert_media']);
        $messageDate = (int) ($message['date'] ?? 0);

        if ($media === null || $media['_'] === 'messageMediaEmpty') {
            if (isset($message['rich_message'])) {
                return ['_' => 'messageRichText', 'rich_message' => $message['rich_message']];
            }
            // A URL in a message sent without a preview means the preview was disabled.
            $disabled = TextUtil::getFirstUrl($text) !== '';

            return ['_' => 'messageText', 'text' => $text, 'link_preview_options' => self::linkPreviewOptions($disabled, '', false, false, $aboveMedia)];
        }

        $content = $this->mediaContentOf($media, $text, $aboveMedia, $messageDate, (int) ($message['via_bot_id'] ?? 0));
        if ($content !== null) {
            return $content;
        }

        // explicit empty media message
        return ['_' => 'messageText', 'text' => $text, 'link_preview_options' => null];
    }

    /**
     * The media part of td::get_message_content; null where TDLib falls back to a text message.
     *
     * @param array<string, mixed> $media
     * @return array<string, mixed>|null
     */
    private function mediaContentOf(array $media, FormattedText $text, bool $aboveMedia, int $messageDate, int $viaBotUserId): ?array
    {
        switch ($media['_']) {
            case 'messageMediaWebPage':
                // Bots always see the URL of the preview (MessageContent.cpp).
                $page = $media['webpage'] ?? [];
                $url = in_array($page['_'] ?? '', ['webPage', 'webPageEmpty', 'webPagePending'], true) ? (string) ($page['url'] ?? '') : '';

                return ['_' => 'messageText', 'text' => $text, 'web_page_url' => $url, 'link_preview_options' => self::linkPreviewOptions(
                    false, $url, !empty($media['force_small_media']), !empty($media['force_large_media']), $aboveMedia,
                )];

            case 'messageMediaPhoto':
                if (!isset($media['photo'])) {
                    return isset($media['ttl_seconds']) ? ['_' => 'messageExpiredPhoto'] : null;
                }
                $isSelfDestructing = isset($media['ttl_seconds']);
                $photo = MediaConverter::photo($media['photo'], $isSelfDestructing ? FileType::SELF_DESTRUCTING_PHOTO : FileType::PHOTO);
                if ($photo === null) {
                    return ['_' => 'messageExpiredPhoto'];
                }

                return [
                    '_' => 'messagePhoto',
                    'photo' => $photo,
                    'video' => !empty($media['live_photo']) && isset($media['video']) ? MediaConverter::livePhotoVideo($media['video'], $isSelfDestructing) : null,
                    'caption' => $text,
                    'show_caption_above_media' => $aboveMedia,
                    'has_spoiler' => !empty($media['spoiler']),
                ];

            case 'messageMediaDocument':
                $isSelfDestructing = isset($media['ttl_seconds']);
                if (!isset($media['document'])) {
                    if (!$isSelfDestructing) {
                        return null;
                    }

                    return ['_' => match (true) {
                        !empty($media['voice']) => 'messageExpiredVoiceNote',
                        !empty($media['round']) => 'messageExpiredVideoNote',
                        default => 'messageExpiredVideo',
                    }];
                }
                $document = MediaConverter::document($media['document'], $isSelfDestructing);
                if ($document === null) {
                    return null;
                }

                $spoiler = !empty($media['spoiler']);

                return match ($document['kind']) {
                    MediaConverter::DOCUMENT_ANIMATION => ['_' => 'messageAnimation', 'animation' => $document, 'caption' => $text, 'show_caption_above_media' => $aboveMedia, 'has_spoiler' => $spoiler],
                    MediaConverter::DOCUMENT_AUDIO => ['_' => 'messageAudio', 'audio' => $document, 'caption' => $text],
                    MediaConverter::DOCUMENT_STICKER => ['_' => 'messageSticker', 'sticker' => $document, 'is_premium' => empty($media['nopremium'])],
                    MediaConverter::DOCUMENT_VIDEO => [
                        '_' => 'messageVideo',
                        'video' => $document,
                        'cover' => isset($media['video_cover']) ? MediaConverter::photo($media['video_cover'], $isSelfDestructing ? FileType::SELF_DESTRUCTING_PHOTO : FileType::PHOTO) : null,
                        'start_timestamp' => (int) ($media['video_timestamp'] ?? 0),
                        'alternative_videos' => self::alternativeVideos($media['alt_documents'] ?? []),
                        'caption' => $text,
                        'show_caption_above_media' => $aboveMedia,
                        'has_spoiler' => $spoiler,
                    ],
                    MediaConverter::DOCUMENT_VIDEO_NOTE => ['_' => 'messageVideoNote', 'video_note' => $document],
                    MediaConverter::DOCUMENT_VOICE_NOTE => ['_' => 'messageVoiceNote', 'voice_note' => $document, 'caption' => $text],
                    default => ['_' => 'messageDocument', 'document' => $document, 'caption' => $text],
                };

            case 'messageMediaContact':
                return ['_' => 'messageContact', 'contact' => [
                    'phone_number' => (string) $media['phone_number'],
                    'first_name' => (string) $media['first_name'],
                    'last_name' => (string) $media['last_name'],
                    'vcard' => (string) $media['vcard'],
                    'user_id' => (int) $media['user_id'],
                ]];

            case 'messageMediaGeo':
                $location = self::location($media['geo'] ?? []);

                return $location === null ? null : ['_' => 'messageLocation', 'location' => $location];

            case 'messageMediaGeoLive':
                $location = self::location($media['geo'] ?? []);

                return $location === null ? null : [
                    '_' => 'messageLiveLocation',
                    'location' => $location,
                    'live_period' => (int) $media['period'],
                    'heading' => (int) ($media['heading'] ?? 0),
                    'proximity_alert_radius' => (int) ($media['proximity_notification_radius'] ?? 0),
                ];

            case 'messageMediaVenue':
                $location = self::location($media['geo'] ?? []);

                return $location === null ? null : ['_' => 'messageVenue', 'venue' => [
                    'location' => $location,
                    'title' => (string) $media['title'],
                    'address' => (string) $media['address'],
                    'provider' => (string) $media['provider'],
                    'id' => (string) $media['venue_id'],
                    'type' => (string) $media['venue_type'],
                ]];

            case 'messageMediaDice':
                $isStake = isset($media['game_outcome']);
                $emoji = (string) $media['emoticon'];
                if ($isStake && $emoji !== self::DEFAULT_DICE_EMOJI) {
                    return ['_' => 'messageUnsupported'];
                }
                $emoji = $emoji === '' ? self::DEFAULT_DICE_EMOJI : self::removeEmojiModifiers($emoji);
                $value = (int) $media['value'];
                if (!self::isValidDice($emoji, $value, $isStake, $media['game_outcome'] ?? null)) {
                    return null;
                }

                return $isStake
                    ? ['_' => 'messageStakeDice', 'value' => $value]
                    : ['_' => 'messageDice', 'emoji' => $emoji, 'value' => $value];

            case 'messageMediaGame':
                $game = $media['game'] ?? [];
                if ((string) ($game['short_name'] ?? '') === '') {
                    return null;
                }
                $animation = null;
                if (($game['document']['_'] ?? '') === 'document') {
                    $document = MediaConverter::document($game['document']);
                    if ($document !== null && $document['kind'] === MediaConverter::DOCUMENT_ANIMATION) {
                        $animation = $document;
                    }
                }

                return ['_' => 'messageGame', 'game' => [
                    'id' => (int) ($game['id'] ?? 0),
                    'short_name' => (string) $game['short_name'],
                    'title' => (string) ($game['title'] ?? ''),
                    'text' => $text,
                    'description' => (string) ($game['description'] ?? ''),
                    'photo' => MediaConverter::photo($game['photo'] ?? []) ?? ['sizes' => []],
                    'animation' => $animation,
                    'bot_user_id' => $viaBotUserId,
                ]];

            case 'messageMediaInvoice':
                $totalAmount = (int) ($media['total_amount'] ?? 0);
                if ($totalAmount <= 0 || $totalAmount > 999999999999) {
                    $totalAmount = 0;
                }

                return [
                    '_' => 'messageInvoice',
                    'title' => (string) ($media['title'] ?? ''),
                    'description' => (string) ($media['description'] ?? ''),
                    'currency' => (string) ($media['currency'] ?? ''),
                    'total_amount' => $totalAmount,
                    'start_parameter' => (string) ($media['start_param'] ?? ''),
                    'is_test' => !empty($media['test']),
                    'need_shipping_address' => !empty($media['shipping_address_requested']),
                    'receipt_message_id' => (int) ($media['receipt_msg_id'] ?? 0),
                    'paid_media' => isset($media['extended_media']) ? $this->paidMedia($media['extended_media']) : null,
                    'paid_media_caption' => $text,
                ];

            case 'messageMediaPoll':
                $polls = $this->client->polls();
                $pollId = $polls->onGetPoll(0, $media['poll'] ?? null, $media['results'] ?? [], $this);
                if ($pollId === 0) {
                    return null;
                }
                $attached = null;
                if (isset($media['attached_media'])) {
                    $attached = $this->contentFromMedia($media['attached_media']);
                    if (!PollManager::isAllowedPollContent($attached['_'])) {
                        $attached = null;
                    }
                }

                return [
                    '_' => 'messagePoll',
                    'poll' => $polls->getPollObject($pollId),
                    'description' => $text,
                    'media' => PollManager::pollMediaObject($attached),
                ];

            case 'messageMediaStory':
                $chatId = ChatId::fromPeer($media['peer'] ?? null);
                $storyId = (int) ($media['id'] ?? 0);
                if ($chatId === 0 || $storyId <= 0) {
                    return null;
                }

                return ['_' => 'messageStory', 'story_poster_chat_id' => $chatId, 'story_id' => $storyId, 'via_mention' => !empty($media['via_mention'])];

            case 'messageMediaGiveaway':
                $chatIds = [];
                foreach ($media['channels'] ?? [] as $channelId) {
                    if ((int) $channelId > 0) {
                        $chatIds[] = ChatId::fromChannel((int) $channelId);
                    }
                }
                $quantity = (int) ($media['quantity'] ?? 0);
                $months = (int) ($media['months'] ?? 0);
                $stars = self::starCount((int) ($media['stars'] ?? 0));
                $untilDate = (int) ($media['until_date'] ?? 0);
                if ($chatIds === [] || $quantity <= 0 || ($months <= 0 && $stars <= 0) || $untilDate < 0) {
                    return null;
                }

                return [
                    '_' => 'messageGiveaway',
                    'parameters' => [
                        'boosted_chat_id' => array_shift($chatIds),
                        'additional_chat_ids' => $chatIds,
                        'winners_selection_date' => $untilDate,
                        'only_new_members' => !empty($media['only_new_subscribers']),
                        'has_public_winners' => !empty($media['winners_are_visible']),
                        'country_codes' => array_values(array_map('strval', $media['countries_iso2'] ?? [])),
                        'prize_description' => (string) ($media['prize_description'] ?? ''),
                    ],
                    'winner_count' => $quantity,
                    'prize' => $months !== 0 ? ['_' => 'giveawayPrizePremium', 'month_count' => $months] : ['_' => 'giveawayPrizeStars', 'star_count' => $stars],
                ];

            case 'messageMediaGiveawayResults':
                $giveawayMessageId = (int) ($media['launch_msg_id'] ?? 0);
                $channelId = (int) ($media['channel_id'] ?? 0);
                $months = (int) ($media['months'] ?? 0);
                $stars = self::starCount((int) ($media['stars'] ?? 0));
                if ($giveawayMessageId <= 0 || $channelId <= 0 || (int) ($media['additional_peers_count'] ?? 0) < 0
                    || ($months <= 0 && $stars <= 0) || (int) ($media['until_date'] ?? 0) <= 0
                    || (int) ($media['winners_count'] ?? 0) < 0 || (int) ($media['unclaimed_count'] ?? 0) < 0) {
                    return null;
                }
                $winners = [];
                foreach ($media['winners'] ?? [] as $winner) {
                    if ((int) $winner <= 0) {
                        break;
                    }
                    $winners[] = (int) $winner;
                }

                return [
                    '_' => 'messageGiveawayWinners',
                    'boosted_chat_id' => ChatId::fromChannel($channelId),
                    'giveaway_message_id' => $giveawayMessageId,
                    'additional_chat_count' => (int) ($media['additional_peers_count'] ?? 0),
                    'actual_winners_selection_date' => (int) $media['until_date'],
                    'only_new_members' => !empty($media['only_new_subscribers']),
                    'was_refunded' => !empty($media['refunded']),
                    'prize' => $months !== 0 ? ['_' => 'giveawayPrizePremium', 'month_count' => $months] : ['_' => 'giveawayPrizeStars', 'star_count' => $stars],
                    'prize_description' => (string) ($media['prize_description'] ?? ''),
                    'winner_count' => (int) $media['winners_count'],
                    'winner_user_ids' => $winners,
                    'unclaimed_prize_count' => (int) $media['unclaimed_count'],
                ];

            case 'messageMediaPaidMedia':
                return [
                    '_' => 'messagePaidMedia',
                    'star_count' => self::starCount((int) ($media['stars_amount'] ?? 0)),
                    'media' => array_values(array_filter(array_map(fn (array $extended): ?array => $this->paidMedia($extended), $media['extended_media'] ?? []))),
                    'caption' => $text,
                    'show_caption_above_media' => $aboveMedia,
                ];

            case 'messageMediaToDo':
                return ['_' => 'messageChecklist', 'list' => JsonChecklist::fromServer($media['todo'] ?? [], $media['completions'] ?? [], $messageDate)];

            default:
                return ['_' => 'messageUnsupported'];
        }
    }

    /**
     * MessageExtendedMedia(td, telegram_api::MessageExtendedMedia) → get_paid_media_object.
     *
     * @param array<string, mixed> $extended
     * @return array<string, mixed>|null
     */
    public function paidMedia(array $extended): ?array
    {
        if (($extended['_'] ?? '') === 'messageExtendedMediaPreview') {
            [$width, $height] = MediaConverter::dimensions((int) ($extended['w'] ?? 0), (int) ($extended['h'] ?? 0));

            return ['_' => 'paidMediaPreview', 'width' => $width, 'height' => $height, 'duration' => (int) ($extended['video_duration'] ?? 0)];
        }

        $media = $extended['media'] ?? [];
        switch ($media['_'] ?? '') {
            case 'messageMediaPhoto':
                $photo = isset($media['photo']) ? MediaConverter::photo($media['photo']) : null;
                if ($photo === null) {
                    break;
                }

                return [
                    '_' => 'paidMediaPhoto',
                    'photo' => $photo,
                    'video' => !empty($media['live_photo']) && isset($media['video']) ? MediaConverter::livePhotoVideo($media['video']) : null,
                ];
            case 'messageMediaDocument':
                if (($media['document']['_'] ?? '') !== 'document') {
                    break;
                }
                $video = MediaConverter::document($media['document'], false, true);
                if ($video === null || $video['kind'] !== MediaConverter::DOCUMENT_VIDEO) {
                    break;
                }

                return [
                    '_' => 'paidMediaVideo',
                    'video' => $video,
                    'cover' => isset($media['video_cover']) ? MediaConverter::photo($media['video_cover']) : null,
                    'start_timestamp' => max(0, (int) ($media['video_timestamp'] ?? 0)),
                ];
        }

        return ['_' => 'paidMediaUnsupported'];
    }

    /**
     * StarManager::get_star_count.
     */
    private static function starCount(int $amount): int
    {
        return $amount < 0 ? 0 : min($amount, 1 << 51);
    }

    /**
     * MessageDice::is_valid.
     *
     * @param array<string, mixed>|null $outcome
     */
    private static function isValidDice(string $emoji, int $value, bool $isStake, ?array $outcome): bool
    {
        if ($value < 0) {
            return false;
        }
        if ($emoji === self::DEFAULT_DICE_EMOJI || $emoji === "\u{1F3AF}") {
            if ($value > 6) {
                return false;
            }
        } elseif ($value > 1000) {
            return false;
        }
        if ($isStake) {
            $seed = (string) ($outcome['seed'] ?? '');
            if ($value !== 0) {
                if ($seed === '' || (int) ($outcome['stake_ton_amount'] ?? 0) <= 0 || (int) ($outcome['ton_amount'] ?? 0) < 0) {
                    return false;
                }
            } else {
                // a received stake has no state hash
                return false;
            }
        }

        return true;
    }

    /**
     * td::remove_emoji_modifiers.
     */
    public static function removeEmojiModifiers(string $emoji): string
    {
        static $modifiers = ["\u{FE0F}", "\u{200D}\u{2640}", "\u{200D}\u{2642}", "\u{1F3FB}", "\u{1F3FC}", "\u{1F3FD}", "\u{1F3FE}", "\u{1F3FF}"];

        $result = '';
        $length = strlen($emoji);
        for ($i = 0; $i < $length;) {
            foreach ($modifiers as $modifier) {
                if (substr_compare($emoji, $modifier, $i, strlen($modifier)) === 0) {
                    $i += strlen($modifier);
                    continue 2;
                }
            }
            $result .= $emoji[$i++];
        }

        return $result === '' ? $emoji : $result;
    }
}
