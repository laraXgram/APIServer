<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Messages\MessageConverter;
use LaraGram\Server\Messages\MessageInfo;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Objects\JsonUser;
use LaraGram\Server\Support\TdInteger;

/**
 * Games: sendGame, setGameScore and getGameHighScores with the GameManager
 * and InlineMessageManager parts they rely on.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait GameMethods
{
    public function processSendGameQuery(Query $query): void
    {
        $gameShortName = self::getRequiredStringArg($query, 'game_short_name');
        $this->doSendMessage(['_' => 'inputMessageGame', 'bot_user_id' => $this->getMyId(), 'game_short_name' => $gameShortName], $query);
    }

    public function processSetGameScoreQuery(Query $query): void
    {
        $chatIdStr = $query->arg('chat_id');
        $messageId = self::getMessageId($query);
        $userId = self::getUserIdArg($query);
        $score = TdInteger::toInt32($query->arg('score'));
        $force = self::boolArg($query, 'force');
        $editMessage = true;
        if ($query->hasArg('disable_edit_message')) {
            $editMessage = !self::boolArg($query, 'disable_edit_message');
        } elseif ($query->hasArg('edit_message')) {
            $editMessage = self::boolArg($query, 'edit_message');
        }

        if ($chatIdStr === '' && $messageId === 0) {
            $id = self::parseInlineMessageId(self::getInlineMessageId($query));
            $this->checkUserNoFail($userId);
            // InlineMessageManager::set_inline_game_score
            $this->invokeOnDc((int) $id['dc_id'], 'messages.setInlineGameScore', self::untl(self::tl('messages.setInlineGameScore', [
                'edit_message' => $editMessage,
                'force' => $force,
                'id' => $id,
                'user_id' => $this->getGameInputUser($userId),
                'score' => $score,
            ])));
            $query->answerQuery(true);

            return;
        }

        [$chatId, $messageId] = $this->checkMessage($chatIdStr, $messageId, false, self::ACCESS_EDIT, 'message to set game score');
        $this->checkUserNoFail($userId);

        // GameManager::set_game_score
        $message = $this->getMessage($chatId, $messageId, true) ?? throw new QueryError(400, 'Message not found');
        $inputUser = $this->getGameInputUser($userId);
        if (!$this->canSetGameScore($chatId, $message)) {
            throw new QueryError(400, 'Game score can\'t be set');
        }
        $result = $this->invokeOrFail('messages.setGameScore', self::untl(self::tl('messages.setGameScore', [
            'edit_message' => $editMessage,
            'force' => $force,
            'peer' => $this->inputPeer($chatId),
            'id' => $messageId,
            'user_id' => $inputUser,
            'score' => $score,
        ])));
        foreach ($this->updatesOf(is_array($result) ? $result : []) as $update) {
            if (in_array($update['_'] ?? '', ['updateEditMessage', 'updateEditChannelMessage'], true)) {
                $info = (new MessageConverter($this))->convert($update['message'] ?? []);
                if ($info !== null && $info->chatId === $chatId && $info->id === $messageId) {
                    $this->addSentMessage($info);
                }
            }
        }

        // Client::TdOnEditMessageCallback
        $message = $this->getMessage($chatId, $messageId, true) ?? throw new QueryError(400, 'message not found');
        $this->answerEditedMessage($query, $message);
    }

    public function processGetGameHighScoresQuery(Query $query): void
    {
        $chatIdStr = $query->arg('chat_id');
        $messageId = self::getMessageId($query);
        $userId = self::getUserIdArg($query);

        if ($chatIdStr === '' && $messageId === 0) {
            $id = self::parseInlineMessageId(self::getInlineMessageId($query));
            $this->checkUserNoFail($userId);
            // InlineMessageManager::get_inline_game_high_scores
            $result = $this->invokeOnDc((int) $id['dc_id'], 'messages.getInlineGameHighScores', [
                'id' => $id,
                'user_id' => $this->getGameInputUser($userId),
            ]);
        } else {
            [$chatId, $messageId] = $this->checkMessage($chatIdStr, $messageId, false, self::ACCESS_READ, 'message to get game high scores');
            $this->checkUserNoFail($userId);
            // GameManager::get_game_high_scores
            if ($this->getMessage($chatId, $messageId, true) === null) {
                throw new QueryError(400, 'Message not found');
            }
            $result = $this->invokeOrFail('messages.getGameHighScores', [
                'peer' => $this->inputPeer($chatId),
                'id' => $messageId,
                'user_id' => $this->getGameInputUser($userId),
            ]);
        }

        // GameManager::get_game_high_scores_object + Client::JsonGameHighScore
        $scores = [];
        foreach ($result['scores'] ?? [] as $highScore) {
            $position = (int) $highScore['pos'];
            $scoreUserId = (int) $highScore['user_id'];
            $score = (int) $highScore['score'];
            if ($position <= 0 || $scoreUserId <= 0 || $score < 0) {
                continue;
            }
            $scores[] = ['position' => $position, 'user' => JsonUser::make($scoreUserId, $this), 'score' => $score];
        }
        $query->answerQuery($scores);
    }

    /**
     * UserManager::get_input_user for a bot.
     *
     * @return array<string, mixed>
     */
    private function getGameInputUser(int $userId): array
    {
        if ($userId <= 0) {
            throw new QueryError(400, 'User not found');
        }

        return $this->inputUser($userId);
    }

    /**
     * MessagesManager::can_set_game_score.
     */
    private function canSetGameScore(int $chatId, MessageInfo $message): bool
    {
        if ($message->content['_'] !== 'messageGame') {
            return false;
        }
        $isInlineMessage = $message->viaBotUserId > 0;
        if ($isInlineMessage && $message->viaBotUserId !== $this->getMyId()) {
            return false;
        }

        switch (ChatId::type($chatId)) {
            case ChatId::TYPE_PRIVATE:
                return $message->isOutgoing || $chatId === $this->getMyId();
            case ChatId::TYPE_GROUP:
                return $message->isOutgoing;
            case ChatId::TYPE_SUPERGROUP:
                if ($isInlineMessage) {
                    return true;
                }
                $permissions = $this->getChatPermissions($chatId);
                if ($this->isBroadcastChannel($chatId)) {
                    return $permissions->canEditMessages() || ($permissions->canPostMessages() && $message->isOutgoing);
                }

                return $message->isOutgoing;
        }

        return false;
    }

    /**
     * process_input_message_game + Game::get_input_media_game.
     *
     * @param array<string, mixed> $content td_api inputMessageGame
     * @return array<string, mixed>
     */
    protected function getInputGameContent(array $content): array
    {
        $botUserId = (int) $content['bot_user_id'];
        if ($botUserId <= 0) {
            throw new QueryError(400, 'User not found');
        }
        $shortName = TextFormatting::cleanInputString($content['game_short_name']);
        if ($shortName === null) {
            throw new QueryError(400, 'Game short name must be encoded in UTF-8');
        }
        if ($shortName === '') {
            throw new QueryError(400, 'Game short name must be non-empty');
        }

        return [
            'type' => 'Game',
            'text' => new \LaraGram\Server\Entities\FormattedText(),
            'media' => ['_' => 'inputMediaGame', 'id' => [
                '_' => 'inputGameShortName',
                'bot_id' => $botUserId === $this->getMyId() ? ['_' => 'inputUserSelf'] : $this->inputUser($botUserId),
                'short_name' => $shortName,
            ]],
            'no_webpage' => false,
            'invert_media' => false,
        ];
    }
}
