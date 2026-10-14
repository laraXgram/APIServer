<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Concerns;

use LaraGram\Server\Http\JsonWriter;
use LaraGram\Server\Messages\MessageConverter;
use LaraGram\Server\Messages\MessageInfo;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Objects\JsonChat;
use LaraGram\Server\Objects\JsonUser;
use LaraGram\Server\Td\ParticipantStatus;
use LaraGram\Server\Updates\UpdateType;

/**
 * The bot-only qts updates of UpdatesManager::process_qts_update that are not
 * messages or queries: chat members, join requests, boosts, reactions, paid
 * media purchases, managed bots, subscriptions and guest queries, with the
 * Client::add_update_* handlers and their JSON objects.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait ReceivesChatUpdates
{
    /** @var array<int, array{queue: list<MessageInfo>, active: bool}> chat id => guest messages */
    private array $newGuestQueryQueues = [];

    /**
     * @param array<string, mixed> $update a TL Update
     * @return bool whether the update was one of these
     */
    private function onChatUpdate(array $update): bool
    {
        switch ($update['_'] ?? '') {
            case 'updateBotStopped':
                $this->onUpdateBotStopped((int) $update['user_id'], (int) $update['date'], ($update['stopped'] ?? false) === true);
                break;
            case 'updateChatParticipant':
                $this->onUpdateChatParticipant($update);
                break;
            case 'updateChannelParticipant':
                $this->onUpdateChannelParticipant($update);
                break;
            case 'updateBotChatInviteRequester':
                $this->onUpdateChatInviteRequester($update);
                break;
            case 'updateBotChatBoost':
                $this->onUpdateChatBoost($update);
                break;
            case 'updateBotMessageReaction':
                $this->onUpdateMessageReaction($update);
                break;
            case 'updateBotMessageReactions':
                $this->onUpdateMessageReactions($update);
                break;
            case 'updateBotPurchasedPaidMedia':
                $userId = (int) $update['user_id'];
                if ($userId > 0) {
                    $this->addUpdate(UpdateType::PURCHASED_PAID_MEDIA, JsonWriter::encode([
                        'from' => JsonUser::make($userId, $this),
                        'paid_media_payload' => (string) $update['payload'],
                    ]), 86400, $userId + (12 << 33));
                }
                break;
            case 'updateManagedBot':
                $userId = (int) $update['user_id'];
                $this->addUpdate(UpdateType::MANAGED_BOT, JsonWriter::encode([
                    'user' => JsonUser::make($userId, $this),
                    'bot' => JsonUser::make((int) $update['bot_id'], $this),
                ]), 86400, $userId + (13 << 33));
                break;
            case 'updateBotStarsSubscription':
                $this->onUpdateStarsSubscription($update);
                break;
            case 'updateBotGuestChatQuery':
                $this->onUpdateGuestChatQuery($update);
                break;
            default:
                return false;
        }

        return true;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Chat members
    // ═════════════════════════════════════════════════════════════════

    /**
     * DialogParticipantManager::on_update_bot_stopped: the bot's own status in a private chat.
     */
    private function onUpdateBotStopped(int $userId, int $date, bool $isStopped): void
    {
        if ($date <= 0 || $this->getUser($userId) === null) {
            return;
        }
        $old = ParticipantStatus::banned();
        $new = ParticipantStatus::member();
        if ($isStopped) {
            [$old, $new] = [$new, $old];
        }
        $myId = $this->getMyId();
        $this->addChatMemberUpdate($userId, $userId, $date, null, false, false,
            ['member' => $myId, 'status' => $old], ['member' => $myId, 'status' => $new]);
    }

    /**
     * DialogParticipantManager::on_update_chat_participant.
     *
     * @param array<string, mixed> $update TL updateChatParticipant
     */
    private function onUpdateChatParticipant(array $update): void
    {
        $basicGroupId = (int) $update['chat_id'];
        $actorId = (int) $update['actor_id'];
        $date = (int) $update['date'];
        $oldParticipant = $update['prev_participant'] ?? null;
        $newParticipant = $update['new_participant'] ?? null;
        if ($basicGroupId <= 0 || $actorId <= 0 || $date <= 0 || ($oldParticipant === null && $newParticipant === null)) {
            return;
        }
        $chatId = ChatId::fromBasicGroup($basicGroupId);
        $chat = $this->getBasicGroup($basicGroupId);
        if ($chat === null) {
            return;
        }
        $chatDate = (int) ($chat['date'] ?? 0);
        $isCreator = !empty($chat['creator']);

        $convert = static function (array $participant) use ($chatDate, $isCreator): array {
            $userId = (int) ($participant['user_id'] ?? 0);
            $rank = (string) ($participant['rank'] ?? '');

            return match ($participant['_'] ?? '') {
                'chatParticipantCreator' => ['member' => $userId, 'inviter' => $userId, 'date' => $chatDate,
                    'status' => ParticipantStatus::creator(true, false, $rank)],
                'chatParticipantAdmin' => ['member' => $userId, 'inviter' => (int) ($participant['inviter_id'] ?? 0),
                    'date' => (int) ($participant['date'] ?? 0), 'status' => ParticipantStatus::groupAdministrator($isCreator, $rank)],
                default => ['member' => $userId, 'inviter' => (int) ($participant['inviter_id'] ?? 0),
                    'date' => (int) ($participant['date'] ?? 0), 'status' => ParticipantStatus::member(0, $rank)],
            };
        };
        [$old, $new] = self::participantPair($oldParticipant, $newParticipant, $convert);
        if ($old === null) {
            return;
        }

        [$invite, $viaJoinRequest] = $this->participantUpdateInvite($update['invite'] ?? null);
        $this->addChatMemberUpdate($chatId, $actorId, $date, $invite, $viaJoinRequest, false, $old, $new);
    }

    /**
     * DialogParticipantManager::on_update_channel_participant.
     *
     * @param array<string, mixed> $update TL updateChannelParticipant
     */
    private function onUpdateChannelParticipant(array $update): void
    {
        $channelId = (int) $update['channel_id'];
        $actorId = (int) $update['actor_id'];
        $date = (int) $update['date'];
        $oldParticipant = $update['prev_participant'] ?? null;
        $newParticipant = $update['new_participant'] ?? null;
        if ($channelId <= 0 || $actorId <= 0 || $date <= 0 || ($oldParticipant === null && $newParticipant === null)) {
            return;
        }
        $channel = $this->getChannel($channelId);
        if ($channel === null) {
            return;
        }
        $channelType = empty($channel['megagroup']) ? 'broadcast' : 'megagroup';

        $convert = static function (array $participant) use ($channelType): array {
            $member = ParticipantStatus::fromChannelParticipant($participant, $channelType);
            $type = $participant['_'] ?? '';
            $memberId = in_array($type, ['channelParticipantLeft', 'channelParticipantBanned'], true)
                ? ChatId::fromPeer($participant['peer'] ?? null)
                : (int) ($participant['user_id'] ?? 0);

            return [
                'member' => $memberId,
                'is_user' => !in_array($type, ['channelParticipantLeft', 'channelParticipantBanned'], true)
                    || ($participant['peer']['_'] ?? '') === 'peerUser',
                'inviter' => match ($type) {
                    'channelParticipantSelf' => (int) ($participant['inviter_id'] ?? 0),
                    'channelParticipantAdmin' => (int) ($participant['promoted_by'] ?? 0),
                    'channelParticipantBanned' => (int) ($participant['kicked_by'] ?? 0),
                    default => 0,
                },
                'date' => in_array($type, ['channelParticipantCreator', 'channelParticipantLeft'], true) ? 0 : (int) ($participant['date'] ?? 0),
                'status' => $member['status'],
            ];
        };
        [$old, $new] = self::participantPair($oldParticipant, $newParticipant, $convert);
        if ($old === null) {
            return;
        }
        if ($new['status']->isAdministrator() && !$new['status']->isCreator() && $actorId === $this->getMyId() && !$new['status']->canBeEdited()) {
            $new['status'] = $new['status']->withToggledCanBeEdited();
        }

        [$invite, $viaJoinRequest] = $this->participantUpdateInvite($update['invite'] ?? null);
        $this->addChatMemberUpdate(ChatId::fromChannel($channelId), $actorId, $date, $invite, $viaJoinRequest,
            !empty($update['via_chatlist']), $old, $new);
    }

    /**
     * The old and new DialogParticipant of an update; null when they are invalid.
     *
     * @param array<string, mixed>|null $oldParticipant
     * @param array<string, mixed>|null $newParticipant
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private static function participantPair(?array $oldParticipant, ?array $newParticipant, callable $convert): array
    {
        $left = static fn (array $participant): array => ['member' => $participant['member'], 'is_user' => $participant['is_user'] ?? true,
            'inviter' => 0, 'date' => 0, 'status' => ParticipantStatus::left()];
        if ($oldParticipant !== null) {
            $old = $convert($oldParticipant);
            $new = $newParticipant === null ? $left($old) : $convert($newParticipant);
        } else {
            $new = $convert($newParticipant);
            $old = $left($new);
        }
        if ($old['member'] !== $new['member'] || !self::isValidParticipant($old) || !self::isValidParticipant($new)) {
            return [null, null];
        }

        return [$old, $new];
    }

    /**
     * DialogParticipant::is_valid.
     *
     * @param array<string, mixed> $participant
     */
    private static function isValidParticipant(array $participant): bool
    {
        if ($participant['member'] === 0 || $participant['date'] < 0) {
            return false;
        }
        $status = $participant['status'];
        if ($status->isRestricted() || $status->isBanned() || ($status->isAdministrator() && !$status->isCreator())) {
            return $participant['inviter'] > 0;
        }

        return true;
    }

    /**
     * The invite link of a member update and whether it came from a join request.
     *
     * @param array<string, mixed>|null $invite TL ExportedChatInvite
     * @return array{0: array<string, mixed>|null, 1: bool}
     */
    private function participantUpdateInvite(?array $invite): array
    {
        if ($invite === null) {
            return [null, false];
        }
        if (($invite['_'] ?? '') === 'chatInvitePublicJoinRequests') {
            return [null, true];
        }

        return [self::dialogInviteLink($invite), false];
    }

    /**
     * DialogParticipantManager::send_update_chat_member + Client::add_update_chat_member.
     *
     * @param array<string, mixed>|null $invite DialogInviteLink
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private function addChatMemberUpdate(int $chatId, int $actorId, int $date, ?array $invite, bool $viaJoinRequest,
        bool $viaFolderInviteLink, array $old, array $new): void
    {
        $leftTime = $date + 86400 - $this->getUnixTime();
        if ($leftTime <= 0 || !($old['is_user'] ?? true) || !($new['is_user'] ?? true)) {
            return;
        }
        $userId = $old['member'];
        $isMy = $userId === $this->getMyId();
        $chatType = $this->getChatTypeName($chatId);

        $object = [
            'chat' => JsonChat::make($chatId, $this),
            'from' => JsonUser::make($actorId, $this),
            'date' => $date,
            'old_chat_member' => $old['status']->chatMemberObject(JsonUser::make($userId, $this), $chatType, $old['status']->rank),
            'new_chat_member' => $new['status']->chatMemberObject(JsonUser::make($userId, $this), $chatType, $new['status']->rank),
        ];
        if ($invite !== null) {
            $object['invite_link'] = $this->chatInviteLinkObject($invite);
        }
        if ($viaJoinRequest) {
            $object['via_join_request'] = true;
        }
        if ($viaFolderInviteLink) {
            $object['via_chat_folder_invite_link'] = true;
        }

        $this->addUpdate($isMy ? UpdateType::MY_CHAT_MEMBER : UpdateType::CHAT_MEMBER, JsonWriter::encode($object), $leftTime,
            ($isMy ? $chatId : $userId) + (($isMy ? 5 : 6) << 33));
    }

    // ═════════════════════════════════════════════════════════════════
    //  Join requests and boosts
    // ═════════════════════════════════════════════════════════════════

    /**
     * DialogParticipantManager::on_update_chat_invite_requester + Client::add_update_chat_join_request.
     *
     * @param array<string, mixed> $update TL updateBotChatInviteRequester
     */
    private function onUpdateChatInviteRequester(array $update): void
    {
        $chatId = ChatId::fromPeer($update['peer'] ?? null);
        $userId = (int) $update['user_id'];
        $date = (int) $update['date'];
        if ($date <= 0 || $this->getUser($userId) === null || $chatId === 0) {
            return;
        }
        $leftTime = $date + 86400 - $this->getUnixTime();
        if ($leftTime <= 0) {
            return;
        }
        [$invite] = $this->participantUpdateInvite($update['invite'] ?? null);

        $object = [
            'chat' => JsonChat::make($chatId, $this),
            'from' => JsonUser::make($userId, $this),
            'user_chat_id' => $userId,
            'date' => $date,
        ];
        $about = (string) ($update['about'] ?? '');
        if ($about !== '') {
            $object['bio'] = $about;
        }
        if ($invite !== null) {
            $object['invite_link'] = $this->chatInviteLinkObject($invite);
        }
        $queryId = (int) ($update['query_id'] ?? 0);
        if ($queryId !== 0) {
            $object['query_id'] = (string) $queryId;
        }
        $this->addUpdate(UpdateType::CHAT_JOIN_REQUEST, JsonWriter::encode($object), $leftTime, $userId + (6 << 33));
    }

    /**
     * BoostManager::on_update_dialog_boost + Client::add_update_chat_boost.
     *
     * @param array<string, mixed> $update TL updateBotChatBoost
     */
    private function onUpdateChatBoost(array $update): void
    {
        $chatId = ChatId::fromPeer($update['peer'] ?? null);
        $boost = $chatId === 0 ? null : $this->chatBoostObject($update['boost'] ?? []);
        if ($boost === null) {
            return;
        }
        $leftTime = $boost['add_date'] + 86400 - $this->getUnixTime();
        if ($leftTime <= 0) {
            return;
        }
        $chat = JsonChat::make($chatId, $this);
        if ($boost['expiration_date'] === 0) {
            $this->addUpdate(UpdateType::CHAT_BOOST_REMOVED, JsonWriter::encode([
                'chat' => $chat, 'boost_id' => $boost['boost_id'], 'remove_date' => $boost['add_date'], 'source' => $boost['source'],
            ]), $leftTime, $chatId + (7 << 33));
        } else {
            $this->addUpdate(UpdateType::CHAT_BOOST_UPDATED, JsonWriter::encode(['chat' => $chat, 'boost' => $boost]), $leftTime,
                $chatId + (7 << 33));
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  Reactions
    // ═════════════════════════════════════════════════════════════════

    /**
     * UpdatesManager (updateBotMessageReaction) + Client::add_update_message_reaction.
     *
     * @param array<string, mixed> $update
     */
    private function onUpdateMessageReaction(array $update): void
    {
        $chatId = ChatId::fromPeer($update['peer'] ?? null);
        $messageId = (int) $update['msg_id'];
        $date = (int) $update['date'];
        $actor = $update['actor'] ?? null;
        $actorId = ChatId::fromPeer($actor);
        $oldReactions = self::reactionTypes($update['old_reactions'] ?? []);
        $newReactions = self::reactionTypes($update['new_reactions'] ?? []);
        if ($chatId === 0 || $messageId <= 0 || $actorId === 0 || $date <= 0 || $oldReactions === $newReactions) {
            return;
        }
        $leftTime = $date + 86400 - $this->getUnixTime();
        if ($leftTime <= 0) {
            return;
        }

        $object = ['chat' => JsonChat::make($chatId, $this), 'message_id' => $messageId];
        if (($actor['_'] ?? '') === 'peerUser') {
            $object['user'] = JsonUser::make($actorId, $this);
        } else {
            $object['actor_chat'] = JsonChat::make($actorId, $this);
        }
        $object['date'] = $date;
        $object['old_reaction'] = array_values(array_filter(array_map(self::reactionTypeObject(...), $oldReactions)));
        $object['new_reaction'] = array_values(array_filter(array_map(self::reactionTypeObject(...), $newReactions)));
        $this->addUpdate(UpdateType::MESSAGE_REACTION, JsonWriter::encode($object), $leftTime, $chatId + (8 << 33));
    }

    /**
     * UpdatesManager (updateBotMessageReactions) + Client::add_update_message_reaction_count.
     *
     * @param array<string, mixed> $update
     */
    private function onUpdateMessageReactions(array $update): void
    {
        $chatId = ChatId::fromPeer($update['peer'] ?? null);
        $messageId = (int) $update['msg_id'];
        $date = (int) $update['date'];
        if ($chatId === 0 || $messageId <= 0 || $date <= 0) {
            return;
        }
        $reactions = [];
        foreach ($update['reactions'] ?? [] as $count) {
            $type = self::reactionTypes([$count['reaction'] ?? []])[0];
            if ($type === '' || (int) ($count['count'] ?? 0) <= 0) {
                continue;
            }
            $reactions[] = ['type' => self::reactionTypeObject($type), 'total_count' => (int) $count['count']];
        }
        $leftTime = $date + 86400 - $this->getUnixTime();
        if ($leftTime <= 0) {
            return;
        }
        $this->addUpdate(UpdateType::MESSAGE_REACTION_COUNT, JsonWriter::encode([
            'chat' => JsonChat::make($chatId, $this), 'message_id' => $messageId, 'date' => $date, 'reactions' => $reactions,
        ]), $leftTime, $chatId + (9 << 33));
    }

    /**
     * ReactionType(telegram_api::Reaction): the emoji, '#' + custom emoji id, '$' for paid, '' for none.
     *
     * @param list<array<string, mixed>> $reactions
     * @return list<string>
     */
    private static function reactionTypes(array $reactions): array
    {
        return array_map(static function (array $reaction): string {
            switch ($reaction['_'] ?? '') {
                case 'reactionEmoji':
                    $emoji = (string) $reaction['emoticon'];

                    return str_starts_with($emoji, '#') || $emoji === '$' ? '' : $emoji;
                case 'reactionCustomEmoji':
                    return '#'.(int) $reaction['document_id'];
                case 'reactionPaid':
                    return '$';
            }

            return '';
        }, $reactions);
    }

    /**
     * ReactionType::get_reaction_type_object + Client::JsonReactionType.
     *
     * @return array<string, string>|null
     */
    private static function reactionTypeObject(string $type): ?array
    {
        return match (true) {
            $type === '' => null,
            $type === '$' => ['type' => 'paid'],
            $type[0] === '#' => ['type' => 'custom_emoji', 'custom_emoji_id' => substr($type, 1)],
            default => ['type' => 'emoji', 'emoji' => $type],
        };
    }

    // ═════════════════════════════════════════════════════════════════
    //  Subscriptions and guest queries
    // ═════════════════════════════════════════════════════════════════

    /**
     * UpdatesManager (updateBotStarsSubscription) + Client::add_update_subscription.
     *
     * @param array<string, mixed> $update
     */
    private function onUpdateStarsSubscription(array $update): void
    {
        $userId = (int) $update['user_id'];
        $payload = (string) $update['payload'];
        $canceled = !empty($update['canceled']);
        $restored = !empty($update['restored']);
        $failed = !empty($update['payment_failed']);
        if ($userId <= 0 || !mb_check_encoding($payload, 'UTF-8') || (int) $canceled + (int) $restored + (int) $failed !== 1) {
            return;
        }
        $this->addUpdate(UpdateType::SUBSCRIPTION, JsonWriter::encode([
            'user' => JsonUser::make($userId, $this),
            'invoice_payload' => $payload,
            'state' => $canceled ? 'canceled' : ($restored ? 'active' : 'failed'),
        ]), 86400, $userId + (14 << 33));
    }

    /**
     * MessagesManager::get_guest_message_object + Client::add_new_guest_query.
     *
     * @param array<string, mixed> $update TL updateBotGuestChatQuery
     */
    private function onUpdateGuestChatQuery(array $update): void
    {
        $converter = new MessageConverter($this);
        $references = [];
        foreach ($update['reference_messages'] ?? [] as $reference) {
            $info = $converter->convert($reference);
            if ($info !== null) {
                $references[] = $info;
            }
        }
        $message = $converter->convert($update['message'] ?? []);
        $queryId = (int) ($update['query_id'] ?? 0);
        if ($message === null || $queryId === 0) {
            return;
        }
        $message->referenceMessages = $references;
        $message->guestQueryId = $queryId;

        $chatId = $message->chatId;
        $this->newGuestQueryQueues[$chatId] ??= ['queue' => [], 'active' => false];
        $this->newGuestQueryQueues[$chatId]['queue'][] = $message;
        $this->processNewGuestQueryQueue($chatId);
    }

    /**
     * Client::process_new_guest_query_queue.
     */
    private function processNewGuestQueryQueue(int $chatId): void
    {
        if (!isset($this->newGuestQueryQueues[$chatId]) || $this->newGuestQueryQueues[$chatId]['active']) {
            return;
        }
        $this->newGuestQueryQueues[$chatId]['active'] = true;

        while ($this->newGuestQueryQueues[$chatId]['queue'] !== []) {
            if ($this->loggingOut || $this->closing) {
                break;
            }
            $message = $this->newGuestQueryQueues[$chatId]['queue'][0];
            $this->loadStickerSetNames($this->messageStickerSets($message));
            array_shift($this->newGuestQueryQueues[$chatId]['queue']);

            if ($this->needSkipUpdateMessage(0, $message, false)) {
                continue;
            }
            $this->addMessageUpdate(UpdateType::GUEST_MESSAGE, $message, $message->chatId + (12 << 33));
        }

        unset($this->newGuestQueryQueues[$chatId]);
    }
}
