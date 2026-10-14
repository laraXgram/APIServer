<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\FormattedText;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Messages\MessageConverter;
use LaraGram\Server\Messages\MessageInfo;

/**
 * Ephemeral messages: messages shown to one member of a chat, sent with
 * ephemeral.sendMessage and managed by their ephemeral identifier.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait EphemeralMethods
{
    public function processEditEphemeralMessageTextQuery(Query $query): void
    {
        $content = $query->hasArg('rich_message')
            ? ['_' => 'inputMessageRichMessage', 'message' => $this->getInputRichMessage($query), 'clear_draft' => false]
            : ['_' => 'inputMessageText', 'clear_draft' => false] + $this->getInputMessageText($query);
        $this->doEditEphemeralMessage($query, $content);
    }

    public function processEditEphemeralMessageMediaQuery(Query $query): void
    {
        $content = $this->getInputMediaArg($query, 'media');
        $this->doEditEphemeralMessage($query, $content);
    }

    public function processEditEphemeralMessageReplyMarkupQuery(Query $query): void
    {
        $this->doEditEphemeralMessage($query, null);
    }

    public function processEditEphemeralMessageCaptionQuery(Query $query): void
    {
        $caption = $this->getCaption($query);
        $showCaptionAboveMedia = self::boolArg($query, 'show_caption_above_media');
        $chatIdStr = $query->arg('chat_id');
        $receiverUserId = self::getUserIdArg($query, 'receiver_user_id');
        $ephemeralMessageId = self::getIntegerArg($query, 'ephemeral_message_id', 0);
        $replyMarkup = $this->resolveReplyMarkupBotUsernames($this->getReplyMarkup($query));
        $chatId = $this->checkChat($chatIdStr, self::ACCESS_EDIT);

        // MessageQueryManager::edit_ephemeral_message_caption
        $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, true);
        $caption = $this->getOutgoingFormattedText($caption, true);
        $this->editEphemeralMessage($chatId, $receiverUserId, $ephemeralMessageId, true, $caption, null, $showCaptionAboveMedia, $tlReplyMarkup);
        $query->answerQuery(true);
    }

    public function processDeleteEphemeralMessageQuery(Query $query): void
    {
        $chatIdStr = $query->arg('chat_id');
        $receiverUserId = self::getUserIdArg($query, 'receiver_user_id');
        $ephemeralMessageId = self::getIntegerArg($query, 'ephemeral_message_id', 0);
        $chatId = $this->checkChat($chatIdStr, self::ACCESS_READ);

        // DeleteEphemeralMessageQuery
        $this->invokeOrFail('ephemeral.deleteMessage', [
            'peer' => $this->inputPeer($chatId),
            'receiver_id' => $this->ephemeralReceiver($receiverUserId),
            'id' => $ephemeralMessageId,
        ]);
        $query->answerQuery(true);
    }

    /**
     * Client::do_edit_ephemeral_message + MessageQueryManager::edit_ephemeral_message.
     *
     * @param array<string, mixed>|null $content
     */
    private function doEditEphemeralMessage(Query $query, ?array $content): void
    {
        $chatIdStr = $query->arg('chat_id');
        $receiverUserId = self::getUserIdArg($query, 'receiver_user_id');
        $ephemeralMessageId = self::getIntegerArg($query, 'ephemeral_message_id', 0);
        $replyMarkup = $this->resolveReplyMarkupBotUsernames($this->getReplyMarkup($query));
        $chatId = $this->checkChat($chatIdStr, self::ACCESS_EDIT);

        $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, true);
        if ($content === null) {
            $this->editEphemeralMessage($chatId, $receiverUserId, $ephemeralMessageId, false, null, null, false, $tlReplyMarkup);
        } elseif ($content['_'] === 'inputMessageText') {
            [$text, , $webPage, $above] = $this->processInputMessageTextForEdit($chatId, $content);
            $this->editEphemeralMessage($chatId, $receiverUserId, $ephemeralMessageId, true, $text, $webPage, $above, $tlReplyMarkup);
        } else {
            $prepared = $this->getInputMessageContent($chatId, $content);
            if (!in_array($prepared['type'], ['Animation', 'Audio', 'Document', 'Photo', 'Video', 'RichText'], true)) {
                throw new QueryError(400, 'Unsupported input message content type');
            }
            if ($prepared['type'] === 'RichText') {
                $this->editEphemeralMessage($chatId, $receiverUserId, $ephemeralMessageId, false, null, null, false, $tlReplyMarkup,
                    $this->getInputRichMessageTl($prepared['rich_message'], $chatId));
                $query->answerQuery(true);

                return;
            }
            $media = $this->getUploadedInputMedia($prepared['media'], $chatId, false);
            $this->editEphemeralMessage($chatId, $receiverUserId, $ephemeralMessageId, true, $prepared['text'], $media,
                $prepared['invert_media'], $tlReplyMarkup);
        }
        $query->answerQuery(true);
    }

    /**
     * EditEphemeralMessageQuery.
     *
     * @param array<string, mixed>|null $media
     * @param array<string, mixed>|null $replyMarkup TL ReplyMarkup
     */
    private function editEphemeralMessage(int $chatId, int $receiverUserId, int $ephemeralMessageId, bool $forceEditText,
        ?FormattedText $text, ?array $media, bool $invertMedia, ?array $replyMarkup, ?array $richMessage = null): void
    {
        $editText = $forceEditText || ($text !== null && $text->text !== '');
        $this->invokeOrFail('ephemeral.editMessage', self::untl(self::tl('ephemeral.editMessage', [
            'invert_media' => $invertMedia,
            'peer' => $this->inputPeer($chatId),
            'receiver_id' => $this->ephemeralReceiver($receiverUserId),
            'id' => $ephemeralMessageId,
            'message' => $editText ? ($text?->text ?? '') : null,
            'media' => $media,
            'entities' => $editText && $text !== null ? $this->inputEntitiesOrNull($text) : null,
            'reply_markup' => $replyMarkup,
            'rich_message' => $richMessage,
        ])));
    }

    /**
     * get_input_user of an ephemeral message receiver.
     *
     * @return array<string, mixed>
     */
    private function ephemeralReceiver(int $userId): array
    {
        if ($userId <= 0) {
            throw new QueryError(400, 'Can\'t access the user');
        }

        return $this->inputUser($userId);
    }

    /**
     * td_api::sendEphemeralMessage (MessagesManager::send_ephemeral_message + SendMediaQuery).
     *
     * @param array<string, mixed>|null $topic
     * @param array<string, mixed> $ephemeral
     * @param array<string, mixed> $replyTo
     * @param array<string, mixed>|null $replyMarkup
     * @param array<string, mixed> $content
     */
    protected function tdSendEphemeralMessage(int $chatId, ?array $topic, array $ephemeral, array $replyTo, bool $protectContent, ?array $replyMarkup, array $content): MessageInfo
    {
        $receiverUserId = (int) $ephemeral['receiver_user_id'];
        if ($receiverUserId <= 0) {
            throw new QueryError(400, 'User not found');
        }
        $this->checkSendMessageTopic($chatId, $topic);
        $tlReplyMarkup = $this->getDialogReplyMarkup($replyMarkup, $chatId);
        $prepared = $this->getInputMessageContent($chatId, $content);
        if (!in_array($prepared['type'], ['Animation', 'Audio', 'Contact', 'Document', 'Location', 'Photo', 'RichText',
            'Sticker', 'Text', 'Venue', 'Video', 'VideoNote', 'VoiceNote'], true)) {
            throw new QueryError(400, 'Unallowed message content specified');
        }

        $inputReplyTo = $replyTo['reply_to_ephemeral_message_id'] > 0
            ? ['_' => 'inputReplyToEphemeralMessage', 'id' => $replyTo['reply_to_ephemeral_message_id']]
            : $this->getInputReplyTo($chatId, $topic, $replyTo);
        $params = self::tl('ephemeral.sendMessage', [
            'invert_media' => $prepared['invert_media'],
            'anchor' => $ephemeral['replace_callback_query_message'],
            'noforwards' => $protectContent,
            'peer' => $this->inputPeer($chatId),
            'receiver_id' => $this->inputUser($receiverUserId),
            'query_id' => $ephemeral['callback_query_id'] !== 0 ? $ephemeral['callback_query_id'] : null,
            'message' => $prepared['text']->text,
            'entities' => $this->inputEntitiesOrNull($prepared['text']),
            'media' => $prepared['media'],
            'reply_markup' => $tlReplyMarkup,
            'rich_message' => $prepared['rich_message'] ?? null,
            'random_id' => self::randomId(),
            'reply_to' => $inputReplyTo,
        ]);
        $result = $this->invokeSend('ephemeral.sendMessage', $params, $chatId, $prepared['type']);

        foreach ($this->updatesOf($result) as $update) {
            if (($update['_'] ?? '') === 'updateNewEphemeralMessage') {
                $info = $this->convertEphemeralMessage($update['message'] ?? []);
                if ($info !== null && $info->chatId === $chatId) {
                    return $info;
                }
            }
        }

        throw new QueryError(500, 'Request aborted');
    }

    /**
     * MessagesManager::parse_ephemeral_message.
     *
     * @param array<string, mixed> $message TL ephemeralMessage
     */
    public function convertEphemeralMessage(array $message): ?MessageInfo
    {
        if (!empty($message['welcome_template']) || !isset($message['peer_id'])) {
            return null;
        }
        $info = (new MessageConverter($this))->convert(array_filter([
            '_' => 'message',
            'out' => $message['out'] ?? null,
            'noforwards' => $message['noforwards'] ?? null,
            'invert_media' => $message['invert_media'] ?? null,
            'id' => 0,
            'from_id' => $message['from_id'] ?? null,
            'peer_id' => $message['peer_id'],
            'reply_to' => $message['reply_to'] ?? null,
            'date' => $message['date'] ?? 0,
            'message' => $message['message'] ?? '',
            'media' => $message['media'] ?? null,
            'reply_markup' => $message['reply_markup'] ?? null,
            'entities' => $message['entities'] ?? null,
            'rich_message' => $message['rich_message'] ?? null,
        ], static fn ($value): bool => $value !== null));
        if ($info === null) {
            return null;
        }
        $info->ephemeralMessageId = (int) ($message['id'] ?? 0);
        $info->receiverUserId = (int) ($message['receiver_id'] ?? 0);

        return $info;
    }
}
