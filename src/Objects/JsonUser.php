<?php

declare(strict_types=1);

namespace LaraGram\Server\Objects;

use LaraGram\Server\Bot\Client;

/**
 * The Bot API `User` object (Client::JsonUser), built from the TL `user`.
 */
final class JsonUser
{
    /**
     * @return array<string, mixed>
     */
    public static function make(int $userId, Client $client, bool $fullBotInfo = false): array
    {
        $user = $client->getUser($userId);
        $isBot = $user !== null && !empty($user['bot']);

        $object = [
            'id' => $userId,
            'is_bot' => $isBot,
            'first_name' => $user === null ? '' : (string) ($user['first_name'] ?? ''),
        ];

        if ($user === null) {
            return $object;
        }

        if (($user['last_name'] ?? '') !== '') {
            $object['last_name'] = $user['last_name'];
        }
        if (($username = self::activeUsernames($user)[0] ?? null) !== null) {
            $object['username'] = $username;
        }
        if (($user['lang_code'] ?? '') !== '') {
            $object['language_code'] = $user['lang_code'];
        }
        if (!empty($user['premium'])) {
            $object['is_premium'] = true;
        }
        if (!empty($user['attach_menu_enabled'])) {
            $object['added_to_attachment_menu'] = true;
        }

        if ($isBot && $fullBotInfo) {
            $forumView = !empty($user['bot_forum_view']);
            $object['can_join_groups'] = empty($user['bot_nochats']);
            $object['can_read_all_group_messages'] = !empty($user['bot_chat_history']);
            $object['supports_inline_queries'] = isset($user['bot_inline_placeholder']);
            $object['supports_guest_queries'] = !empty($user['bot_guestchat']);
            $object['can_connect_to_business'] = !empty($user['bot_business']);
            $object['has_main_web_app'] = !empty($user['bot_has_main_app']);
            $object['has_topics_enabled'] = $forumView;
            $object['allows_users_to_create_topics'] = $forumView && !empty($user['bot_forum_can_manage_topics']);
            $object['can_manage_bots'] = !empty($user['bot_can_manage_bots']);
            $object['supports_join_request_queries'] = !empty($user['bot_guard']);
        }

        return $object;
    }

    /**
     * Active usernames in display order: the `usernames` list when present,
     * else the single `username`.
     *
     * @param array<string, mixed> $user
     * @return list<string>
     */
    public static function activeUsernames(array $user): array
    {
        if (!empty($user['usernames'])) {
            $active = [];
            foreach ($user['usernames'] as $username) {
                if (!empty($username['active'])) {
                    $active[] = (string) $username['username'];
                }
            }

            return $active;
        }

        return ($user['username'] ?? '') !== '' ? [(string) $user['username']] : [];
    }
}
