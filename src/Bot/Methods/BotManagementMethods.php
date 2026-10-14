<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Objects\JsonUser;

/**
 * Verification of users and chats by the bot and the management of the bots
 * it manages, with the BotInfoManager parts they rely on.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait BotManagementMethods
{
    public function processVerifyUserQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $customDescription = $query->arg('custom_description');
        $this->checkUser($userId);
        $this->setCustomBotVerification($userId, true, $customDescription);
        $query->answerQuery(true);
    }

    public function processVerifyChatQuery(Query $query): void
    {
        $customDescription = $query->arg('custom_description');
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_READ);
        $this->setCustomBotVerification($chatId, true, $customDescription);
        $query->answerQuery(true);
    }

    public function processRemoveUserVerificationQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $this->checkUserNoFail($userId);
        $this->setCustomBotVerification($userId, false, '');
        $query->answerQuery(true);
    }

    public function processRemoveChatVerificationQuery(Query $query): void
    {
        $chatId = $this->checkChatNoFail($query->arg('chat_id'));
        // get_message_sender_dialog_id(check_access = false)
        if (ChatId::type($chatId) === null || $chatId === 0) {
            throw new QueryError(400, 'Invalid chat identifier specified');
        }
        $this->setCustomBotVerification($chatId, false, '');
        $query->answerQuery(true);
    }

    public function processGetManagedBotTokenQuery(Query $query): void
    {
        $this->answerManagedBotToken($query, false);
    }

    public function processReplaceManagedBotTokenQuery(Query $query): void
    {
        $this->answerManagedBotToken($query, true);
    }

    public function processGetManagedBotAccessSettingsQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $this->checkUser($userId);

        // BotInfoManager::get_bot_access_settings
        $this->checkBotData($userId);
        $result = $this->invokeOrFail('bots.getAccessSettings', ['bot' => $this->inputUser($userId)]);
        $isRestricted = !empty($result['restricted']);
        $addedUserIds = [];
        if ($isRestricted) {
            foreach ($result['add_users'] ?? [] as $user) {
                if ((int) ($user['id'] ?? 0) > 0) {
                    $addedUserIds[] = (int) $user['id'];
                }
            }
        }

        // Client::JsonBotAccessSettings
        $object = ['is_access_restricted' => $isRestricted];
        if ($addedUserIds !== []) {
            $object['added_users'] = array_map(fn (int $id): array => JsonUser::make($id, $this), $addedUserIds);
        }
        $query->answerQuery($object);
    }

    public function processSetManagedBotAccessSettingsQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $isRestricted = self::boolArg($query, 'is_access_restricted');
        $addedUserIds = [];
        if ($isRestricted && $query->hasArg('added_user_ids')) {
            $addedUserIds = self::getUserIds($query, 10, 'added_user_ids');
        }
        $this->checkUser($userId);

        // BotInfoManager::set_bot_access_settings
        $this->checkBotData($userId);
        $inputUsers = array_map(fn (int $id): array => $this->inputUser($id), $addedUserIds);
        $this->invokeOrFail('bots.editAccessSettings', self::untl(self::tl('bots.editAccessSettings', [
            'restricted' => $isRestricted,
            'bot' => $this->inputUser($userId),
            'add_users' => $inputUsers !== [] ? $inputUsers : null,
        ])));
        $query->answerQuery(true);
    }

    /**
     * BotInfoManager::set_custom_bot_verification for the bot itself.
     */
    private function setCustomBotVerification(int $chatId, bool $isVerified, string $customDescription): void
    {
        $customDescription = TextFormatting::cleanInputString($customDescription) ?? throw new QueryError(400, 'Strings must be encoded in UTF-8');
        if (ChatId::type($chatId) !== ChatId::TYPE_PRIVATE && !$this->isChatKnown($chatId)) {
            throw new QueryError(400, 'Can\'t access the verified entity');
        }
        $this->invokeOrFail('bots.setCustomVerification', self::untl(self::tl('bots.setCustomVerification', [
            'enabled' => $isVerified,
            'peer' => $this->inputPeer($chatId),
            'custom_description' => $customDescription !== '' ? $customDescription : null,
        ])));
    }

    /**
     * BotInfoManager::get_bot_token + Client::TdOnGetBotTokenCallback.
     */
    private function answerManagedBotToken(Query $query, bool $revoke): void
    {
        $userId = self::getUserIdArg($query);
        $this->checkUser($userId);
        $this->checkBotData($userId);
        $result = $this->invokeOrFail('bots.exportBotToken', ['bot' => $this->inputUser($userId), 'revoke' => $revoke]);
        $query->answerQuery((string) ($result['token'] ?? ''));
    }

    /**
     * UserManager::get_bot_data.
     */
    private function checkBotData(int $userId): void
    {
        $user = $this->getUser($userId);
        if ($user === null) {
            throw new QueryError(400, 'Bot not found');
        }
        if (empty($user['bot'])) {
            throw new QueryError(400, 'User is not a bot');
        }
        if (!empty($user['deleted'])) {
            throw new QueryError(400, 'Bot is deleted');
        }
        if (!empty($user['min'])) {
            throw new QueryError(400, 'Bot is inaccessible');
        }
    }
}
