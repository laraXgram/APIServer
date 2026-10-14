<?php

declare(strict_types=1);

namespace LaraGram\Server\Objects;

/**
 * The Bot API `ChatPermissions` object (Client::json_store_permissions) of
 * td_api-named permissions (Objects\ChatPermissions, Td\ParticipantStatus).
 */
final class JsonChatPermissions
{
    /**
     * @param array<string, bool> $permissions
     * @return array<string, bool>
     */
    public static function make(array $permissions): array
    {
        return [
            'can_send_messages' => $permissions['can_send_basic_messages'],
            'can_send_media_messages' => $permissions['can_send_audios'] || $permissions['can_send_documents']
                || $permissions['can_send_photos'] || $permissions['can_send_videos']
                || $permissions['can_send_video_notes'] || $permissions['can_send_voice_notes'],
            'can_send_audios' => $permissions['can_send_audios'],
            'can_send_documents' => $permissions['can_send_documents'],
            'can_send_photos' => $permissions['can_send_photos'],
            'can_send_videos' => $permissions['can_send_videos'],
            'can_send_video_notes' => $permissions['can_send_video_notes'],
            'can_send_voice_notes' => $permissions['can_send_voice_notes'],
            'can_send_polls' => $permissions['can_send_polls'],
            'can_send_other_messages' => $permissions['can_send_other_messages'],
            'can_add_web_page_previews' => $permissions['can_add_link_previews'],
            'can_react_to_messages' => $permissions['can_react_to_messages'],
            'can_edit_tag' => $permissions['can_edit_tag'],
            'can_change_info' => $permissions['can_change_info'],
            'can_invite_users' => $permissions['can_invite_users'],
            'can_pin_messages' => $permissions['can_pin_messages'],
            'can_manage_topics' => $permissions['can_create_topics'],
        ];
    }
}
