<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Objects\JsonUser;

/**
 * Chat invite links with the DialogInviteLinkManager parts they rely on.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait InviteLinkMethods
{
    /** DialogInviteLinkManager::MAX_INVITE_LINK_TITLE_LENGTH (server-side limit) */
    private const MAX_INVITE_LINK_TITLE_LENGTH = 32;

    public function processExportChatInviteLinkQuery(Query $query): void
    {
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);
        $link = $this->exportDialogInviteLink($chatId, '', 0, 0, false, null, true);
        $query->answerQuery($link['invite_link']);
    }

    public function processCreateChatInviteLinkQuery(Query $query): void
    {
        $name = $query->arg('name');
        $expireDate = self::getIntegerArg($query, 'expire_date', 0, 0);
        $memberLimit = self::getIntegerArg($query, 'member_limit', 0, 0, 100000);
        $createsJoinRequest = self::boolArg($query, 'creates_join_request');
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);

        $name = self::cleanInputStringOrFail($name);
        $query->answerQuery($this->chatInviteLinkObject($this->exportDialogInviteLink($chatId, $name, $expireDate, $memberLimit, $createsJoinRequest, null, false)));
    }

    public function processEditChatInviteLinkQuery(Query $query): void
    {
        $inviteLink = $query->arg('invite_link');
        $name = $query->arg('name');
        $expireDate = self::getIntegerArg($query, 'expire_date', 0, 0);
        $memberLimit = self::getIntegerArg($query, 'member_limit', 0, 0, 100000);
        $createsJoinRequest = self::boolArg($query, 'creates_join_request');
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);

        $name = self::cleanInputStringOrFail($name);
        $inviteLink = self::cleanInputStringOrFail($inviteLink);
        $query->answerQuery($this->chatInviteLinkObject($this->editDialogInviteLink($chatId, $inviteLink, $name, $expireDate, $memberLimit, $createsJoinRequest, false)));
    }

    public function processCreateChatSubscriptionInviteLinkQuery(Query $query): void
    {
        $name = $query->arg('name');
        $period = self::getIntegerArg($query, 'subscription_period', 0, 0, 1000000000);
        $price = self::getIntegerArg($query, 'subscription_price', 0, 0, 1000000);
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);

        $name = self::cleanInputStringOrFail($name);
        $query->answerQuery($this->chatInviteLinkObject($this->exportDialogInviteLink($chatId, $name, 0, 0, false, ['period' => $period, 'amount' => $price], false)));
    }

    public function processEditChatSubscriptionInviteLinkQuery(Query $query): void
    {
        $inviteLink = $query->arg('invite_link');
        $name = $query->arg('name');
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);

        $name = self::cleanInputStringOrFail($name);
        $inviteLink = self::cleanInputStringOrFail($inviteLink);
        $query->answerQuery($this->chatInviteLinkObject($this->editDialogInviteLink($chatId, $inviteLink, $name, 0, 0, false, true)));
    }

    public function processRevokeChatInviteLinkQuery(Query $query): void
    {
        $inviteLink = $query->arg('invite_link');
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);

        $inviteLink = self::cleanInputStringOrFail($inviteLink);
        // DialogInviteLinkManager::revoke_dialog_invite_link
        $this->canManageDialogInviteLinks($chatId);
        if ($inviteLink === '') {
            throw new QueryError(400, 'Invite link must be non-empty');
        }
        $result = $this->invokeOrFail('messages.editExportedChatInvite', [
            'revoked' => true,
            'peer' => $this->inputPeer($chatId),
            'link' => $inviteLink,
        ]);
        $link = self::dialogInviteLink($result['invite'] ?? []);
        if ($link === null || (($result['_'] ?? '') === 'messages.exportedChatInviteReplaced' && self::dialogInviteLink($result['new_invite'] ?? []) === null)) {
            throw new QueryError(500, 'Receive invalid invite link');
        }
        $query->answerQuery($this->chatInviteLinkObject($link));
    }

    /**
     * DialogInviteLinkManager::can_manage_dialog_invite_links.
     */
    private function canManageDialogInviteLinks(int $chatId): void
    {
        $this->checkChatAccess($chatId, self::ACCESS_WRITE);
        switch (ChatId::type($chatId)) {
            case ChatId::TYPE_PRIVATE:
                throw new QueryError(400, 'Can\'t invite members to a private chat');
            case ChatId::TYPE_GROUP:
                $chat = $this->getBasicGroup(ChatId::toBasicGroupId($chatId)) ?? [];
                if (!self::isBasicGroupActive($chat)) {
                    throw new QueryError(400, 'Chat is deactivated');
                }
                break;
        }
        if (!$this->getChatPermissions($chatId)->canManageInviteLinks()) {
            throw new QueryError(400, 'Not enough rights to manage chat invite link');
        }
    }

    /**
     * DialogInviteLinkManager::export_dialog_invite_link (ExportChatInviteQuery).
     *
     * @param array{period: int, amount: int}|null $pricing
     * @return array<string, mixed>
     */
    private function exportDialogInviteLink(int $chatId, string $title, int $expireDate, int $usageLimit, bool $createsJoinRequest, ?array $pricing, bool $isPermanent): array
    {
        if ($pricing !== null && ($pricing['period'] <= 0 || $pricing['amount'] <= 0 || $pricing['amount'] > 1000000000)) {
            throw new QueryError(400, 'Invalid subscription pricing specified');
        }
        if ($createsJoinRequest && $usageLimit > 0) {
            throw new QueryError(400, 'Member limit can\'t be specified for links requiring administrator approval');
        }
        $title = self::cleanName($title, self::MAX_INVITE_LINK_TITLE_LENGTH);
        $this->canManageDialogInviteLinks($chatId);

        $result = $this->invokeOrFail('messages.exportChatInvite', self::untl(self::tl('messages.exportChatInvite', [
            'legacy_revoke_permanent' => $isPermanent,
            'request_needed' => $createsJoinRequest,
            'peer' => $this->inputPeer($chatId),
            'expire_date' => $expireDate > 0 ? $expireDate : null,
            'usage_limit' => $usageLimit > 0 ? $usageLimit : null,
            'title' => $title !== '' ? $title : null,
            'subscription_pricing' => $pricing !== null ? ['_' => 'starsSubscriptionPricing', 'period' => $pricing['period'], 'amount' => $pricing['amount']] : null,
        ])));

        $link = self::dialogInviteLink(is_array($result) ? $result : []);
        if ($link === null) {
            throw new QueryError(500, 'Receive invalid invite link');
        }
        if ($link['creator_user_id'] !== $this->getMyId()) {
            throw new QueryError(500, 'Receive invalid invite link creator');
        }
        if ($link['is_primary']) {
            $this->forgetChatFull($chatId);
        }

        return $link;
    }

    /**
     * DialogInviteLinkManager::edit_dialog_invite_link (EditChatInviteLinkQuery).
     *
     * @return array<string, mixed>
     */
    private function editDialogInviteLink(int $chatId, string $inviteLink, string $title, int $expireDate, int $usageLimit, bool $createsJoinRequest, bool $isSubscription): array
    {
        $this->canManageDialogInviteLinks($chatId);
        if ($createsJoinRequest && $usageLimit > 0) {
            throw new QueryError(400, 'Member limit can\'t be specified for links requiring administrator approval');
        }
        if ($inviteLink === '') {
            throw new QueryError(400, 'Invite link must be non-empty');
        }
        $title = self::cleanName($title, self::MAX_INVITE_LINK_TITLE_LENGTH);

        $params = ['peer' => $this->inputPeer($chatId), 'link' => $inviteLink, 'title' => $title];
        if (!$isSubscription) {
            $params['expire_date'] = $expireDate;
            $params['usage_limit'] = $usageLimit;
            $params['request_needed'] = $createsJoinRequest;
        }
        $result = $this->invokeOrFail('messages.editExportedChatInvite', $params);
        if (($result['_'] ?? '') !== 'messages.exportedChatInvite') {
            throw new QueryError(500, 'Receive unexpected response from server');
        }
        $link = self::dialogInviteLink($result['invite'] ?? []);
        if ($link === null) {
            throw new QueryError(500, 'Receive invalid invite link');
        }

        return $link;
    }

    /**
     * DialogInviteLink(telegram_api::ExportedChatInvite): null when invalid.
     *
     * @param array<string, mixed> $invite
     * @return array<string, mixed>|null
     */
    private static function dialogInviteLink(array $invite): ?array
    {
        if (($invite['_'] ?? '') !== 'chatInviteExported') {
            return null;
        }
        $date = (int) ($invite['date'] ?? 0);
        $link = [
            'invite_link' => (string) ($invite['link'] ?? ''),
            'name' => (string) ($invite['title'] ?? ''),
            'creator_user_id' => max(0, (int) ($invite['admin_id'] ?? 0)),
            'date' => $date !== 0 && $date < 1000000000 ? 0 : $date,
            'expire_date' => (int) ($invite['expire_date'] ?? 0),
            'member_limit' => max(0, (int) ($invite['usage_limit'] ?? 0)),
            'pending_join_request_count' => max(0, (int) ($invite['requested'] ?? 0)),
            'edit_date' => (int) ($invite['start_date'] ?? 0),
            'pricing' => null,
            'creates_join_request' => !empty($invite['request_needed']),
            'is_primary' => !empty($invite['permanent']),
            'is_revoked' => !empty($invite['revoked']),
        ];
        if ($link['expire_date'] !== 0 && $link['expire_date'] < 1000000000) {
            $link['expire_date'] = 0;
        }
        if ($link['edit_date'] !== 0 && $link['edit_date'] < 1000000000) {
            $link['edit_date'] = 0;
        }
        $pricing = $invite['subscription_pricing'] ?? null;
        if ($pricing !== null) {
            $period = (int) ($pricing['period'] ?? 0);
            $amount = (int) ($pricing['amount'] ?? 0);
            if ($amount > 1000000000) {
                $amount = 0;
            }
            if ($period > 0 && $amount > 0) {
                $link['pricing'] = ['period' => $period, 'star_count' => $amount];
            }
        }
        if ($link['is_primary'] && ($link['name'] !== '' || $link['expire_date'] > 0 || $link['member_limit'] > 0
            || $link['edit_date'] > 0 || $link['pending_join_request_count'] > 0)) {
            $link['name'] = '';
            $link['expire_date'] = $link['member_limit'] = $link['edit_date'] = $link['pending_join_request_count'] = 0;
        }
        if ($link['creates_join_request'] && $link['member_limit'] > 0) {
            $link['member_limit'] = 0;
        }

        if ($link['invite_link'] === '' || $link['creator_user_id'] <= 0 || $link['date'] <= 0) {
            return null;
        }

        return $link;
    }

    /**
     * Client::JsonChatInviteLink.
     *
     * @param array<string, mixed> $link
     * @return array<string, mixed>
     */
    public function chatInviteLinkObject(array $link): array
    {
        $object = ['invite_link' => $link['invite_link']];
        if ($link['name'] !== '') {
            $object['name'] = $link['name'];
        }
        $object['creator'] = JsonUser::make($link['creator_user_id'], $this);
        if ($link['expire_date'] !== 0) {
            $object['expire_date'] = $link['expire_date'];
        }
        if ($link['member_limit'] !== 0) {
            $object['member_limit'] = $link['member_limit'];
        }
        if ($link['pending_join_request_count'] !== 0) {
            $object['pending_join_request_count'] = $link['pending_join_request_count'];
        }
        if ($link['pricing'] !== null) {
            $object['subscription_period'] = $link['pricing']['period'];
            $object['subscription_price'] = $link['pricing']['star_count'];
        }
        $object['creates_join_request'] = $link['creates_join_request'];
        $object['is_primary'] = $link['is_primary'];
        $object['is_revoked'] = $link['is_revoked'];

        return $object;
    }
}
