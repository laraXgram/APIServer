<?php

declare(strict_types=1);

namespace LaraGram\Server\Objects;

use LaraGram\Server\Bot\Client;
use LaraGram\Server\Messages\MessageInfo;

/**
 * The Bot API `Message` object (Client::JsonMessage::store).
 *
 * Content types are added here as they are ported; the order of every
 * write follows the official serializer.
 */
final class JsonMessage
{
    /**
     * @return array<string, mixed>
     */
    public static function make(MessageInfo $message, bool $needReply, Client $client): array
    {
        $object = [];

        if ($message->businessConnectionId !== '') {
            $object['business_connection_id'] = $message->businessConnectionId;
            if ($message->senderBusinessBotUserId !== 0) {
                $object['sender_business_bot'] = JsonUser::make($message->senderBusinessBotUserId, $client);
            }
        }
        $object['message_id'] = $message->id;
        if ($message->ephemeralMessageId !== 0) {
            $object['ephemeral_message_id'] = $message->ephemeralMessageId;
        }
        if ($message->senderUserId !== 0) {
            $object['from'] = JsonUser::make($message->senderUserId, $client);
        }
        if ($message->authorSignature !== '') {
            $object['author_signature'] = $message->authorSignature;
        }
        if ($message->senderChatId !== 0) {
            $object['sender_chat'] = JsonChat::make($message->senderChatId, $client);
        }
        $object['chat'] = JsonChat::make($message->chatId, $client);
        $object['date'] = $message->date;
        if ($message->editDate > 0) {
            $object['edit_date'] = $message->editDate;
        }
        $forumTopicId = self::forumTopicId($message->topicId);
        if ($forumTopicId !== 0) {
            $object['message_thread_id'] = $forumTopicId;
        }
        if ($message->receiverUserId !== 0) {
            $object['receiver_user'] = JsonUser::make($message->receiverUserId, $client);
        }

        if ($message->initialSendDate > 0 && $message->forwardOrigin !== null) {
            $object['forward_origin'] = self::origin($message->forwardOrigin, $message->initialSendDate, $client);
            if ($message->isAutomaticForward) {
                $object['is_automatic_forward'] = true;
            }

            $origin = $message->forwardOrigin;
            switch ($origin['_']) {
                case 'messageOriginUser':
                    $object['forward_from'] = JsonUser::make($origin['sender_user_id'], $client);
                    break;
                case 'messageOriginChat':
                    $object['forward_from_chat'] = JsonChat::make($origin['sender_chat_id'], $client);
                    if ($origin['author_signature'] !== '') {
                        $object['forward_signature'] = $origin['author_signature'];
                    }
                    break;
                case 'messageOriginHiddenUser':
                    if ($origin['sender_name'] !== '') {
                        $object['forward_sender_name'] = $origin['sender_name'];
                    }
                    break;
                case 'messageOriginChannel':
                    $object['forward_from_chat'] = JsonChat::make($origin['chat_id'], $client);
                    $object['forward_from_message_id'] = $origin['message_id'];
                    if ($origin['author_signature'] !== '') {
                        $object['forward_signature'] = $origin['author_signature'];
                    }
                    break;
            }
            $object['forward_date'] = $message->initialSendDate;
        }

        if ($needReply) {
            $replyToMessageId = $client->getSameChatReplyToMessageId($message, true);
            if ($replyToMessageId > 0) {
                $replyTo = $client->getReplyToMessageInfo($message, $replyToMessageId);
                if ($replyTo !== null) {
                    $object['reply_to_message'] = self::make($replyTo, false, $client);
                }
            }
        }

        $reply = $message->replyToMessage;
        if ($reply !== null && $reply['quote'] !== null) {
            $quote = ['text' => $reply['quote']['text']->text];
            if ($reply['quote']['text']->entities !== []) {
                $quote['entities'] = JsonEntity::vector($reply['quote']['text']->entities, $client);
            }
            $quote['position'] = $reply['quote']['position'];
            if ($reply['quote']['is_manual']) {
                $quote['is_manual'] = true;
            }
        }
        if ($reply !== null && $reply['origin'] !== null) {
            $object['external_reply'] = self::externalReply($reply, $client);
        }
        if (isset($quote)) {
            $object['quote'] = $quote;
        }
        if ($reply !== null && $reply['checklist_task_id'] !== 0) {
            $object['reply_to_checklist_task_id'] = $reply['checklist_task_id'];
        }
        if ($reply !== null && $reply['poll_option_id'] !== '') {
            $object['reply_to_poll_option_id'] = $reply['poll_option_id'];
        }
        if ($message->replyToStory !== null) {
            $object['reply_to_story'] = [
                'chat' => JsonChat::make($message->replyToStory['story_poster_chat_id'], $client),
                'id' => $message->replyToStory['story_id'],
            ];
        }
        if ($message->mediaAlbumId !== 0) {
            $object['media_group_id'] = (string) $message->mediaAlbumId;
        }
        if ($message->guestQueryId !== 0) {
            $object['guest_query_id'] = (string) $message->guestQueryId;
        }

        self::content($object, $message, $needReply, $client);

        if ($message->replyMarkup !== null && ($markup = JsonReplyMarkup::make($message->replyMarkup)) !== null) {
            $object['reply_markup'] = $markup;
        }
        if ($message->viaBotUserId > 0) {
            $object['via_bot'] = JsonUser::make($message->viaBotUserId, $client);
        }
        if (!$message->canBeSaved) {
            $object['has_protected_content'] = true;
        }
        if (self::isTopicMessage($message->topicId)) {
            $object['is_topic_message'] = true;
        }
        if ($message->isFromOffline) {
            $object['is_from_offline'] = true;
        }
        if ($message->effectId !== 0) {
            $object['effect_id'] = (string) $message->effectId;
        }
        if ($message->senderBoostCount !== 0) {
            $object['sender_boost_count'] = $message->senderBoostCount;
        }
        if ($message->senderTag !== '') {
            $object['sender_tag'] = $message->senderTag;
        }
        if ($message->paidMessageStarCount !== 0) {
            $object['paid_star_count'] = $message->paidMessageStarCount;
        }
        if ($message->isPaidPost) {
            $object['is_paid_post'] = true;
        }
        if ($message->suggestedPostInfo !== null) {
            // Client::JsonSuggestedPostInfo
            $info = $message->suggestedPostInfo;
            $post = [];
            if ($info['price'] !== null) {
                $post['price'] = $info['price'];
            }
            if ($info['send_date'] !== 0) {
                $post['send_date'] = $info['send_date'];
            }
            $post['state'] = $info['state'];
            $object['suggested_post_info'] = $post;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $object
     */
    private static function content(array &$object, MessageInfo $message, bool $needReply, Client $client): void
    {
        $content = $message->content;

        switch ($content['_']) {
            case 'messageText':
                $object['text'] = $content['text']->text;
                if ($content['text']->entities !== []) {
                    $object['entities'] = JsonEntity::vector($content['text']->entities, $client);
                }
                if ($content['link_preview_options'] !== null) {
                    $object['link_preview_options'] = $content['link_preview_options'];
                }
                break;
            case 'messageRichText':
                $object['rich_message'] = JsonRichMessage::make($content['rich_message'], $client);
                break;
            case 'messageAnimation':
                $object['animation'] = JsonMedia::animation($content['animation'], false);
                $object['document'] = JsonMedia::animation($content['animation'], true);
                self::addCaption($object, $content['caption'], $content['show_caption_above_media'], $client);
                self::addMediaSpoiler($object, $content['has_spoiler']);
                break;
            case 'messageAudio':
                $object['audio'] = JsonMedia::audio($content['audio']);
                self::addCaption($object, $content['caption'], false, $client);
                break;
            case 'messageDocument':
                $object['document'] = JsonMedia::document($content['document']);
                self::addCaption($object, $content['caption'], false, $client);
                break;
            case 'messagePhoto':
                if ($content['video'] !== null) {
                    $object['live_photo'] = JsonMedia::livePhoto($content['photo'], $content['video']);
                }
                $object['photo'] = JsonMedia::photo($content['photo']);
                self::addCaption($object, $content['caption'], $content['show_caption_above_media'], $client);
                self::addMediaSpoiler($object, $content['has_spoiler']);
                break;
            case 'messageSticker':
                $object['sticker'] = JsonSticker::make($content['sticker'], $client);
                break;
            case 'messageVideo':
                $object['video'] = JsonMedia::video($content['video'], $content['cover'], $content['start_timestamp'], $content['alternative_videos']);
                self::addCaption($object, $content['caption'], $content['show_caption_above_media'], $client);
                self::addMediaSpoiler($object, $content['has_spoiler']);
                break;
            case 'messageVideoNote':
                $object['video_note'] = JsonMedia::videoNote($content['video_note']);
                break;
            case 'messageVoiceNote':
                $object['voice'] = JsonMedia::voiceNote($content['voice_note']);
                self::addCaption($object, $content['caption'], false, $client);
                break;
            case 'messageContact':
                $object['contact'] = JsonMedia::contact($content['contact']);
                break;
            case 'messageDice':
                $object['dice'] = ['emoji' => $content['emoji'], 'value' => $content['value']];
                break;
            case 'messageGame':
                $object['game'] = self::game($content['game'], $client);
                break;
            case 'messageInvoice':
                $object['invoice'] = [
                    'title' => $content['title'],
                    'description' => $content['description'],
                    'start_parameter' => $content['start_parameter'],
                    'currency' => $content['currency'],
                    'total_amount' => $content['total_amount'],
                ];
                break;
            case 'messageLiveLocation':
                $expiresIn = max(0, $message->date + $content['live_period'] - $client->getUnixTime());
                $object['location'] = JsonMedia::liveLocation($content, $expiresIn);
                break;
            case 'messageLocation':
                $object['location'] = JsonMedia::location($content['location']);
                break;
            case 'messageStakeDice':
                $object['dice'] = ['emoji' => "\u{1F3B2}", 'value' => $content['value']];
                break;
            case 'messageVenue':
                $object['location'] = JsonMedia::location($content['venue']['location']);
                $object['venue'] = JsonMedia::venue($content['venue']);
                break;
            case 'messagePoll':
                $object['poll'] = JsonPoll::make($content['poll'], $content['description'], $content['media'], $client);
                break;
            case 'messagePaidMedia':
                $object['paid_media'] = [
                    'star_count' => $content['star_count'],
                    'paid_media' => array_map(static fn (array $media): array => self::paidMedia($media), $content['media']),
                ];
                self::addCaption($object, $content['caption'], $content['show_caption_above_media'], $client);
                break;
            case 'messageStory':
                $object['story'] = ['chat' => JsonChat::make($content['story_poster_chat_id'], $client), 'id' => $content['story_id']];
                break;
            case 'messageGiveaway':
                $object['giveaway'] = self::giveaway($content, $client);
                break;
            case 'messageGiveawayWinners':
                $object['giveaway_winners'] = self::giveawayWinners($content, $client);
                break;
            case 'messageChecklist':
                $object['checklist'] = JsonChecklist::make($content['list'], $client);
                break;
            default:
                JsonServiceMessage::store($object, $message, $needReply, $client);
                break;
        }
    }

    /**
     * Client::JsonGame.
     *
     * @param array<string, mixed> $game
     * @return array<string, mixed>
     */
    public static function game(array $game, Client $client): array
    {
        $object = ['title' => $game['title']];
        if ($game['text']->text !== '') {
            $object['text'] = $game['text']->text;
        }
        if ($game['text']->entities !== []) {
            $object['text_entities'] = JsonEntity::vector($game['text']->entities, $client);
        }
        $object['description'] = $game['description'];
        $object['photo'] = JsonMedia::photo($game['photo']);
        if ($game['animation'] !== null) {
            $object['animation'] = JsonMedia::animation($game['animation'], false);
        }

        return $object;
    }

    /**
     * Client::JsonPaidMedia.
     *
     * @param array<string, mixed> $media td_api PaidMedia
     * @return array<string, mixed>
     */
    public static function paidMedia(array $media): array
    {
        switch ($media['_']) {
            case 'paidMediaPreview':
                $object = ['type' => 'preview'];
                if ($media['width'] !== 0) {
                    $object['width'] = $media['width'];
                }
                if ($media['height'] !== 0) {
                    $object['height'] = $media['height'];
                }
                if ($media['duration'] !== 0) {
                    $object['duration'] = $media['duration'];
                }

                return $object;
            case 'paidMediaPhoto':
                return $media['video'] !== null
                    ? ['type' => 'live_photo', 'live_photo' => JsonMedia::livePhoto($media['photo'], $media['video'])]
                    : ['type' => 'photo', 'photo' => JsonMedia::photo($media['photo'])];
            case 'paidMediaVideo':
                return ['type' => 'video', 'video' => JsonMedia::video($media['video'], $media['cover'], $media['start_timestamp'])];
            default:
                return ['type' => 'other'];
        }
    }

    /**
     * Client::JsonGiveaway.
     *
     * @param array<string, mixed> $giveaway td_api messageGiveaway
     * @return array<string, mixed>
     */
    private static function giveaway(array $giveaway, Client $client): array
    {
        $parameters = $giveaway['parameters'];
        $chatIds = array_merge([$parameters['boosted_chat_id']], $parameters['additional_chat_ids']);
        $object = [
            'chats' => array_map(static fn (int $chatId): array => JsonChat::make($chatId, $client), $chatIds),
            'winners_selection_date' => $parameters['winners_selection_date'],
            'winner_count' => $giveaway['winner_count'],
        ];
        if ($parameters['only_new_members']) {
            $object['only_new_members'] = true;
        }
        if ($parameters['has_public_winners']) {
            $object['has_public_winners'] = true;
        }
        if ($parameters['country_codes'] !== []) {
            $object['country_codes'] = $parameters['country_codes'];
        }
        if ($parameters['prize_description'] !== '') {
            $object['prize_description'] = $parameters['prize_description'];
        }
        self::storeGiveawayPrize($object, $giveaway['prize']);

        return $object;
    }

    /**
     * Client::JsonGiveawayWinners.
     *
     * @param array<string, mixed> $winners td_api messageGiveawayWinners
     * @return array<string, mixed>
     */
    private static function giveawayWinners(array $winners, Client $client): array
    {
        $object = [
            'chat' => JsonChat::make($winners['boosted_chat_id'], $client),
            'giveaway_message_id' => $winners['giveaway_message_id'],
        ];
        if ($winners['additional_chat_count'] > 0) {
            $object['additional_chat_count'] = $winners['additional_chat_count'];
        }
        $object['winners_selection_date'] = $winners['actual_winners_selection_date'];
        if ($winners['only_new_members']) {
            $object['only_new_members'] = true;
        }
        if ($winners['was_refunded']) {
            $object['was_refunded'] = true;
        }
        self::storeGiveawayPrize($object, $winners['prize']);
        if ($winners['prize_description'] !== '') {
            $object['prize_description'] = $winners['prize_description'];
        }
        $object['winner_count'] = $winners['winner_count'];
        if ($winners['unclaimed_prize_count'] > 0) {
            $object['unclaimed_prize_count'] = $winners['unclaimed_prize_count'];
        }
        $object['winners'] = JsonServiceMessage::users($winners['winner_user_ids'], $client);

        return $object;
    }

    /**
     * @param array<string, mixed> $object
     * @param array<string, mixed> $prize td_api GiveawayPrize
     */
    private static function storeGiveawayPrize(array &$object, array $prize): void
    {
        if ($prize['_'] === 'giveawayPrizePremium') {
            if ($prize['month_count'] > 0) {
                $object['premium_subscription_month_count'] = $prize['month_count'];
            }
        } elseif ($prize['star_count'] > 0) {
            $object['prize_star_count'] = $prize['star_count'];
        }
    }

    /**
     * @param array<string, mixed> $object
     */
    private static function addCaption(array &$object, \LaraGram\Server\Entities\FormattedText $caption, bool $showCaptionAboveMedia, Client $client): void
    {
        if ($caption->text === '') {
            return;
        }

        $object['caption'] = $caption->text;
        if ($caption->entities !== []) {
            $object['caption_entities'] = JsonEntity::vector($caption->entities, $client);
        }
        if ($showCaptionAboveMedia) {
            $object['show_caption_above_media'] = true;
        }
    }

    /**
     * @param array<string, mixed> $object
     */
    private static function addMediaSpoiler(array &$object, bool $hasSpoiler): void
    {
        if ($hasSpoiler) {
            $object['has_media_spoiler'] = true;
        }
    }

    /**
     * Client::JsonMessageOrigin.
     *
     * @param array<string, mixed> $origin
     * @return array<string, mixed>
     */
    public static function origin(array $origin, int $initialSendDate, Client $client): array
    {
        $object = [];

        switch ($origin['_']) {
            case 'messageOriginUser':
                $object['type'] = 'user';
                $object['sender_user'] = JsonUser::make($origin['sender_user_id'], $client);
                break;
            case 'messageOriginChat':
                $object['type'] = 'chat';
                $object['sender_chat'] = JsonChat::make($origin['sender_chat_id'], $client);
                if ($origin['author_signature'] !== '') {
                    $object['author_signature'] = $origin['author_signature'];
                }
                break;
            case 'messageOriginHiddenUser':
                $object['type'] = 'hidden_user';
                if ($origin['sender_name'] !== '') {
                    $object['sender_user_name'] = $origin['sender_name'];
                }
                break;
            case 'messageOriginChannel':
                $object['type'] = 'channel';
                $object['chat'] = JsonChat::make($origin['chat_id'], $client);
                $object['message_id'] = $origin['message_id'];
                if ($origin['author_signature'] !== '') {
                    $object['author_signature'] = $origin['author_signature'];
                }
                break;
        }

        $object['date'] = $initialSendDate;

        return $object;
    }

    /**
     * Client::JsonExternalReplyInfo (content types follow the message content port).
     *
     * @param array<string, mixed> $reply td_api messageReplyToMessage
     * @return array<string, mixed>
     */
    private static function externalReply(array $reply, Client $client): array
    {
        $object = ['origin' => self::origin($reply['origin'], $reply['origin_send_date'], $client)];

        if ($reply['chat_id'] !== 0) {
            $object['chat'] = JsonChat::make($reply['chat_id'], $client);
            if ($reply['message_id'] !== 0) {
                $object['message_id'] = $reply['message_id'];
            }
        }

        $content = $reply['content'];
        switch ($content['_'] ?? '') {
            case 'messageText':
                if ($content['link_preview_options'] !== null) {
                    $object['link_preview_options'] = $content['link_preview_options'];
                }
                break;
            case 'messageAnimation':
                $object['animation'] = JsonMedia::animation($content['animation'], false);
                self::addMediaSpoiler($object, $content['has_spoiler']);
                break;
            case 'messageAudio':
                $object['audio'] = JsonMedia::audio($content['audio']);
                break;
            case 'messageDocument':
                $object['document'] = JsonMedia::document($content['document']);
                break;
            case 'messagePaidMedia':
                $object['paid_media'] = [
                    'star_count' => $content['star_count'],
                    'paid_media' => array_map(static fn (array $media): array => self::paidMedia($media), $content['media']),
                ];
                break;
            case 'messagePhoto':
                if ($content['video'] !== null) {
                    $object['live_photo'] = JsonMedia::livePhoto($content['photo'], $content['video']);
                } else {
                    $object['photo'] = JsonMedia::photo($content['photo']);
                }
                self::addMediaSpoiler($object, $content['has_spoiler']);
                break;
            case 'messageSticker':
                $object['sticker'] = JsonSticker::make($content['sticker'], $client);
                break;
            case 'messageVideo':
                $object['video'] = JsonMedia::video($content['video'], $content['cover'], $content['start_timestamp'], $content['alternative_videos']);
                self::addMediaSpoiler($object, $content['has_spoiler']);
                break;
            case 'messageVideoNote':
                $object['video_note'] = JsonMedia::videoNote($content['video_note']);
                break;
            case 'messageVoiceNote':
                $object['voice'] = JsonMedia::voiceNote($content['voice_note']);
                break;
            case 'messageContact':
                $object['contact'] = JsonMedia::contact($content['contact']);
                break;
            case 'messageDice':
                $object['dice'] = ['emoji' => $content['emoji'], 'value' => $content['value']];
                break;
            case 'messageGame':
                $object['game'] = self::game($content['game'], $client);
                break;
            case 'messageInvoice':
                $object['invoice'] = [
                    'title' => $content['title'],
                    'description' => $content['description'],
                    'start_parameter' => $content['start_parameter'],
                    'currency' => $content['currency'],
                    'total_amount' => $content['total_amount'],
                ];
                break;
            case 'messageLocation':
                $object['location'] = JsonMedia::location($content['location']);
                break;
            case 'messageStakeDice':
                $object['dice'] = ['emoji' => "\u{1F3B2}", 'value' => $content['value']];
                break;
            case 'messageVenue':
                $object['venue'] = JsonMedia::venue($content['venue']);
                break;
            case 'messagePoll':
                $object['poll'] = JsonPoll::make($content['poll'], $content['description'], $content['media'], $client);
                break;
            case 'messageGiveaway':
                $object['giveaway'] = self::giveaway($content, $client);
                break;
            case 'messageGiveawayWinners':
                $object['giveaway_winners'] = self::giveawayWinners($content, $client);
                break;
            case 'messageStory':
                $object['story'] = ['chat' => JsonChat::make($content['story_poster_chat_id'], $client), 'id' => $content['story_id']];
                break;
            case 'messageChecklist':
                $object['checklist'] = JsonChecklist::make($content['list'], $client);
                break;
        }

        return $object;
    }

    /**
     * Client::get_forum_topic_id.
     *
     * @param array<string, mixed>|null $topicId
     */
    public static function forumTopicId(?array $topicId): int
    {
        return ($topicId['_'] ?? '') === 'messageTopicForum' ? (int) $topicId['forum_topic_id'] : 0;
    }

    /**
     * @param array<string, mixed>|null $topicId
     */
    private static function isTopicMessage(?array $topicId): bool
    {
        return self::forumTopicId($topicId) > 1;
    }
}
