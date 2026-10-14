<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Concerns;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Objects\JsonUser;
use LaraGram\Server\Support\TdInteger;

/**
 * Chat lookup and access checks: Client::check_chat, check_chat_access,
 * check_user and the TDLib getChat/searchPublicChat they rely on.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait ResolvesChats
{
    public const ACCESS_READ = 0;
    public const ACCESS_READ_MEMBERS = 1;
    public const ACCESS_EDIT = 2;
    public const ACCESS_WRITE = 3;

    /**
     * Client::check_chat: the chat identifier, after the chat is known and accessible.
     */
    public function checkChat(string $chatIdStr, int $access, bool $allowUnknownUser = false): int
    {
        if ($chatIdStr === '') {
            throw new QueryError(400, 'Bad Request: chat_id is empty');
        }

        if ($chatIdStr[0] === '@') {
            $chatId = $this->searchPublicChat($chatIdStr);
            $type = ChatId::type($chatId);
            $allowed = match ($type) {
                ChatId::TYPE_PRIVATE => !empty($this->getUser($chatId)['bot']),
                ChatId::TYPE_SUPERGROUP => true,
                default => false,
            };
            if (!$allowed) {
                throw new QueryError(400, 'Bad Request: chat not found');
            }
            $this->checkChatAccess($chatId, $access);

            return $chatId;
        }

        $chatId = TdInteger::toInt64($chatIdStr);
        if ($allowUnknownUser && $chatId > 0 && $chatId < (1 << 40)) {
            return $chatId;
        }

        if (!$this->isChatKnown($chatId) && !$this->loadChat($chatId)) {
            throw new QueryError(400, 'Chat not found', 'chat not found');
        }

        $this->checkChatAccess($chatId, $access);

        return $chatId;
    }

    /**
     * Client::check_chat_access.
     */
    public function checkChatAccess(int $chatId, int $access): void
    {
        $needWrite = $access === self::ACCESS_WRITE;
        $needEdit = $access === self::ACCESS_EDIT || $needWrite;

        switch (ChatId::type($chatId)) {
            case ChatId::TYPE_PRIVATE:
                $user = $this->getUser($chatId);
                if ($user === null) {
                    throw new QueryError(400, 'Bad Request: private chat not found');
                }
                if (!empty($user['deleted']) && $needEdit) {
                    throw new QueryError(403, 'Forbidden: user is deactivated');
                }
                break;

            case ChatId::TYPE_GROUP:
                if ($access === self::ACCESS_READ_MEMBERS) {
                    $needWrite = $needEdit = true;
                }
                $chat = $this->getBasicGroup(ChatId::toBasicGroupId($chatId)) ?? [];
                $migratedTo = $chat['migrated_to']['channel_id'] ?? 0;
                $isActive = ($chat['_'] ?? '') === 'chatForbidden' || (empty($chat['deactivated']) && $migratedTo === 0);
                if (!$isActive && $needWrite) {
                    if ($migratedTo !== 0) {
                        throw new QueryError(400, 'Bad Request: group chat was upgraded to a supergroup chat', '', [
                            'migrate_to_chat_id' => ChatId::fromChannel((int) $migratedTo),
                        ]);
                    }
                    throw new QueryError(403, 'Forbidden: the group chat was deleted');
                }
                if ($isActive && ($chat['_'] ?? '') === 'chatForbidden' && $needEdit) {
                    throw new QueryError(403, 'Forbidden: bot was kicked from the group chat');
                }
                if ($isActive && !empty($chat['left']) && $needEdit) {
                    throw new QueryError(403, 'Forbidden: bot is not a member of the group chat');
                }
                break;

            case ChatId::TYPE_SUPERGROUP:
                $channel = $this->getChannel(ChatId::toChannelId($chatId)) ?? [];
                $isSupergroup = !empty($channel['megagroup']);
                if (($channel['_'] ?? '') === 'channelForbidden') {
                    throw new QueryError(403, $isSupergroup ? 'Forbidden: bot was kicked from the supergroup chat' : 'Forbidden: bot was kicked from the channel chat');
                }
                $isPublic = JsonUser::activeUsernames($channel) !== [] || !empty($channel['has_geo']);
                $needMore = $isPublic ? $needEdit : true;
                if (!empty($channel['left']) && $needMore) {
                    throw new QueryError(403, $isSupergroup ? 'Forbidden: bot is not a member of the supergroup chat' : 'Forbidden: bot is not a member of the channel chat');
                }
                break;

            default:
                throw new QueryError(400, 'Chat not found', 'chat not found');
        }
    }

    /**
     * The group-upgraded error carries migrate_to_chat_id (Client::check_chat_access).
     */
    public function migratedChatId(int $chatId): int
    {
        $chat = $this->getBasicGroup(ChatId::toBasicGroupId($chatId)) ?? [];
        $channelId = (int) ($chat['migrated_to']['channel_id'] ?? 0);

        return $channelId === 0 ? 0 : ChatId::fromChannel($channelId);
    }

    public function isChatKnown(int $chatId): bool
    {
        return match (ChatId::type($chatId)) {
            ChatId::TYPE_PRIVATE => $this->getUser($chatId) !== null,
            ChatId::TYPE_GROUP => $this->getBasicGroup(ChatId::toBasicGroupId($chatId)) !== null,
            ChatId::TYPE_SUPERGROUP => $this->getChannel(ChatId::toChannelId($chatId)) !== null,
            default => false,
        };
    }

    /**
     * td_api::getChat for a chat the bot has not seen: bots may use access
     * hash 0 for users and channels.
     */
    public function loadChat(int $chatId): bool
    {
        try {
            switch (ChatId::type($chatId)) {
                case ChatId::TYPE_PRIVATE:
                    $users = $this->invoke('users.getUsers', ['id' => [$this->inputUser($chatId)]]);
                    foreach ((array) $users as $user) {
                        if (is_array($user) && ($user['_'] ?? '') === 'user') {
                            $this->addEntities([$user], []);
                        }
                    }
                    break;
                case ChatId::TYPE_GROUP:
                    $this->invoke('messages.getChats', ['id' => [ChatId::toBasicGroupId($chatId)]]);
                    break;
                case ChatId::TYPE_SUPERGROUP:
                    $this->invoke('channels.getChannels', ['id' => [$this->inputChannel(ChatId::toChannelId($chatId))]]);
                    break;
                default:
                    return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return $this->isChatKnown($chatId);
    }

    /**
     * td_api::searchPublicChat.
     */
    public function searchPublicChat(string $username): int
    {
        $username = ltrim($username, '@');

        try {
            $result = $this->invoke('contacts.resolveUsername', ['username' => $username]);
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e, 'chat not found');
        }

        $chatId = ChatId::fromPeer($result['peer'] ?? null);
        if ($chatId === 0) {
            throw new QueryError(400, 'Chat not found', 'chat not found');
        }

        return $chatId;
    }

    /**
     * Client::check_user: a user the bot can address.
     */
    public function checkUser(int $userId): void
    {
        if ($this->getUser($userId) !== null) {
            return;
        }
        if (!$this->loadChat($userId)) {
            throw new QueryError(400, 'User not found', 'user not found');
        }
    }

    /**
     * The TL InputPeer of a chat.
     *
     * @return array<string, mixed>
     */
    public function inputPeer(int $chatId): array
    {
        return match (ChatId::type($chatId)) {
            ChatId::TYPE_PRIVATE => $chatId === $this->getMyId()
                ? ['_' => 'inputPeerSelf']
                : ['_' => 'inputPeerUser', 'user_id' => $chatId, 'access_hash' => (int) ($this->getUser($chatId)['access_hash'] ?? 0)],
            ChatId::TYPE_GROUP => ['_' => 'inputPeerChat', 'chat_id' => ChatId::toBasicGroupId($chatId)],
            ChatId::TYPE_SUPERGROUP => [
                '_' => 'inputPeerChannel',
                'channel_id' => ChatId::toChannelId($chatId),
                'access_hash' => (int) ($this->getChannel(ChatId::toChannelId($chatId))['access_hash'] ?? 0),
            ],
            default => ['_' => 'inputPeerEmpty'],
        };
    }

    /**
     * The TL InputChannel of a supergroup or channel chat identifier.
     *
     * @return array<string, mixed>
     */
    public function inputChannelOfChat(int $chatId): array
    {
        return $this->inputChannel(ChatId::toChannelId($chatId));
    }

    public function isSupergroupChat(int $chatId): bool
    {
        return ChatId::type($chatId) === ChatId::TYPE_SUPERGROUP && !$this->isBroadcastChannel($chatId);
    }

    /** @var array<int, array{0: array<string, mixed>, 1: float}> chat id => [TL full info, load time] */
    private array $chatFulls = [];

    /**
     * The TL userFull/chatFull/channelFull of a chat (TDLib's *_full objects),
     * loaded when unknown, older than a minute or when $force is set.
     *
     * @return array<string, mixed>
     */
    public function getChatFull(int $chatId, bool $force = false): array
    {
        $cached = $this->chatFulls[$chatId] ?? null;
        if (!$force && $cached !== null && microtime(true) - $cached[1] < 60) {
            return $cached[0];
        }

        try {
            $result = match (ChatId::type($chatId)) {
                ChatId::TYPE_PRIVATE => $this->invoke('users.getFullUser', ['id' => $this->inputUser($chatId)]),
                ChatId::TYPE_GROUP => $this->invoke('messages.getFullChat', ['chat_id' => ChatId::toBasicGroupId($chatId)]),
                default => $this->invoke('channels.getFullChannel', ['channel' => $this->inputChannelOfChat($chatId)]),
            };
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }

        $full = (array) ($result['full_user'] ?? $result['full_chat'] ?? []);
        $this->chatFulls[$chatId] = [$full, microtime(true)];

        return $full;
    }

    public function forgetChatFull(int $chatId): void
    {
        unset($this->chatFulls[$chatId]);
    }
}
