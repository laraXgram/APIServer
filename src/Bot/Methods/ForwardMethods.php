<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Messages\MessageInfo;
use LaraGram\Server\Support\TdInteger;

/**
 * forwardMessage(s) and copyMessage(s) (Client::process_forward_*_query,
 * process_copy_*_query).
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait ForwardMethods
{
    public function processCopyMessageQuery(Query $query): void
    {
        $fromChatId = self::getRequiredStringArg($query, 'from_chat_id');
        $messageId = self::getMessageId($query);
        $replaceCaption = $query->hasArg('caption');
        $caption = $replaceCaption ? $this->getCaption($query) : null;
        $replaceVideoStartTimestamp = $query->hasArg('video_start_timestamp');
        $newVideoStartTimestamp = self::getIntegerArg($query, 'video_start_timestamp', 0);
        $showCaptionAboveMedia = self::boolArg($query, 'show_caption_above_media');

        [$fromChatId, $messageId] = $this->checkMessage($fromChatId, $messageId, false, self::ACCESS_READ, 'message to copy');

        $this->doSendMessage([
            '_' => 'inputMessageForwarded',
            'from_chat_id' => $fromChatId,
            'message_id' => $messageId,
            'in_game_share' => false,
            'replace_video_start_timestamp' => $replaceVideoStartTimestamp,
            'new_video_start_timestamp' => $newVideoStartTimestamp,
            'copy_options' => [
                'send_copy' => true, 'replace_caption' => $replaceCaption, 'new_caption' => $caption,
                'new_invert_media' => $showCaptionAboveMedia, 'reply_to' => null, 'reply_markup' => null,
            ],
        ], $query);
    }

    public function processForwardMessageQuery(Query $query): void
    {
        $fromChatId = self::getRequiredStringArg($query, 'from_chat_id');
        $messageId = self::getMessageId($query);
        $replaceVideoStartTimestamp = $query->hasArg('video_start_timestamp');
        $newVideoStartTimestamp = self::getIntegerArg($query, 'video_start_timestamp', 0);

        [$fromChatId, $messageId] = $this->checkMessage($fromChatId, $messageId, false, self::ACCESS_READ, 'message to forward');

        $this->doSendMessage([
            '_' => 'inputMessageForwarded',
            'from_chat_id' => $fromChatId,
            'message_id' => $messageId,
            'in_game_share' => false,
            'replace_video_start_timestamp' => $replaceVideoStartTimestamp,
            'new_video_start_timestamp' => $newVideoStartTimestamp,
            'copy_options' => null,
        ], $query);
    }

    public function processCopyMessagesQuery(Query $query): void
    {
        $this->doForwardMessages($query, true);
    }

    public function processForwardMessagesQuery(Query $query): void
    {
        $this->doForwardMessages($query, false);
    }

    private function doForwardMessages(Query $query, bool $isCopy): void
    {
        $chatIdStr = $query->arg('chat_id');
        $forumTopicId = self::getForumTopicId($query, 'message_thread_id');
        $fromChatIdStr = self::getRequiredStringArg($query, 'from_chat_id');
        $messageIds = self::getMessageIds($query, 100);
        if ($messageIds === []) {
            throw QueryError::badRequest('Message identifiers are not specified');
        }
        $options = [
            'disable_notification' => self::boolArg($query, 'disable_notification'),
            'protect_content' => self::boolArg($query, 'protect_content'),
            'allow_paid_broadcast' => false,
            'effect_id' => TdInteger::toInt64($query->arg('message_effect_id')),
        ];
        $directMessagesTopicId = TdInteger::toInt64($query->arg('direct_messages_topic_id'));
        $options['suggested_post_info'] = $this->getInputSuggestedPostInfo($query);
        $removeCaption = $isCopy && self::boolArg($query, 'remove_caption');

        [$chatId, $topic] = $this->checkReplyParameters($chatIdStr, $this->getReplyParametersEmpty(), $forumTopicId, $directMessagesTopicId);

        if (($this->yetUnsentMessageCount[$chatId] ?? 0) >= self::MAX_CONCURRENTLY_SENT_CHAT_MESSAGES) {
            $this->failQueryFloodLimitExceeded($query);

            return;
        }

        [$fromChatId, $messageIds] = $this->checkMessages($fromChatIdStr, $messageIds, true, self::ACCESS_READ, 'message to forward');

        $count = $this->yetUnsentMessageCount[$chatId] ?? 0;
        if ($count >= self::MAX_CONCURRENTLY_SENT_CHAT_MESSAGES) {
            $this->failQueryFloodLimitExceeded($query);

            return;
        }
        $messageCount = count($messageIds);
        $this->yetUnsentMessageCount[$chatId] = $count + $messageCount;

        try {
            $copyOptions = [];
            foreach ($messageIds as $messageId) {
                $copyOptions[] = $isCopy
                    ? ['send_copy' => true, 'replace_caption' => $removeCaption, 'new_caption' => null, 'new_invert_media' => false, 'reply_to' => null, 'reply_markup' => null]
                    : self::noCopyOptions();
            }
            $messages = $this->tdForwardMessages($chatId, $topic, $fromChatId, $messageIds, $options, false, -1, $copyOptions);
        } finally {
            $this->decreaseYetUnsentMessageCount($chatId, $messageCount);
        }

        $messages = array_values(array_filter($messages, static fn (?MessageInfo $message): bool => $message !== null));
        if ($messages === []) {
            throw QueryError::badRequest('Messages can\'t be forwarded');
        }

        $query->answerQuery(array_map(static fn (MessageInfo $message): array => ['message_id' => $message->id], $messages));
    }

    /**
     * InputReplyParameters() — no reply.
     *
     * @return array<string, mixed>
     */
    protected function getReplyParametersEmpty(): array
    {
        return [
            'reply_in_chat_id' => '', 'reply_to_message_id' => 0, 'reply_to_ephemeral_message_id' => 0,
            'allow_sending_without_reply' => false, 'quote' => null, 'quote_position' => 0,
            'checklist_task_id' => 0, 'poll_option_id' => '',
        ];
    }

    /**
     * Client::check_messages: the chat and the identifiers of the existing messages.
     *
     * @param list<int> $messageIds
     * @return array{0: int, 1: list<int>}
     */
    protected function checkMessages(string $chatIdStr, array $messageIds, bool $allowEmpty, int $access, string $messageType): array
    {
        $chatId = $this->checkChat($chatIdStr, $access);
        if (!$this->haveMessageAccess($chatId)) {
            throw new QueryError(400, 'MESSAGE_NOT_FOUND', $messageType.' not found');
        }

        $missing = [];
        foreach ($messageIds as $messageId) {
            if ($this->getMessage($chatId, $messageId, true) === null) {
                $missing[] = $messageId;
            }
        }
        if ($missing !== []) {
            try {
                $this->loadMessages($chatId, $missing);
            } catch (QueryError $e) {
                if ($allowEmpty) {
                    return [$chatId, []];
                }
                throw new QueryError($e->getCode(), $e->getMessage(), $messageType.' not found');
            }
        }

        $result = [];
        foreach ($messageIds as $messageId) {
            if ($this->getMessage($chatId, $messageId, true) === null) {
                if (!$allowEmpty) {
                    throw new QueryError(400, $messageType.' not found');
                }
                continue;
            }
            $result[] = $messageId;
        }

        return [$chatId, $result];
    }
}
