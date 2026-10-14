<?php

declare(strict_types=1);

namespace LaraGram\Server\Objects;

use LaraGram\Server\Bot\Client;

/**
 * The Bot API `Chat` object (Client::JsonChat, the short form embedded in
 * messages and updates), built from the TL user/chat/channel objects.
 */
final class JsonChat
{
    /**
     * @return array<string, mixed>
     */
    public static function make(int $chatId, Client $client): array
    {
        $object = ['id' => $chatId];

        switch (ChatId::type($chatId)) {
            case ChatId::TYPE_PRIVATE:
                $user = $client->getUser($chatId) ?? [];
                $object['first_name'] = (string) ($user['first_name'] ?? '');
                if (($user['last_name'] ?? '') !== '') {
                    $object['last_name'] = $user['last_name'];
                }
                if (($username = JsonUser::activeUsernames($user)[0] ?? null) !== null) {
                    $object['username'] = $username;
                }
                $object['type'] = 'private';
                break;

            case ChatId::TYPE_GROUP:
                $chat = $client->getBasicGroup(ChatId::toBasicGroupId($chatId)) ?? [];
                $object['title'] = (string) ($chat['title'] ?? '');
                $object['type'] = 'group';
                $permissions = ChatPermissions::fromBannedRights($chat['default_banned_rights'] ?? null);
                $object['all_members_are_administrators'] = ChatPermissions::everyoneIsAdministrator($permissions);
                break;

            case ChatId::TYPE_SUPERGROUP:
                $channel = $client->getChannel(ChatId::toChannelId($chatId)) ?? [];
                $isSupergroup = !empty($channel['megagroup']);
                $object['title'] = (string) ($channel['title'] ?? '');
                if (($username = JsonUser::activeUsernames($channel)[0] ?? null) !== null) {
                    $object['username'] = $username;
                }
                if ($isSupergroup) {
                    if (!empty($channel['forum'])) {
                        $object['is_forum'] = true;
                    }
                    if (!empty($channel['monoforum'])) {
                        $object['is_direct_messages'] = true;
                    }
                }
                $object['type'] = $isSupergroup ? 'supergroup' : 'channel';
                break;
        }

        return $object;
    }
}
