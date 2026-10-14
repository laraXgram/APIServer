<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Files\FileType;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Objects\ChatPermissions;
use LaraGram\Server\Objects\JsonChatFull;
use LaraGram\Server\Objects\JsonUser;
use LaraGram\Server\Support\TdInteger;
use LaraGram\Server\Support\TdJson;
use LaraGram\Server\Td\ParticipantStatus;

/**
 * getChat, chat members and chat settings (Client::process_get_chat_query,
 * process_*_chat_member_query, process_set_chat_*_query) with TDLib's
 * DialogParticipantManager and DialogManager rules.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait ChatMethods
{
    // ═════════════════════════════════════════════════════════════════
    //  getChat
    // ═════════════════════════════════════════════════════════════════

    public function processGetChatQuery(Query $query): void
    {
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_READ);
        $full = $this->getChatFull($chatId, true);

        $pinnedMessageId = 0;
        try {
            $pinnedMessageId = (int) ($full['pinned_msg_id'] ?? 0);
            if ($pinnedMessageId !== 0) {
                $this->tdGetMessage($chatId, $pinnedMessageId);
            }
        } catch (QueryError $e) {
            $code = $e->getCode();
            if ($code === 401 || $code === 429 || $code >= 500) {
                throw $e;
            }
            $pinnedMessageId = 0;
        }

        $query->answerQuery(JsonChatFull::make($chatId, $this, $full, $pinnedMessageId));
    }

    /**
     * Client::get_chat_type: 'private', 'group', 'supergroup' or 'channel'.
     */
    public function getChatTypeName(int $chatId): string
    {
        return match (ChatId::type($chatId)) {
            ChatId::TYPE_PRIVATE => 'private',
            ChatId::TYPE_GROUP => 'group',
            default => $this->isBroadcastChannel($chatId) ? 'channel' : 'supergroup',
        };
    }

    // ═════════════════════════════════════════════════════════════════
    //  Members
    // ═════════════════════════════════════════════════════════════════

    /**
     * td_api::getChatMember (DialogParticipantManager::get_dialog_participant).
     *
     * @return array{status: ParticipantStatus, tag: string}
     */
    public function tdGetChatMember(int $chatId, int $userId): array
    {
        switch (ChatId::type($chatId)) {
            case ChatId::TYPE_PRIVATE:
                if ($userId === $this->getMyId() || $userId === $chatId) {
                    return ['status' => ParticipantStatus::member(), 'tag' => ''];
                }
                throw new QueryError(400, 'Member not found');

            case ChatId::TYPE_GROUP:
                $member = $this->getBasicGroupParticipant($chatId, $userId);
                break;

            default:
                $member = $this->getChannelParticipant($chatId, ['_' => 'inputPeerUser', 'user_id' => $userId,
                    'access_hash' => (int) ($this->getUser($userId)['access_hash'] ?? 0)]);
        }

        if ($this->getUser($userId) === null) {
            throw new QueryError(400, 'Member not found');
        }

        return $member;
    }

    /**
     * ChatManager::get_chat_participant from the basic group's full information.
     *
     * @return array{status: ParticipantStatus, tag: string}
     */
    private function getBasicGroupParticipant(int $chatId, int $userId): array
    {
        foreach ($this->getBasicGroupParticipants($chatId) as $participant) {
            if ($participant['user_id'] === $userId) {
                return ['status' => $participant['status'], 'tag' => $participant['tag']];
            }
        }

        return ['status' => ParticipantStatus::left(), 'tag' => ''];
    }

    /**
     * The members of a basic group (basicGroupFullInfo.members).
     *
     * @return list<array{user_id: int, status: ParticipantStatus, tag: string}>
     */
    private function getBasicGroupParticipants(int $chatId): array
    {
        $full = $this->getChatFull($chatId, true);
        $participants = $full['participants'] ?? [];
        $list = ($participants['_'] ?? '') === 'chatParticipants'
            ? $participants['participants'] ?? []
            : (isset($participants['self_participant']) ? [$participants['self_participant']] : []);

        $chat = $this->getBasicGroup(ChatId::toBasicGroupId($chatId)) ?? [];
        $isCurrentUserCreator = !empty($chat['creator']);

        $result = [];
        foreach ($list as $participant) {
            $tag = (string) ($participant['rank'] ?? '');
            $status = match ($participant['_'] ?? '') {
                'chatParticipantCreator' => ParticipantStatus::creator(true, false),
                'chatParticipantAdmin' => ParticipantStatus::groupAdministrator($isCurrentUserCreator),
                default => ParticipantStatus::member(),
            };
            $result[] = ['user_id' => (int) $participant['user_id'], 'status' => $status, 'tag' => $tag];
        }

        return $result;
    }

    /**
     * DialogParticipantManager::get_channel_participant.
     *
     * @param array<string, mixed> $inputPeer
     * @return array{status: ParticipantStatus, tag: string}
     */
    private function getChannelParticipant(int $chatId, array $inputPeer): array
    {
        $channel = $this->getChannel(ChatId::toChannelId($chatId)) ?? [];
        if ($this->isBroadcastChannel($chatId) && !ParticipantStatus::fromServerChannel($channel)->isAdministrator()) {
            throw new QueryError(400, 'Member list is inaccessible');
        }

        try {
            $result = $this->invoke('channels.getParticipant', ['channel' => $this->inputChannelOfChat($chatId), 'participant' => $inputPeer]);
        } catch (\Throwable $e) {
            $error = QueryError::fromThrowable($e);
            if ($error->getMessage() === 'USER_NOT_PARTICIPANT') {
                return ['status' => ParticipantStatus::left(), 'tag' => ''];
            }
            throw $error;
        }

        $member = ParticipantStatus::fromChannelParticipant($result['participant'] ?? [], empty($channel['megagroup']) ? 'broadcast' : 'megagroup');

        return ['status' => $member['status'], 'tag' => $member['rank']];
    }

    public function processGetChatMemberQuery(Query $query): void
    {
        $chatIdStr = $query->arg('chat_id');
        $userId = self::getUserIdArg($query);
        $chatId = $this->checkChat($chatIdStr, $userId === $this->getMyId() ? self::ACCESS_READ : self::ACCESS_READ_MEMBERS);

        $this->checkUserNoFail($userId);
        $member = $this->tdGetChatMember($chatId, $userId);

        $query->answerQuery($member['status']->chatMemberObject(JsonUser::make($userId, $this), $this->getChatTypeName($chatId), $member['tag']));
    }

    public function processGetChatAdministratorsQuery(Query $query): void
    {
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_READ_MEMBERS);
        $returnBots = self::boolArg($query, 'return_bots');
        $chatType = $this->getChatTypeName($chatId);

        switch (ChatId::type($chatId)) {
            case ChatId::TYPE_PRIVATE:
                throw new QueryError(400, 'Bad Request: there are no administrators in the private chat');

            case ChatId::TYPE_GROUP:
                $members = $this->getBasicGroupParticipants($chatId);
                $administratorsOnly = true;
                break;

            default:
                $channelStatus = ParticipantStatus::fromServerChannel($this->getChannel(ChatId::toChannelId($chatId)) ?? []);
                if ($this->isMonoforum($chatId) || ($this->isBroadcastChannel($chatId) && !$channelStatus->isAdministrator())) {
                    throw new QueryError(400, 'Member list is inaccessible');
                }
                try {
                    $result = $this->invoke('channels.getParticipants', [
                        'channel' => $this->inputChannelOfChat($chatId),
                        'filter' => ['_' => 'channelParticipantsAdmins'],
                        'offset' => 0,
                        'limit' => 100,
                        'hash' => 0,
                    ]);
                } catch (\Throwable $e) {
                    throw QueryError::fromThrowable($e);
                }
                $channelType = $chatType === 'channel' ? 'broadcast' : 'megagroup';
                $members = [];
                foreach ($result['participants'] ?? [] as $participant) {
                    $member = ParticipantStatus::fromChannelParticipant($participant, $channelType);
                    if (!$member['status']->isAdministrator()) {
                        continue;
                    }
                    $members[] = [
                        'user_id' => $member['user_id'] !== 0 ? $member['user_id'] : ChatId::fromPeer($member['peer']),
                        'is_user' => $member['user_id'] !== 0 || ($member['peer']['_'] ?? '') === 'peerUser',
                        'status' => $member['status'],
                        'tag' => $member['rank'],
                    ];
                }
                $administratorsOnly = false;
        }

        $answer = [];
        foreach ($members as $member) {
            if (($member['is_user'] ?? true) === false) {
                continue;
            }
            $userId = $member['user_id'];
            if (!$returnBots && !empty($this->getUser($userId)['bot']) && $userId !== $this->getMyId()) {
                continue;
            }
            if ($administratorsOnly && !$member['status']->isAdministrator()) {
                continue;
            }
            $answer[] = $member['status']->chatMemberObject(JsonUser::make($userId, $this), $chatType, $member['tag']);
        }

        $query->answerQuery($answer);
    }

    public function processGetChatMemberCountQuery(Query $query): void
    {
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_READ_MEMBERS);

        switch (ChatId::type($chatId)) {
            case ChatId::TYPE_PRIVATE:
                $query->answerQuery(1 + ($chatId !== $this->getMyId() ? 1 : 0));

                return;
            case ChatId::TYPE_GROUP:
                $query->answerQuery((int) ($this->getBasicGroup(ChatId::toBasicGroupId($chatId))['participants_count'] ?? 0));

                return;
            default:
                $full = $this->getChatFull($chatId, true);
                $query->answerQuery((int) ($full['participants_count'] ?? $this->getChannel(ChatId::toChannelId($chatId))['participants_count'] ?? 0));
        }
    }

    public function processLeaveChatQuery(Query $query): void
    {
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_READ);

        // td_api::leaveChat: set_dialog_participant_status(me, Left)
        switch (ChatId::type($chatId)) {
            case ChatId::TYPE_PRIVATE:
                throw new QueryError(400, 'Chat member status can\'t be changed in private chats');
            case ChatId::TYPE_GROUP:
                $this->deleteChatParticipant($chatId, $this->getMyId(), false);
                break;
            default:
                $status = ParticipantStatus::fromServerChannel($this->getChannel(ChatId::toChannelId($chatId)) ?? []);
                if ($status->type === ParticipantStatus::CREATOR) {
                    if (!$status->isMember()) {
                        break;
                    }
                    $this->setChannelMemberStatus($chatId, $this->getMyId(), ParticipantStatus::creator(false, $status->isAnonymous()), false);
                } else {
                    $this->setChannelMemberStatus($chatId, $this->getMyId(), ParticipantStatus::left(), false);
                }
        }

        $query->answerQuery(true);
    }

    /**
     * DialogParticipantManager::delete_chat_participant.
     */
    private function deleteChatParticipant(int $chatId, int $userId, bool $revokeMessages): void
    {
        $chat = $this->getBasicGroup(ChatId::toBasicGroupId($chatId));
        if ($chat === null) {
            throw new QueryError(400, 'Chat info not found');
        }
        if (!self::isBasicGroupActive($chat)) {
            throw new QueryError(400, 'Chat is deactivated');
        }
        $status = $this->getChatPermissions($chatId);
        if (!$status->isMember()) {
            if ($userId === $this->getMyId()) {
                return;
            }
            throw new QueryError(400, 'Not in the chat');
        }

        $this->invokeOrFail('messages.deleteChatUser', array_filter([
            'revoke_history' => $revokeMessages,
            'chat_id' => ChatId::toBasicGroupId($chatId),
            'user_id' => $this->inputUser($userId),
        ], static fn ($value) => $value !== false));
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function invokeOrFail(string $method, array $params = []): mixed
    {
        try {
            return $this->invoke($method, $params);
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }
    }

    public function processPromoteChatMemberQuery(Query $query): void
    {
        $chatIdStr = $query->arg('chat_id');
        $userId = self::getUserIdArg($query);
        $rights = [];
        foreach (['can_manage_chat', 'can_change_info', 'can_post_messages', 'can_edit_messages', 'can_delete_messages',
            'can_invite_users', 'can_restrict_members', 'can_pin_messages', 'can_manage_topics', 'can_promote_members'] as $field) {
            $rights[$field] = self::boolArg($query, $field);
        }
        $rights['can_manage_video_chats'] = self::boolArg($query, 'can_manage_voice_chats') || self::boolArg($query, 'can_manage_video_chats');
        foreach (['can_post_stories', 'can_edit_stories', 'can_delete_stories', 'can_manage_direct_messages', 'can_manage_tags',
            'can_send_welcome_messages', 'is_anonymous'] as $field) {
            $rights[$field] = self::boolArg($query, $field);
        }
        $isPromotion = in_array(true, $rights, true);

        $chatId = $this->checkChat($chatIdStr, self::ACCESS_WRITE);
        if (ChatId::type($chatId) !== ChatId::TYPE_SUPERGROUP) {
            throw new QueryError(400, 'Bad Request: method is available only in supergroup and channel chats');
        }
        if ($this->isBroadcastChannel($chatId) && $isPromotion && !$query->hasArg('can_restrict_members')) {
            $rights['can_restrict_members'] = true;
        }

        $channelType = $this->isBroadcastChannel($chatId) ? 'broadcast' : 'megagroup';
        $newStatus = ParticipantStatus::administrator(ParticipantStatus::administratorRights($rights, $channelType), '', true);
        $this->setChannelMemberStatus($chatId, $userId, $newStatus, false);
        $query->answerQuery(true);
    }

    /**
     * DialogParticipantManager::set_channel_participant_status for a user.
     */
    private function setChannelMemberStatus(int $chatId, int $userId, ParticipantStatus $newStatus, bool $ignoreRestrictedIsMember): void
    {
        $channel = $this->getChannel(ChatId::toChannelId($chatId));
        if ($channel === null) {
            throw new QueryError(400, 'Chat info not found');
        }

        if ($userId === $this->getMyId()) {
            $oldStatus = ParticipantStatus::fromServerChannel($channel);
        } else {
            $this->checkUserNoFail($userId);
            $oldStatus = $this->tdGetChatMemberStatusForChange($chatId, $userId);
        }

        $old = new ParticipantStatus($oldStatus->type, $oldStatus->flags, $oldStatus->untilDate);
        if ($old->equals($newStatus) && $old->type !== ParticipantStatus::CREATOR) {
            return;
        }

        $needAdd = $needPromote = $needRestrict = false;
        if ($newStatus->type === ParticipantStatus::CREATOR || $old->type === ParticipantStatus::CREATOR) {
            if ($this->isMonoforum($chatId)) {
                throw new QueryError(400, 'Can\'t edit chat owner');
            }
            if ($old->type !== ParticipantStatus::CREATOR) {
                throw new QueryError(400, 'Can\'t add another owner to the chat');
            }
            if ($newStatus->type !== ParticipantStatus::CREATOR) {
                throw new QueryError(400, 'Can\'t remove chat owner');
            }
            if ($userId !== $this->getMyId()) {
                throw new QueryError(400, 'Not enough rights to edit chat owner rights');
            }
            if ($newStatus->isMember() === $old->isMember()) {
                $this->invokeOrFail('channels.editAdmin', [
                    'channel' => $this->inputChannelOfChat($chatId), 'user_id' => $this->inputUser($userId),
                    'admin_rights' => ParticipantStatus::chatAdminRights($newStatus->flags), 'rank' => '',
                ]);

                return;
            }
            if ($newStatus->isMember()) {
                $needAdd = true;
            } else {
                $needRestrict = true;
            }
        } elseif ($newStatus->isAdministrator()) {
            $needPromote = true;
        } elseif (!$newStatus->isMember() || $newStatus->type === ParticipantStatus::RESTRICTED) {
            if ($newStatus->isMember() && !$old->isMember()) {
                $needAdd = $old->withIsMember(true)->equals($newStatus);
                $needRestrict = !$needAdd;
            } else {
                $needRestrict = true;
            }
        } elseif ($old->isAdministrator()) {
            $needPromote = true;
        } elseif ($old->type === ParticipantStatus::RESTRICTED || $old->type === ParticipantStatus::BANNED) {
            $needRestrict = true;
        } else {
            $needAdd = true;
        }

        if ($needPromote) {
            if ($this->isMonoforum($chatId)) {
                throw new QueryError(400, 'Can\'t promote members in the chat');
            }
            if ($userId === $this->getMyId()) {
                if ($newStatus->isAdministrator()) {
                    throw new QueryError(400, 'Can\'t promote self');
                }
            } elseif (!$this->getChatPermissions($chatId)->canPromoteMembers()) {
                throw new QueryError(400, 'Not enough rights');
            }
            $this->invokeOrFail('channels.editAdmin', [
                'channel' => $this->inputChannelOfChat($chatId),
                'user_id' => $this->inputUser($userId),
                'admin_rights' => ParticipantStatus::chatAdminRights($newStatus->isAdministrator() ? $newStatus->flags : 0),
                'rank' => '',
            ]);

            return;
        }
        if ($needRestrict) {
            $this->restrictChannelParticipant($chatId, $this->inputPeer($userId), $userId === $this->getMyId(), $newStatus, $old, $ignoreRestrictedIsMember);

            return;
        }

        throw new QueryError(400, 'Bots can\'t add new chat members');
    }

    /**
     * The current status of a user in a channel, for a status change.
     */
    private function tdGetChatMemberStatusForChange(int $chatId, int $userId): ParticipantStatus
    {
        return $this->getChannelParticipant($chatId, $this->inputPeer($userId))['status'];
    }

    /**
     * DialogParticipantManager::restrict_channel_participant.
     *
     * @param array<string, mixed> $inputPeer
     */
    private function restrictChannelParticipant(int $chatId, ?array $inputPeer, bool $isSelf, ParticipantStatus $newStatus,
        ParticipantStatus $oldStatus, bool $ignoreIsMember): void
    {
        $myStatus = ParticipantStatus::fromServerChannel($this->getChannel(ChatId::toChannelId($chatId)) ?? []);
        if (!$myStatus->isMember() && $myStatus->type !== ParticipantStatus::CREATOR) {
            if ($isSelf) {
                if ($newStatus->isMember()) {
                    throw new QueryError(400, 'Can\'t unrestrict self');
                }

                return;
            }
            throw new QueryError(400, 'Not in the chat');
        }
        if ($inputPeer === null) {
            throw new QueryError(400, 'Member not found');
        }
        if ($isSelf) {
            if ($newStatus->type === ParticipantStatus::RESTRICTED || $newStatus->type === ParticipantStatus::BANNED) {
                throw new QueryError(400, 'Can\'t restrict self');
            }
            if ($newStatus->isMember()) {
                throw new QueryError(400, 'Can\'t unrestrict self');
            }
            if ($this->isMonoforum($chatId)) {
                throw new QueryError(400, 'Can\'t leave channel direct messages chats; leave the parent chat instead');
            }
            $this->invokeOrFail('channels.leaveChannel', ['channel' => $this->inputChannelOfChat($chatId)]);

            return;
        }
        if ($this->isMonoforum($chatId)) {
            throw new QueryError(400, 'Can\'t restrict members in the chat; users must be banned in the corresponding channel chat');
        }
        if (!$this->getChatPermissions($chatId)->canRestrictMembers()) {
            throw new QueryError(400, 'Not enough rights to restrict/unrestrict chat member');
        }

        $editBanned = function (ParticipantStatus $status) use ($chatId, $inputPeer): void {
            $this->invokeOrFail('channels.editBanned', [
                'channel' => $this->inputChannelOfChat($chatId),
                'participant' => $inputPeer,
                'banned_rights' => ParticipantStatus::chatBannedRights($status->flags, $status->type === ParticipantStatus::BANNED, $status->untilDate),
            ]);
        };

        if ($oldStatus->isMember() && !$newStatus->isMember() && $newStatus->type !== ParticipantStatus::BANNED && !$ignoreIsMember) {
            // a member can be made Left only by kicking first
            $editBanned(ParticipantStatus::banned($this->getUnixTime() + 60));
            \Swoole\Coroutine::sleep(1.0);
            $editBanned($newStatus);

            return;
        }
        if ($newStatus->isMember() && !$oldStatus->isMember() && !$ignoreIsMember) {
            $editBanned($newStatus->withIsMember(false));

            return;
        }

        $editBanned($newStatus);
    }

    public function processSetChatAdministratorCustomTitleQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);
        $chatType = $this->getChatTypeName($chatId);
        if ($chatType !== 'group' && $chatType !== 'supergroup') {
            throw new QueryError(400, 'Bad Request: method is available only in groups and supergroups');
        }

        $this->checkUserNoFail($userId);
        $member = $this->tdGetChatMember($chatId, $userId);
        $status = $member['status'];
        if ($status->type === ParticipantStatus::CREATOR) {
            throw new QueryError(400, 'Bad Request: only the owner can edit their custom title');
        }
        if ($status->type !== ParticipantStatus::ADMINISTRATOR) {
            throw new QueryError(400, 'Bad Request: user is not an administrator');
        }
        if (!$status->canBeEdited()) {
            throw new QueryError(400, 'Bad Request: not enough rights to change custom title of the user');
        }

        $this->setChatMemberRank($chatId, $userId, $query->arg('custom_title'));
        $query->answerQuery(true);
    }

    public function processSetChatMemberTagQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);
        $chatType = $this->getChatTypeName($chatId);
        if ($chatType !== 'group' && $chatType !== 'supergroup') {
            throw new QueryError(400, 'Bad Request: method is available only in groups and supergroups');
        }

        $this->checkUserNoFail($userId);
        $this->setChatMemberRank($chatId, $userId, $query->arg('tag'));
        $query->answerQuery(true);
    }

    /**
     * DialogParticipantManager::set_dialog_participant_rank.
     */
    private function setChatMemberRank(int $chatId, int $userId, string $rank): void
    {
        $this->invokeOrFail('messages.editChatParticipantRank', [
            'peer' => $this->inputPeer($chatId),
            'participant' => $this->inputPeer($userId),
            'rank' => $rank,
        ]);
    }

    public function processBanChatMemberQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $untilDate = self::getIntegerArg($query, 'until_date', 0);
        $revokeMessages = self::boolArg($query, 'revoke_messages');
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);
        $this->checkUserNoFail($userId);

        switch (ChatId::type($chatId)) {
            case ChatId::TYPE_PRIVATE:
                throw new QueryError(400, 'Can\'t ban members in private chats');
            case ChatId::TYPE_GROUP:
                $this->deleteChatParticipant($chatId, $userId, $revokeMessages);
                break;
            default:
                $this->setChannelMemberStatus($chatId, $userId,
                    ParticipantStatus::banned(ParticipantStatus::fixInputUntilDate($untilDate, $this->getUnixTime())), false);
        }

        $query->answerQuery(true);
    }

    public function processRestrictChatMemberQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $untilDate = self::getIntegerArg($query, 'until_date', 0);
        $allowLegacy = true;
        $useIndependentChatPermissions = self::boolArg($query, 'use_independent_chat_permissions');
        $permissions = $this->getChatPermissionsArg($query, $allowLegacy, $useIndependentChatPermissions);

        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);
        if ($this->getChatTypeName($chatId) !== 'supergroup') {
            throw new QueryError(400, 'Bad Request: method is available only in supergroups');
        }

        $this->checkUserNoFail($userId);
        $member = $this->tdGetChatMember($chatId, $userId);
        $current = $member['status'];
        if ($allowLegacy && $current->type === ParticipantStatus::RESTRICTED) {
            $old = ParticipantStatus::permissionsObject($current->flags);
            foreach (['can_send_polls', 'can_change_info', 'can_invite_users', 'can_pin_messages', 'can_create_topics'] as $field) {
                $permissions[$field] = $old[$field];
            }
        }

        $newStatus = ParticipantStatus::restricted(
            ParticipantStatus::restrictedRights($permissions, 'megagroup'),
            $current->isChatMember(),
            ParticipantStatus::fixInputUntilDate($untilDate, $this->getUnixTime()),
            'megagroup',
        );
        $this->setChannelMemberStatus($chatId, $userId, $newStatus, true);
        $query->answerQuery(true);
    }

    public function processUnbanChatMemberQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $onlyIfBanned = self::boolArg($query, 'only_if_banned');
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);
        if (ChatId::type($chatId) !== ChatId::TYPE_SUPERGROUP) {
            throw new QueryError(400, 'Bad Request: method is available only in supergroup and channel chats');
        }

        $this->checkUserNoFail($userId);
        if ($onlyIfBanned) {
            $member = $this->tdGetChatMember($chatId, $userId);
            if ($member['status']->type !== ParticipantStatus::BANNED) {
                $query->answerQuery(true);

                return;
            }
        }

        $this->setChannelMemberStatus($chatId, $userId, ParticipantStatus::left(), false);
        $query->answerQuery(true);
    }

    public function processBanChatSenderChatQuery(Query $query): void
    {
        $senderChatIdStr = $query->arg('sender_chat_id');
        $untilDate = self::getIntegerArg($query, 'until_date', 0);
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);
        $senderChatId = $this->checkChatNoFail($senderChatIdStr);

        $this->banChatSender($chatId, $senderChatId, ParticipantStatus::banned(ParticipantStatus::fixInputUntilDate($untilDate, $this->getUnixTime())));
        $query->answerQuery(true);
    }

    public function processUnbanChatSenderChatQuery(Query $query): void
    {
        $senderChatIdStr = $query->arg('sender_chat_id');
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);
        $senderChatId = $this->checkChatNoFail($senderChatIdStr);

        $this->banChatSender($chatId, $senderChatId, ParticipantStatus::left());
        $query->answerQuery(true);
    }

    /**
     * Ban or unban a chat as a member (set_channel_participant_status for a chat).
     */
    private function banChatSender(int $chatId, int $senderChatId, ParticipantStatus $newStatus): void
    {
        $senderPeer = $this->getSenderChatInputPeer($senderChatId);
        switch (ChatId::type($chatId)) {
            case ChatId::TYPE_PRIVATE:
                throw new QueryError(400, $newStatus->type === ParticipantStatus::BANNED ? 'Can\'t ban members in private chats' : 'Chat member status can\'t be changed in private chats');
            case ChatId::TYPE_GROUP:
                if ($newStatus->type === ParticipantStatus::BANNED) {
                    throw new QueryError(400, 'Can\'t ban chats in basic groups');
                }

                return;
        }
        if ($senderPeer !== null && ($senderPeer['_'] === 'inputPeerUser' || $senderPeer['_'] === 'inputPeerSelf')) {
            $this->setChannelMemberStatus($chatId, (int) ($senderPeer['user_id'] ?? $this->getMyId()), $newStatus, false);

            return;
        }
        $oldStatus = $newStatus->type === ParticipantStatus::BANNED ? ParticipantStatus::left() : ParticipantStatus::banned();
        $this->restrictChannelParticipant($chatId, $senderPeer, false, $newStatus, $oldStatus, false);
    }

    public function processApproveChatJoinRequestQuery(Query $query): void
    {
        $this->processChatJoinRequest($query, true);
    }

    public function processDeclineChatJoinRequestQuery(Query $query): void
    {
        $this->processChatJoinRequest($query, false);
    }

    private function processChatJoinRequest(Query $query, bool $approve): void
    {
        $userId = self::getUserIdArg($query);
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);
        $this->checkUserNoFail($userId);

        $this->invokeOrFail('messages.hideChatJoinRequest', array_filter([
            'approved' => $approve,
            'peer' => $this->inputPeer($chatId),
            'user_id' => $this->inputUser($userId),
        ], static fn ($value) => $value !== false));
        $query->answerQuery(true);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Chat settings
    // ═════════════════════════════════════════════════════════════════

    public function processSetChatTitleQuery(Query $query): void
    {
        $title = $query->arg('title');
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);

        $newTitle = self::cleanName($title, 128);
        if ($newTitle === '') {
            throw new QueryError(400, 'Title must be non-empty');
        }
        $this->checkCanChangeInfo($chatId, 'Can\'t change private chat title', 'Not enough rights to change chat title');

        $current = ChatId::type($chatId) === ChatId::TYPE_GROUP
            ? ($this->getBasicGroup(ChatId::toBasicGroupId($chatId))['title'] ?? '')
            : ($this->getChannel(ChatId::toChannelId($chatId))['title'] ?? '');
        if ($current !== $newTitle) {
            if (ChatId::type($chatId) === ChatId::TYPE_GROUP) {
                $this->invokeOrFail('messages.editChatTitle', ['chat_id' => ChatId::toBasicGroupId($chatId), 'title' => $newTitle]);
            } else {
                $this->invokeOrFail('channels.editTitle', ['channel' => $this->inputChannelOfChat($chatId), 'title' => $newTitle]);
            }
        }
        $query->answerQuery(true);
    }

    /**
     * The rights check of DialogManager::set_dialog_title/set_dialog_photo.
     */
    private function checkCanChangeInfo(int $chatId, string $privateError, string $rightsError): void
    {
        switch (ChatId::type($chatId)) {
            case ChatId::TYPE_PRIVATE:
                throw new QueryError(400, $privateError);
            case ChatId::TYPE_GROUP:
                $status = $this->getChatPermissions($chatId);
                if (!$status->canChangeInfoAndSettings() || !$status->isAdministrator()) {
                    throw new QueryError(400, $rightsError);
                }
                break;
            default:
                if (!$this->getChatPermissions($chatId)->canChangeInfoAndSettings()) {
                    throw new QueryError(400, $rightsError);
                }
        }
    }

    /**
     * td::clean_name.
     */
    public static function cleanName(string $value, int $maxLength): string
    {
        $value = TextFormatting::stripEmptyCharacters($value, $maxLength);
        $result = '';
        $previousSpace = false;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $c = $value[$i];
            if ($c === ' ' || $c === "\n") {
                if (!$previousSpace) {
                    $result .= ' ';
                    $previousSpace = true;
                }
                continue;
            }
            if ($c === "\xC2" && ($value[$i + 1] ?? '') === "\xA0") {
                if (!$previousSpace) {
                    $result .= ' ';
                    $previousSpace = true;
                }
                $i++;
                continue;
            }
            $result .= $c;
            $previousSpace = false;
        }

        return trim($result, " \t\r\n\0\x0B");
    }

    public function processSetChatDescriptionQuery(Query $query): void
    {
        $description = $query->arg('description');
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);

        if (ChatId::type($chatId) === ChatId::TYPE_PRIVATE) {
            throw new QueryError(400, 'Can\'t change private chat description');
        }
        $newDescription = TextFormatting::stripEmptyCharacters($description, 255);
        if (!$this->getChatPermissions($chatId)->canChangeInfoAndSettings()) {
            throw new QueryError(400, 'Not enough rights to set chat description');
        }

        $this->invokeOrFail('messages.editChatAbout', ['peer' => $this->inputPeer($chatId), 'about' => $newDescription]);
        $this->forgetChatFull($chatId);
        $query->answerQuery(true);
    }

    public function processSetChatPhotoQuery(Query $query): void
    {
        $photo = $this->getInputFile($query, 'photo', true);
        if ($photo === null) {
            if ($query->arg('photo') === '') {
                throw QueryError::badRequest('There is no photo in the request');
            }
            throw QueryError::badRequest('Photo must be uploaded as an InputFile');
        }
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);

        $this->checkCanChangeInfo($chatId, 'Can\'t change private chat photo', 'Not enough rights to change chat photo');
        $file = $this->getInputFileId(FileType::PHOTO, $photo, true);
        $inputChatPhoto = $file === null
            ? ['_' => 'inputChatPhotoEmpty']
            : ['_' => 'inputChatUploadedPhoto', 'file' => $this->uploadFile($file)];
        $this->editChatPhoto($chatId, $inputChatPhoto);
        $query->answerQuery(true);
    }

    public function processDeleteChatPhotoQuery(Query $query): void
    {
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);
        $this->checkCanChangeInfo($chatId, 'Can\'t change private chat photo', 'Not enough rights to change chat photo');
        $this->editChatPhoto($chatId, ['_' => 'inputChatPhotoEmpty']);
        $query->answerQuery(true);
    }

    /**
     * @param array<string, mixed> $inputChatPhoto
     */
    private function editChatPhoto(int $chatId, array $inputChatPhoto): void
    {
        if (ChatId::type($chatId) === ChatId::TYPE_GROUP) {
            $this->invokeOrFail('messages.editChatPhoto', ['chat_id' => ChatId::toBasicGroupId($chatId), 'photo' => $inputChatPhoto]);
        } else {
            $this->invokeOrFail('channels.editPhoto', ['channel' => $this->inputChannelOfChat($chatId), 'photo' => $inputChatPhoto]);
        }
    }

    public function processSetChatPermissionsQuery(Query $query): void
    {
        $allowLegacy = false;
        $useIndependentChatPermissions = self::boolArg($query, 'use_independent_chat_permissions');
        $permissions = $this->getChatPermissionsArg($query, $allowLegacy, $useIndependentChatPermissions);
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);

        $channelType = 'unknown';
        switch (ChatId::type($chatId)) {
            case ChatId::TYPE_PRIVATE:
                throw new QueryError(400, 'Can\'t change private chat permissions');
            case ChatId::TYPE_GROUP:
                if (!$this->getChatPermissions($chatId)->canRestrictMembers()) {
                    throw new QueryError(400, 'Not enough rights to change chat permissions');
                }
                $current = $this->getBasicGroup(ChatId::toBasicGroupId($chatId))['default_banned_rights'] ?? null;
                break;
            default:
                if ($this->isBroadcastChannel($chatId)) {
                    throw new QueryError(400, 'Can\'t change channel chat permissions');
                }
                if (!$this->getChatPermissions($chatId)->canRestrictMembers()) {
                    throw new QueryError(400, 'Not enough rights to change chat permissions');
                }
                $channelType = 'megagroup';
                $current = $this->getChannel(ChatId::toChannelId($chatId))['default_banned_rights'] ?? null;
        }

        $newRights = ParticipantStatus::restrictedRights($permissions, $channelType);
        if ($newRights !== ParticipantStatus::restrictedRightsFromServer($current, $channelType)) {
            $this->invokeOrFail('messages.editChatDefaultBannedRights', [
                'peer' => $this->inputPeer($chatId),
                'banned_rights' => ParticipantStatus::chatBannedRights($newRights),
            ]);
        }
        $query->answerQuery(true);
    }

    /**
     * Client::get_chat_permissions: td_api-named permissions; $allowLegacy
     * says whether the legacy flat arguments were used.
     *
     * @return array<string, bool>
     */
    protected function getChatPermissionsArg(Query $query, bool &$allowLegacy, bool $useIndependentChatPermissions): array
    {
        $p = array_fill_keys(['can_send_basic_messages', 'can_send_audios', 'can_send_documents', 'can_send_photos', 'can_send_videos',
            'can_send_video_notes', 'can_send_voice_notes', 'can_send_polls', 'can_send_other_messages', 'can_add_link_previews',
            'can_react_to_messages', 'can_edit_tag', 'can_change_info', 'can_invite_users', 'can_pin_messages', 'can_create_topics'], false);

        if ($query->hasArg('permissions')) {
            $allowLegacy = false;
            $value = TdJson::tryDecode($query->arg('permissions'));
            if ($value === null) {
                throw QueryError::badRequest('Can\'t parse permissions JSON object');
            }
            if (!$value->isObject()) {
                throw QueryError::badRequest('Object expected as permissions');
            }
            $o = $value->object();
            try {
                $p['can_send_basic_messages'] = $o->getOptionalBoolField('can_send_messages');
                $p['can_send_polls'] = $o->getOptionalBoolField('can_send_polls');
                $p['can_send_other_messages'] = $o->getOptionalBoolField('can_send_other_messages');
                $p['can_add_link_previews'] = $o->getOptionalBoolField('can_add_web_page_previews');
                $p['can_change_info'] = $o->getOptionalBoolField('can_change_info');
                $p['can_invite_users'] = $o->getOptionalBoolField('can_invite_users');
                $p['can_pin_messages'] = $o->getOptionalBoolField('can_pin_messages');
                $p['can_create_topics'] = $o->hasField('can_manage_topics') ? $o->getOptionalBoolField('can_manage_topics') : $p['can_pin_messages'];
                $media = ['can_send_audios', 'can_send_documents', 'can_send_photos', 'can_send_videos', 'can_send_video_notes', 'can_send_voice_notes'];
                $hasIndependent = false;
                foreach ($media as $field) {
                    $hasIndependent = $hasIndependent || $o->hasField($field);
                }
                if ($hasIndependent) {
                    foreach ($media as $field) {
                        $p[$field] = $o->getOptionalBoolField($field);
                    }
                } else {
                    $canSendMedia = $o->getOptionalBoolField('can_send_media_messages');
                    foreach ($media as $field) {
                        $p[$field] = $canSendMedia;
                    }
                    if ($canSendMedia && !$useIndependentChatPermissions) {
                        $p['can_send_basic_messages'] = true;
                    }
                }
                $p['can_edit_tag'] = $o->hasField('can_edit_tag') ? $o->getOptionalBoolField('can_edit_tag') : $p['can_pin_messages'];
                $p['can_react_to_messages'] = $o->hasField('can_react_to_messages')
                    ? $o->getOptionalBoolField('can_react_to_messages') : $p['can_send_basic_messages'];
            } catch (\LaraGram\Server\Support\JsonError $e) {
                throw QueryError::badRequest('Can\'t parse chat permissions: '.$e->getMessage());
            }

            if (($p['can_send_other_messages'] || $p['can_add_link_previews']) && !$useIndependentChatPermissions) {
                foreach (['can_send_audios', 'can_send_documents', 'can_send_photos', 'can_send_videos', 'can_send_video_notes', 'can_send_voice_notes', 'can_send_basic_messages'] as $field) {
                    $p[$field] = true;
                }
            }
            if ($p['can_send_polls'] && !$useIndependentChatPermissions) {
                $p['can_send_basic_messages'] = true;
            }
        } elseif ($allowLegacy) {
            $allowLegacy = false;
            $p['can_send_basic_messages'] = self::boolArg($query, 'can_send_messages');
            $canSendMedia = self::boolArg($query, 'can_send_media_messages');
            $p['can_send_other_messages'] = self::boolArg($query, 'can_send_other_messages');
            $p['can_add_link_previews'] = self::boolArg($query, 'can_add_web_page_previews');
            if (($p['can_send_other_messages'] || $p['can_add_link_previews']) && !$useIndependentChatPermissions) {
                $canSendMedia = true;
            }
            if ($canSendMedia && !$useIndependentChatPermissions) {
                $p['can_send_basic_messages'] = true;
            }
            if ($p['can_send_basic_messages'] && $canSendMedia && $p['can_send_other_messages'] && $p['can_add_link_previews']) {
                $p['can_send_polls'] = $p['can_change_info'] = $p['can_invite_users'] = $p['can_pin_messages'] = $p['can_create_topics'] = true;
            } elseif ($query->hasArg('can_send_messages') || $query->hasArg('can_send_media_messages')
                || $query->hasArg('can_send_other_messages') || $query->hasArg('can_add_web_page_previews')) {
                $allowLegacy = true;
            }
            foreach (['can_send_audios', 'can_send_documents', 'can_send_photos', 'can_send_videos', 'can_send_video_notes', 'can_send_voice_notes'] as $field) {
                $p[$field] = $canSendMedia;
            }
            $p['can_edit_tag'] = $p['can_pin_messages'];
            $p['can_react_to_messages'] = $p['can_send_basic_messages'];
        }

        return $p;
    }

    public function processSetChatStickerSetQuery(Query $query): void
    {
        $stickerSetName = $query->arg('sticker_set_name');
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);
        if ($this->getChatTypeName($chatId) !== 'supergroup') {
            throw new QueryError(400, 'Bad Request: method is available only in supergroups');
        }

        $set = $this->resolveStickerSet($stickerSetName);
        $this->setSupergroupStickerSet($chatId, ['_' => 'inputStickerSetID', 'id' => $set['id'], 'access_hash' => $set['access_hash']]);
        $query->answerQuery(true);
    }

    public function processDeleteChatStickerSetQuery(Query $query): void
    {
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);
        if ($this->getChatTypeName($chatId) !== 'supergroup') {
            throw new QueryError(400, 'Bad Request: method is available only in supergroups');
        }

        $this->setSupergroupStickerSet($chatId, ['_' => 'inputStickerSetEmpty']);
        $query->answerQuery(true);
    }

    /**
     * ChatManager::set_channel_sticker_set.
     *
     * @param array<string, mixed> $inputStickerSet
     */
    private function setSupergroupStickerSet(int $chatId, array $inputStickerSet): void
    {
        if (!$this->getChatPermissions($chatId)->has(ParticipantStatus::CAN_CHANGE_INFO_AND_SETTINGS_ADMIN)) {
            throw new QueryError(400, 'Not enough rights to change supergroup sticker set');
        }
        $full = $this->getChatFull($chatId);
        if (empty($full['can_set_stickers'])) {
            throw new QueryError(400, 'Can\'t set supergroup sticker set');
        }

        $this->invokeOrFail('channels.setStickers', ['channel' => $this->inputChannelOfChat($chatId), 'stickerset' => $inputStickerSet]);
        $this->forgetChatFull($chatId);
    }

    /**
     * Client::resolve_sticker_set (td_api::searchStickerSet): the TL stickerSet.
     *
     * @return array<string, mixed>
     */
    public function resolveStickerSet(string $name): array
    {
        if ($name === '') {
            throw new QueryError(400, 'Bad Request: sticker_set_name is empty');
        }

        try {
            $result = $this->invoke('messages.getStickerSet', [
                'stickerset' => ['_' => 'inputStickerSetShortName', 'short_name' => self::cleanUsername($name)],
                'hash' => 0,
            ]);
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e, 'sticker set not found');
        }

        $set = $result['set'] ?? null;
        if ($set === null) {
            throw new QueryError(400, 'Sticker set not found', 'sticker set not found');
        }
        $this->stickerSetNames[(int) $set['id']] = (string) $set['short_name'];

        return $set;
    }

    /**
     * td::clean_username: lowercase (ASCII), without dots, trimmed.
     */
    public static function cleanUsername(string $username): string
    {
        return trim(strtolower(str_replace('.', '', $username)), " \t\r\n\0\x0B");
    }
}
