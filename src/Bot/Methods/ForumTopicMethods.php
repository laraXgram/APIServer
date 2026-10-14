<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Messages\MediaConverter;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Objects\JsonSticker;
use LaraGram\Server\Support\TdInteger;

/**
 * Forum topics with the ForumTopicManager parts they rely on.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait ForumTopicMethods
{
    /** ForumTopicManager::MAX_FORUM_TOPIC_TITLE_LENGTH (server-side limit) */
    private const MAX_FORUM_TOPIC_TITLE_LENGTH = 128;

    public function processGetForumTopicIconStickersQuery(Query $query): void
    {
        // StickersManager::get_default_topic_icons
        $result = $this->invokeOrFail('messages.getStickerSet', ['stickerset' => ['_' => 'inputStickerSetEmojiDefaultTopicIcons'], 'hash' => 0]);
        $set = $result['set'] ?? [];
        if (isset($set['id'])) {
            $this->stickerSetNames[(int) $set['id']] = (string) ($set['short_name'] ?? '');
        }

        $stickers = [];
        foreach ($result['documents'] ?? [] as $document) {
            $sticker = MediaConverter::document($document);
            if ($sticker !== null && $sticker['kind'] === MediaConverter::DOCUMENT_STICKER) {
                $stickers[] = $sticker;
            }
        }
        $sets = [];
        foreach ($stickers as $sticker) {
            if (($inputSet = JsonSticker::inputStickerSet($sticker)) !== null) {
                $sets[] = $inputSet;
            }
        }
        $this->loadStickerSetNames($sets);

        $query->answerQuery(array_map(fn (array $sticker): array => JsonSticker::make($sticker, $this), $stickers));
    }

    public function processCreateForumTopicQuery(Query $query): void
    {
        $name = $query->arg('name');
        $iconColor = self::getIntegerArg($query, 'icon_color', 0);
        $iconCustomEmojiId = TdInteger::toInt64($query->arg('icon_custom_emoji_id'));
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);

        $name = self::cleanInputStringOrFail($name);
        // ForumTopicManager::create_forum_topic
        $this->checkIsForum($chatId, true);
        if (ChatId::type($chatId) === ChatId::TYPE_SUPERGROUP && !$this->getChatPermissions($chatId)->canCreateTopics()) {
            throw new QueryError(400, 'Not enough rights to create a topic');
        }
        $title = self::cleanName($name, self::MAX_FORUM_TOPIC_TITLE_LENGTH);
        if ($title === '') {
            throw new QueryError(400, 'Name must be non-empty');
        }
        if ($iconColor < 0 || $iconColor > 0xFFFFFF) {
            throw new QueryError(400, 'Invalid icon color specified');
        }

        $randomId = self::randomId();
        $result = $this->invokeOrFail('messages.createForumTopic', self::untl(self::tl('messages.createForumTopic', [
            'peer' => $this->inputPeer($chatId),
            'title' => $title,
            'icon_color' => $iconColor,
            'icon_emoji_id' => $iconCustomEmojiId !== 0 ? $iconCustomEmojiId : null,
            'random_id' => $randomId,
        ])));

        $message = null;
        $messageIdByRandomId = 0;
        foreach ($this->updatesOf(is_array($result) ? $result : []) as $update) {
            if (($update['_'] ?? '') === 'updateMessageID' && (int) $update['random_id'] === $randomId) {
                $messageIdByRandomId = (int) $update['id'];
            }
        }
        foreach ($this->updatesOf(is_array($result) ? $result : []) as $update) {
            if (in_array($update['_'] ?? '', ['updateNewMessage', 'updateNewChannelMessage'], true)
                && (int) ($update['message']['id'] ?? 0) === $messageIdByRandomId) {
                $message = $update['message'];
            }
        }
        if (($message['_'] ?? '') !== 'messageService' || ($message['action']['_'] ?? '') !== 'messageActionTopicCreate') {
            throw new QueryError(400, 'Invalid result received');
        }
        $action = $message['action'];
        $forumTopicId = (int) $message['id'];
        $replyTo = $message['reply_to'] ?? null;
        if (($replyTo['_'] ?? '') === 'messageReplyHeader' && !empty($replyTo['forum_topic']) && (int) ($replyTo['reply_to_top_id'] ?? 0) > 0) {
            $forumTopicId = (int) $replyTo['reply_to_top_id'];
        }

        $object = [
            'message_thread_id' => $forumTopicId,
            'name' => (string) $action['title'],
            'icon_color' => (int) ($action['icon_color'] ?? 0),
        ];
        if ((int) ($action['icon_emoji_id'] ?? 0) !== 0) {
            $object['icon_custom_emoji_id'] = (string) $action['icon_emoji_id'];
        }
        $query->answerQuery($object);
    }

    public function processEditForumTopicQuery(Query $query): void
    {
        $forumTopicId = self::getForumTopicId($query, 'message_thread_id');
        $name = $query->arg('name');
        $editIconCustomEmojiId = $query->hasArg('icon_custom_emoji_id');
        $iconCustomEmojiId = TdInteger::toInt64($query->arg('icon_custom_emoji_id'));
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);

        $name = self::cleanInputStringOrFail($name);
        $this->tdEditForumTopic($chatId, $forumTopicId, $name, $editIconCustomEmojiId, $iconCustomEmojiId);
        $query->answerQuery(true);
    }

    public function processEditGeneralForumTopicQuery(Query $query): void
    {
        $name = $query->arg('name');
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);

        $name = self::cleanInputStringOrFail($name);
        $this->tdEditForumTopic($chatId, self::GENERAL_FORUM_TOPIC_ID, $name, false, 0);
        $query->answerQuery(true);
    }

    public function processCloseForumTopicQuery(Query $query): void
    {
        $this->toggleForumTopicQuery($query, self::getForumTopicId($query, 'message_thread_id'), true);
    }

    public function processReopenForumTopicQuery(Query $query): void
    {
        $this->toggleForumTopicQuery($query, self::getForumTopicId($query, 'message_thread_id'), false);
    }

    public function processCloseGeneralForumTopicQuery(Query $query): void
    {
        $this->toggleForumTopicQuery($query, self::GENERAL_FORUM_TOPIC_ID, true);
    }

    public function processReopenGeneralForumTopicQuery(Query $query): void
    {
        $this->toggleForumTopicQuery($query, self::GENERAL_FORUM_TOPIC_ID, false);
    }

    public function processHideGeneralForumTopicQuery(Query $query): void
    {
        $this->toggleGeneralForumTopicIsHidden($query, true);
    }

    public function processUnhideGeneralForumTopicQuery(Query $query): void
    {
        $this->toggleGeneralForumTopicIsHidden($query, false);
    }

    public function processDeleteForumTopicQuery(Query $query): void
    {
        $forumTopicId = self::getForumTopicId($query, 'message_thread_id');
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);

        // ForumTopicManager::delete_forum_topic
        $this->checkIsForum($chatId, true);
        self::checkForumTopicId($forumTopicId);

        // MessageQueryManager::delete_topic_history
        do {
            $result = $this->invokeOrFail('messages.deleteTopicHistory', ['peer' => $this->inputPeer($chatId), 'top_msg_id' => $forumTopicId]);
        } while ((int) ($result['offset'] ?? 0) > 0);
        $query->answerQuery(true);
    }

    public function processUnpinAllForumTopicMessagesQuery(Query $query): void
    {
        $forumTopicId = self::getForumTopicId($query, 'message_thread_id');
        $this->unpinAllForumTopicMessages($query, $forumTopicId);
    }

    public function processUnpinAllGeneralForumTopicMessagesQuery(Query $query): void
    {
        $this->unpinAllForumTopicMessages($query, self::GENERAL_FORUM_TOPIC_ID);
    }

    private function unpinAllForumTopicMessages(Query $query, int $forumTopicId): void
    {
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);
        if ($forumTopicId === 0) {
            throw new QueryError(400, 'Invalid forum topic identifier specified');
        }

        // MessagesManager::unpin_all_dialog_messages
        $this->checkCanPinMessages($chatId);
        self::checkForumTopicId($forumTopicId);
        if (!$this->isForumChat($chatId)) {
            throw new QueryError(400, 'Chat doesn\'t have topics');
        }
        $this->tdUnpinAllMessages($chatId, $forumTopicId);
        $query->answerQuery(true);
    }

    private function toggleForumTopicQuery(Query $query, int $forumTopicId, bool $isClosed): void
    {
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);

        // ForumTopicManager::toggle_forum_topic_is_closed
        $this->checkIsForum($chatId, false);
        self::checkForumTopicId($forumTopicId);
        $this->invokeEditForumTopic($chatId, ['topic_id' => $forumTopicId, 'closed' => $isClosed]);
        $query->answerQuery(true);
    }

    private function toggleGeneralForumTopicIsHidden(Query $query, bool $isHidden): void
    {
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);

        // ForumTopicManager::toggle_forum_topic_is_hidden
        $this->checkIsForum($chatId, false);
        if (!$this->getChatPermissions($chatId)->canEditTopics()) {
            throw new QueryError(400, 'Not enough rights to close or open the topic');
        }
        $this->invokeEditForumTopic($chatId, ['topic_id' => self::GENERAL_FORUM_TOPIC_ID, 'hidden' => $isHidden]);
        $query->answerQuery(true);
    }

    /**
     * ForumTopicManager::edit_forum_topic.
     */
    private function tdEditForumTopic(int $chatId, int $forumTopicId, string $title, bool $editIconCustomEmojiId, int $iconCustomEmojiId): void
    {
        $this->checkIsForum($chatId, true);
        self::checkForumTopicId($forumTopicId);

        $editTitle = $title !== '';
        $newTitle = self::cleanName($title, self::MAX_FORUM_TOPIC_TITLE_LENGTH);
        if ($editTitle && $newTitle === '') {
            throw new QueryError(400, 'Name must be non-empty');
        }
        if (!$editTitle && !$editIconCustomEmojiId) {
            return;
        }

        $params = ['topic_id' => $forumTopicId];
        if ($editTitle) {
            $params['title'] = $newTitle;
        }
        if ($editIconCustomEmojiId) {
            $params['icon_emoji_id'] = $iconCustomEmojiId;
        }
        $this->invokeEditForumTopic($chatId, $params);
    }

    /**
     * EditForumTopicQuery.
     *
     * @param array<string, mixed> $params
     */
    private function invokeEditForumTopic(int $chatId, array $params): void
    {
        $this->invokeOrFail('messages.editForumTopic', ['peer' => $this->inputPeer($chatId)] + $params);
    }

    /**
     * ForumTopicManager::is_forum.
     */
    private function checkIsForum(int $chatId, bool $allowBots): void
    {
        switch (ChatId::type($chatId)) {
            case ChatId::TYPE_PRIVATE:
                if (!empty($this->getUser($this->getMyId())['bot_forum_view'])) {
                    if (!$allowBots) {
                        throw new QueryError(400, 'The chat is not a supergroup forum');
                    }

                    return;
                }
                break;
            case ChatId::TYPE_SUPERGROUP:
                if (!empty($this->getChannel(ChatId::toChannelId($chatId))['forum'])) {
                    return;
                }
                break;
        }

        throw new QueryError(400, 'The chat is not a forum');
    }

    private function isForumChat(int $chatId): bool
    {
        return match (ChatId::type($chatId)) {
            // MessagesManager::add_new_dialog: private chats of bots have topics
            ChatId::TYPE_PRIVATE => true,
            ChatId::TYPE_SUPERGROUP => !empty($this->getChannel(ChatId::toChannelId($chatId))['forum']),
            default => false,
        };
    }

    /**
     * ForumTopicManager::can_be_forum_topic_id.
     */
    private static function checkForumTopicId(int $forumTopicId): void
    {
        if ($forumTopicId <= 0 || $forumTopicId > 2147483647) {
            throw new QueryError(400, 'Invalid forum topic identifier specified');
        }
    }
}
