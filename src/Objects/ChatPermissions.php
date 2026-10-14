<?php

declare(strict_types=1);

namespace LaraGram\Server\Objects;

/**
 * Chat permissions as TDLib derives them from TL `chatBannedRights`
 * (td::RestrictedRights → td_api::chatPermissions), keyed by the td_api names.
 */
final class ChatPermissions
{
    /**
     * @param array<string, mixed>|null $bannedRights TL chatBannedRights
     * @return array<string, bool>
     */
    public static function fromBannedRights(?array $bannedRights, bool $isBroadcast = false): array
    {
        if ($bannedRights === null || $isBroadcast) {
            return self::none();
        }

        $can = static fn (string $flag): bool => empty($bannedRights[$flag]);

        return [
            'can_send_basic_messages' => $can('send_plain'),
            'can_send_audios' => $can('send_audios'),
            'can_send_documents' => $can('send_docs'),
            'can_send_photos' => $can('send_photos'),
            'can_send_videos' => $can('send_videos'),
            'can_send_video_notes' => $can('send_roundvideos'),
            'can_send_voice_notes' => $can('send_voices'),
            'can_send_polls' => $can('send_polls'),
            'can_send_other_messages' => $can('send_stickers') || $can('send_gifs') || $can('send_games') || $can('send_inline'),
            'can_add_link_previews' => $can('embed_links'),
            'can_react_to_messages' => $can('send_reactions'),
            'can_edit_tag' => $can('edit_rank'),
            'can_change_info' => $can('change_info'),
            'can_invite_users' => $can('invite_users'),
            'can_pin_messages' => $can('pin_messages'),
            'can_create_topics' => $can('manage_topics'),
        ];
    }

    /**
     * @return array<string, bool>
     */
    public static function none(): array
    {
        return array_fill_keys([
            'can_send_basic_messages', 'can_send_audios', 'can_send_documents', 'can_send_photos', 'can_send_videos',
            'can_send_video_notes', 'can_send_voice_notes', 'can_send_polls', 'can_send_other_messages',
            'can_add_link_previews', 'can_react_to_messages', 'can_edit_tag', 'can_change_info', 'can_invite_users',
            'can_pin_messages', 'can_create_topics',
        ], false);
    }

    /**
     * JsonChat's `all_members_are_administrators` for basic groups.
     *
     * @param array<string, bool> $permissions
     */
    public static function everyoneIsAdministrator(array $permissions): bool
    {
        foreach (['can_send_basic_messages', 'can_send_audios', 'can_send_documents', 'can_send_photos', 'can_send_videos',
            'can_send_video_notes', 'can_send_voice_notes', 'can_send_polls', 'can_send_other_messages',
            'can_add_link_previews', 'can_react_to_messages', 'can_edit_tag', 'can_change_info',
            'can_invite_users', 'can_pin_messages'] as $key) {
            if (!$permissions[$key]) {
                return false;
            }
        }

        return true;
    }
}
