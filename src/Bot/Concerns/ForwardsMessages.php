<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Concerns;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\FormattedText;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Files\RemoteFileLocation;
use LaraGram\Server\Messages\MessageInfo;
use LaraGram\Server\Objects\ChatId;

/**
 * Forwarding and copying messages: MessagesManager::forward_messages,
 * get_forwarded_messages and do_forward_messages. Forwards and server-side
 * copies go in one messages.forwardMessages request; other copies are sent
 * as new messages with the original content.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait ForwardsMessages
{
    /**
     * MessageCopyOptions with no copy.
     *
     * @return array<string, mixed>
     */
    public static function noCopyOptions(): array
    {
        return ['send_copy' => false, 'replace_caption' => false, 'new_caption' => null, 'new_invert_media' => false,
            'reply_to' => null, 'reply_markup' => null];
    }

    /**
     * td_api::forwardMessages (and sendMessage with inputMessageForwarded for one message).
     *
     * @param list<int> $messageIds
     * @param array<string, mixed> $options messageSendOptions
     * @param list<array<string, mixed>> $copyOptions one per message
     * @return list<MessageInfo|null> by message index; null for a message that couldn't be forwarded
     */
    public function tdForwardMessages(int $toChatId, ?array $topic, int $fromChatId, array $messageIds, array $options,
        bool $inGameShare, int $newVideoStartTimestamp, array $copyOptions): array
    {
        if (count($messageIds) > 100) {
            throw new QueryError(400, 'Too many messages to forward');
        }
        if ($messageIds === []) {
            throw new QueryError(400, 'There are no messages to forward');
        }
        $single = count($messageIds) === 1;
        $this->checkMessageSendOptions($toChatId, $options, $single, $single);

        // get_forwarded_messages
        if (!$this->isChatKnown($fromChatId)) {
            throw new QueryError(400, 'Chat to forward messages from not found');
        }
        if ($this->getChatHasProtectedContent($fromChatId)) {
            foreach ($copyOptions as $copyOption) {
                if (!$copyOption['send_copy']) {
                    throw new QueryError(400, 'Message has protected content and can\'t be forwarded');
                }
            }
        }
        $this->canSendMessageToChat($toChatId);
        $this->checkSendMessageTopic($toChatId, $topic);

        $last = 0;
        foreach ($messageIds as $messageId) {
            if ($messageId <= 0) {
                throw new QueryError(400, 'Invalid message identifier');
            }
            if ($messageId <= $last) {
                throw new QueryError(400, 'Message identifiers must be in a strictly increasing order');
            }
            $last = $messageId;
        }

        $canUseServerForward = true;
        foreach ($copyOptions as $copyOption) {
            if (!$this->isCopySupportedServerSide($copyOption, $toChatId, $topic)) {
                $canUseServerForward = false;
                break;
            }
        }
        $dropAuthor = $canUseServerForward && $copyOptions[0]['send_copy'];
        $dropMediaCaptions = $canUseServerForward && $copyOptions[0]['replace_caption'];
        $allowPaid = $options['allow_paid_broadcast'] ?? false;

        $forwarded = [];
        $copied = [];
        foreach ($messageIds as $index => $messageId) {
            $message = $this->getMessage($fromChatId, $messageId, true);
            if ($message === null) {
                continue;
            }
            $copyOption = $copyOptions[$index];
            $needCopy = $copyOption['send_copy'];
            if (!$this->canForwardMessage($fromChatId, $message, $needCopy)) {
                continue;
            }
            $isBrokenServerCopy = $message->content['_'] === 'messageDice';
            $isLocalCopy = $needCopy && !($canUseServerForward && !$isBrokenServerCopy);

            $contentType = self::contentTypeOf($message->content);
            try {
                $this->canSendMessageContent($toChatId, $contentType, ['is_anonymous' => $message->content['poll']['is_anonymous'] ?? true],
                    !$isLocalCopy);
            } catch (QueryError) {
                if (!$needCopy || !$allowPaid) {
                    continue;
                }
            }

            if ($isLocalCopy) {
                $copied[$index] = [$message, $copyOption];
            } else {
                $forwarded[$index] = $message;
            }
        }

        $results = array_fill(0, count($messageIds), null);

        if ($forwarded !== []) {
            $randomIds = [];
            foreach ($forwarded as $index => $message) {
                $randomIds[$index] = self::randomId();
            }
            $topMsgId = ($topic['_'] ?? '') === 'messageTopicForum' ? (int) $topic['forum_topic_id'] : 0;
            $params = [
                'silent' => $options['disable_notification'] ?? false,
                'with_my_score' => $inGameShare,
                'drop_author' => $dropAuthor,
                'drop_media_captions' => $dropMediaCaptions,
                'noforwards' => $options['protect_content'] ?? false,
                'allow_paid_floodskip' => $allowPaid,
                'from_peer' => $this->inputPeer($fromChatId),
                'id' => array_values(array_map(static fn (MessageInfo $m): int => $m->id, $forwarded)),
                'random_id' => array_values($randomIds),
                'to_peer' => $this->inputPeer($toChatId),
                'top_msg_id' => $topMsgId !== 0 ? $topMsgId : null,
                'reply_to' => ($topic['_'] ?? '') === 'messageTopicDirectMessages'
                    ? $this->getInputReplyTo($toChatId, $topic, self::noReply()) : null,
                'effect' => ($options['effect_id'] ?? 0) !== 0 ? $options['effect_id'] : null,
                'video_timestamp' => $newVideoStartTimestamp >= 0 ? $newVideoStartTimestamp : null,
                'suggested_post' => $this->getInputSuggestedPost($options['suggested_post_info'] ?? null),
            ];
            $result = $this->invokeMultiSend('messages.forwardMessages', self::tl('messages.forwardMessages', $params, ['video_timestamp']),
                $toChatId, 'Text', count($forwarded));
            $sent = $this->getSentMessagesByRandomId($result, array_values($randomIds));
            foreach ($randomIds as $index => $randomId) {
                $results[$index] = $sent[$randomId] ?? null;
            }
        }

        foreach ($copied as $index => [$message, $copyOption]) {
            $results[$index] = $this->sendLocalCopy($toChatId, $topic, $message, $copyOption, $options, $newVideoStartTimestamp);
        }

        return $results;
    }

    /**
     * MessageCopyOptions::is_supported_server_side.
     *
     * @param array<string, mixed> $copyOption
     * @param array<string, mixed>|null $topic
     */
    private function isCopySupportedServerSide(array $copyOption, int $chatId, ?array $topic): bool
    {
        if (!$copyOption['send_copy']) {
            return true;
        }
        if (($copyOption['replace_caption'] && $copyOption['new_caption'] !== null && $copyOption['new_caption']->text !== '')
            || $copyOption['reply_markup'] !== null) {
            return false;
        }
        $replyTo = $copyOption['reply_to'];
        if ($replyTo !== null && $replyTo['reply_to_message_id'] > 0) {
            if (($topic['_'] ?? '') !== 'messageTopicForum' || $replyTo['quote'] !== null || $replyTo['checklist_task_id'] !== 0
                || $replyTo['poll_option_id'] !== '' || $replyTo['reply_in_chat_id'] !== 0
                || $replyTo['reply_to_message_id'] !== $this->getImplicitReplyToMessageId($chatId, $topic)) {
                return false;
            }
        }

        return true;
    }

    /**
     * MessagesManager::can_forward_message for a bot.
     */
    private function canForwardMessage(int $fromChatId, MessageInfo $message, bool $isCopy): bool
    {
        if ($message->isSelfDestruct) {
            return false;
        }
        if (!self::canForwardMessageContent($message->content, $isCopy)) {
            return false;
        }
        if (!$isCopy && (!$message->canBeSaved || $this->getChatHasProtectedContent($fromChatId))) {
            return false;
        }

        return true;
    }

    /**
     * can_forward_message_content.
     *
     * @param array<string, mixed> $content
     */
    private static function canForwardMessageContent(array $content, bool $isCopy): bool
    {
        $type = $content['_'];
        if ($type === 'messageText') {
            return !TextFormatting::isEmptyString($content['text']->text) || ($content['link_preview_options']['url'] ?? '') !== '';
        }
        if ($isCopy && in_array($type, ['messageGiveaway', 'messageGiveawayWinners', 'messagePaidMedia', 'messageInvoice'], true)) {
            return false;
        }

        return in_array($type, self::FORWARDABLE_CONTENTS, true);
    }

    /** Message contents that are neither service messages, nor unsupported or expired. */
    private const FORWARDABLE_CONTENTS = [
        'messageText', 'messageAnimation', 'messageAudio', 'messageContact', 'messageDice', 'messageDocument',
        'messageGame', 'messageGiveaway', 'messageGiveawayWinners', 'messageInvoice', 'messageLiveLocation',
        'messageLocation', 'messagePaidMedia', 'messagePhoto', 'messagePoll', 'messageSticker', 'messageStory',
        'messageVenue', 'messageVideo', 'messageVideoNote', 'messageVoiceNote', 'messageChecklist', 'messageRichText',
    ];

    /**
     * The MessageContentType name of a td_api content.
     *
     * @param array<string, mixed> $content
     */
    public static function contentTypeOf(array $content): string
    {
        return match ($content['_']) {
            'messageText' => 'Text',
            'messageAnimation' => 'Animation',
            'messageAudio' => 'Audio',
            'messageContact' => 'Contact',
            'messageDice', 'messageStakeDice' => 'Dice',
            'messageDocument' => 'Document',
            'messageGame' => 'Game',
            'messageGiveaway' => 'Giveaway',
            'messageGiveawayWinners' => 'GiveawayWinners',
            'messageInvoice' => 'Invoice',
            'messageLiveLocation' => 'LiveLocation',
            'messageLocation' => 'Location',
            'messagePaidMedia' => 'PaidMedia',
            'messagePhoto' => 'Photo',
            'messagePoll' => 'Poll',
            'messageSticker' => 'Sticker',
            'messageStory' => 'Story',
            'messageVenue' => 'Venue',
            'messageVideo' => 'Video',
            'messageVideoNote' => 'VideoNote',
            'messageVoiceNote' => 'VoiceNote',
            'messageChecklist' => 'ToDoList',
            'messageRichText' => 'RichText',
            default => 'Unsupported',
        };
    }

    /**
     * DialogManager::get_dialog_has_protected_content.
     */
    public function getChatHasProtectedContent(int $chatId): bool
    {
        return match (ChatId::type($chatId)) {
            ChatId::TYPE_GROUP => !empty($this->getBasicGroup(ChatId::toBasicGroupId($chatId))['noforwards']),
            ChatId::TYPE_SUPERGROUP => !empty($this->getChannel(ChatId::toChannelId($chatId))['noforwards']),
            default => false,
        };
    }

    /**
     * Send a copy of a message as a new message (MessageContentDupType::Copy).
     *
     * @param array<string, mixed>|null $topic
     * @param array<string, mixed> $copyOption
     * @param array<string, mixed> $options
     */
    private function sendLocalCopy(int $toChatId, ?array $topic, MessageInfo $message, array $copyOption, array $options, int $newVideoStartTimestamp): ?MessageInfo
    {
        $content = $message->content;
        $caption = self::contentCaption($content);
        if ($copyOption['replace_caption']) {
            $caption = $copyOption['new_caption'] ?? new FormattedText();
        }
        $invertMedia = $copyOption['replace_caption'] && in_array($content['_'], ['messageAnimation', 'messagePhoto', 'messageVideo', 'messagePaidMedia'], true)
            ? $copyOption['new_invert_media'] : ($content['show_caption_above_media'] ?? (($content['link_preview_options']['show_above_text'] ?? false)));

        $prepared = $this->getCopyInputMedia($content, $caption, $newVideoStartTimestamp);
        if ($prepared === null) {
            return null;
        }

        $replyTo = $copyOption['reply_to'] ?? self::noReply();
        $tlReplyMarkup = $this->getDialogReplyMarkup($copyOption['reply_markup'], $toChatId);
        $params = [
            'silent' => $options['disable_notification'] ?? false,
            'noforwards' => $options['protect_content'] ?? false,
            'invert_media' => $invertMedia,
            'allow_paid_floodskip' => $options['allow_paid_broadcast'] ?? false,
            'peer' => $this->inputPeer($toChatId),
            'reply_to' => $this->getInputReplyTo($toChatId, $topic, $replyTo),
            'message' => $prepared['text']->text,
            'random_id' => self::randomId(),
            'reply_markup' => $tlReplyMarkup,
            'entities' => $this->inputEntitiesOrNull($prepared['text']),
            'effect' => ($options['effect_id'] ?? 0) !== 0 ? $options['effect_id'] : null,
            'suggested_post' => $this->getInputSuggestedPost($options['suggested_post_info'] ?? null),
        ];
        if ($prepared['media'] === null) {
            $params['no_webpage'] = $prepared['no_webpage'];
            $method = 'messages.sendMessage';
        } else {
            $params['media'] = $prepared['media'];
            $method = 'messages.sendMedia';
        }

        $result = $this->invokeSend($method, self::tl($method, $params), $toChatId, $prepared['type']);

        return $this->getSentMessage($result, $params['random_id'], $toChatId, $prepared + ['invert_media' => $invertMedia], $tlReplyMarkup, $replyTo, $topic, $options);
    }

    /**
     * @return array<string, mixed> CheckedReplyParameters without a reply
     */
    public static function noReply(): array
    {
        return [
            'reply_in_chat_id' => 0, 'reply_to_message_id' => 0, 'reply_to_ephemeral_message_id' => 0,
            'quote' => null, 'quote_position' => 0, 'checklist_task_id' => 0, 'poll_option_id' => '',
        ];
    }

    /**
     * @param array<string, mixed> $content
     */
    private static function contentCaption(array $content): FormattedText
    {
        return $content['caption'] ?? $content['text'] ?? new FormattedText();
    }

    /**
     * get_message_content_input_media for a copied content.
     *
     * @param array<string, mixed> $content
     * @return array<string, mixed>|null prepared content
     */
    private function getCopyInputMedia(array $content, FormattedText $caption, int $newVideoStartTimestamp): ?array
    {
        $prepared = ['type' => self::contentTypeOf($content), 'text' => $caption, 'media' => null, 'no_webpage' => false, 'invert_media' => false];
        $spoiler = $content['has_spoiler'] ?? false;

        switch ($content['_']) {
            case 'messageText':
                $options = $content['link_preview_options'] ?? null;
                $url = (string) ($options['url'] ?? '');
                $prepared['no_webpage'] = (bool) ($options['is_disabled'] ?? false);
                if ($url !== '') {
                    $prepared['media'] = self::tl('inputMediaWebPage', [
                        'force_large_media' => (bool) ($options['force_large_media'] ?? false),
                        'force_small_media' => (bool) ($options['force_small_media'] ?? false),
                        'optional' => $caption->text !== '',
                        'url' => $url,
                    ]);
                }

                return $prepared;

            case 'messagePhoto':
                $sizes = $content['photo']['sizes'] ?? [];
                $location = $sizes === [] ? null : self::locationOf(end($sizes)['file']['file_id'] ?? '');
                if ($location === null) {
                    return null;
                }
                $prepared['media'] = self::tl('inputMediaPhoto', ['spoiler' => $spoiler, 'id' => self::inputPhoto($location)]);

                return $prepared;

            case 'messageAnimation':
            case 'messageAudio':
            case 'messageDocument':
            case 'messageSticker':
            case 'messageVideo':
            case 'messageVideoNote':
            case 'messageVoiceNote':
                $key = match ($content['_']) {
                    'messageAnimation' => 'animation',
                    'messageAudio' => 'audio',
                    'messageDocument' => 'document',
                    'messageSticker' => 'sticker',
                    'messageVideo' => 'video',
                    'messageVideoNote' => 'video_note',
                    default => 'voice_note',
                };
                $location = self::locationOf($content[$key]['file']['file_id'] ?? '');
                if ($location === null) {
                    return null;
                }
                $media = ['spoiler' => $spoiler, 'id' => self::inputDocument($location)];
                if ($content['_'] === 'messageVideo') {
                    $startTimestamp = $newVideoStartTimestamp >= 0 ? $newVideoStartTimestamp : (int) ($content['start_timestamp'] ?? 0);
                    if ($startTimestamp !== 0) {
                        $media['video_timestamp'] = $startTimestamp;
                    }
                    $coverSizes = $content['cover']['sizes'] ?? [];
                    $cover = $coverSizes === [] ? null : self::locationOf(end($coverSizes)['file']['file_id'] ?? '');
                    if ($cover !== null) {
                        $media['video_cover'] = self::inputPhoto($cover);
                    }
                }
                $prepared['media'] = self::tl('inputMediaDocument', $media);
                if (in_array($content['_'], ['messageSticker', 'messageVideoNote'], true)) {
                    $prepared['text'] = new FormattedText();
                }

                return $prepared;

            case 'messageContact':
                $contact = $content['contact'];
                $prepared['media'] = ['_' => 'inputMediaContact', 'phone_number' => $contact['phone_number'],
                    'first_name' => $contact['first_name'], 'last_name' => $contact['last_name'], 'vcard' => $contact['vcard']];
                $prepared['text'] = new FormattedText();

                return $prepared;

            case 'messageLocation':
            case 'messageLiveLocation':
                $prepared['type'] = 'Location';
                $prepared['media'] = ['_' => 'inputMediaGeoPoint', 'geo_point' => self::inputGeoPoint($content['location'])];
                $prepared['text'] = new FormattedText();

                return $prepared;

            case 'messageVenue':
                $venue = $content['venue'];
                $prepared['media'] = ['_' => 'inputMediaVenue', 'geo_point' => self::inputGeoPoint($venue['location']),
                    'title' => $venue['title'], 'address' => $venue['address'], 'provider' => $venue['provider'],
                    'venue_id' => $venue['id'], 'venue_type' => $venue['type']];
                $prepared['text'] = new FormattedText();

                return $prepared;

            case 'messageDice':
                $prepared['media'] = ['_' => 'inputMediaDice', 'emoticon' => $content['emoji']];
                $prepared['text'] = new FormattedText();

                return $prepared;
        }

        return null;
    }

    private static function locationOf(string $fileId): ?RemoteFileLocation
    {
        if ($fileId === '') {
            return null;
        }
        try {
            return RemoteFileLocation::fromFileId($fileId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Send a request creating several messages; errors of the request are
     * reported like Client::on_message_send_failed does for a multisend.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function invokeMultiSend(string $method, array $params, int $chatId, string $contentType, int $messageCount): array
    {
        try {
            return $this->invokeSend($method, $params, $chatId, $contentType);
        } catch (QueryError $e) {
            $code = $e->getCode();
            if ($code === 401 || $code === 429 || $code >= 500 || $code === 406 || $e->getMessage() === 'Group send failed') {
                throw $e;
            }
            throw new QueryError($code, 'Failed to send message #1 with the error message "'.$e->getMessage().'"');
        }
    }

    /**
     * The messages a request created, by random identifier.
     *
     * @param array<string, mixed> $result
     * @param list<int> $randomIds
     * @return array<int, MessageInfo>
     */
    protected function getSentMessagesByRandomId(array $result, array $randomIds): array
    {
        $byRandomId = [];
        foreach ($randomIds as $randomId) {
            $messages = $this->getSentMessages($result, [$randomId]);
            if ($messages !== []) {
                $byRandomId[$randomId] = $messages[0];
            }
        }

        return $byRandomId;
    }
}
