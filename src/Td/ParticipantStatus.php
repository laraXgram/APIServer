<?php

declare(strict_types=1);

namespace LaraGram\Server\Td;

/**
 * td::DialogParticipantStatus with td::AdministratorRights and
 * td::RestrictedRights (DialogParticipant.cpp): a chat member's status as a
 * type, a flag set and an "until" date.
 */
final class ParticipantStatus
{
    public const CREATOR = 0;
    public const ADMINISTRATOR = 1;
    public const MEMBER = 2;
    public const RESTRICTED = 3;
    public const LEFT = 4;
    public const BANNED = 5;

    // AdministratorRights
    public const CAN_CHANGE_INFO_AND_SETTINGS_ADMIN = 1 << 0;
    public const CAN_POST_MESSAGES = 1 << 1;
    public const CAN_EDIT_MESSAGES = 1 << 2;
    public const CAN_DELETE_MESSAGES = 1 << 3;
    public const CAN_INVITE_USERS_ADMIN = 1 << 4;
    public const CAN_RESTRICT_MEMBERS = 1 << 6;
    public const CAN_PIN_MESSAGES_ADMIN = 1 << 7;
    public const CAN_PROMOTE_MEMBERS = 1 << 8;
    public const CAN_MANAGE_CALLS = 1 << 9;
    public const CAN_MANAGE_DIALOG = 1 << 10;
    public const CAN_MANAGE_TOPICS_ADMIN = 1 << 11;
    public const IS_ANONYMOUS = 1 << 13;
    public const CAN_POST_STORIES = 1 << 48;
    public const CAN_EDIT_STORIES = 1 << 49;
    public const CAN_DELETE_STORIES = 1 << 50;
    public const CAN_MANAGE_DIRECT_MESSAGES = 1 << 51;
    public const CAN_MANAGE_RANKS = 1 << 52;
    public const CAN_MANAGE_LINKED_PEERS_ADMIN = 1 << 53;
    public const CAN_MANAGE_WELCOME_MESSAGES = 1 << 54;

    public const ALL_ADMINISTRATOR_RIGHTS = self::CAN_CHANGE_INFO_AND_SETTINGS_ADMIN | self::CAN_POST_MESSAGES
        | self::CAN_EDIT_MESSAGES | self::CAN_DELETE_MESSAGES | self::CAN_INVITE_USERS_ADMIN | self::CAN_RESTRICT_MEMBERS
        | self::CAN_PIN_MESSAGES_ADMIN | self::CAN_MANAGE_TOPICS_ADMIN | self::CAN_PROMOTE_MEMBERS | self::CAN_MANAGE_CALLS
        | self::CAN_MANAGE_DIALOG | self::CAN_POST_STORIES | self::CAN_EDIT_STORIES | self::CAN_DELETE_STORIES
        | self::CAN_MANAGE_DIRECT_MESSAGES | self::CAN_MANAGE_RANKS | self::CAN_MANAGE_LINKED_PEERS_ADMIN
        | self::CAN_MANAGE_WELCOME_MESSAGES;

    // RestrictedRights
    public const CAN_SEND_MESSAGES = 1 << 16;
    public const CAN_SEND_STICKERS = 1 << 18;
    public const CAN_SEND_ANIMATIONS = 1 << 19;
    public const CAN_SEND_GAMES = 1 << 20;
    public const CAN_USE_INLINE_BOTS = 1 << 21;
    public const CAN_ADD_WEB_PAGE_PREVIEWS = 1 << 22;
    public const CAN_SEND_POLLS = 1 << 23;
    public const CAN_CHANGE_INFO_AND_SETTINGS = 1 << 24;
    public const CAN_INVITE_USERS = 1 << 25;
    public const CAN_PIN_MESSAGES = 1 << 26;
    public const CAN_MANAGE_TOPICS = 1 << 12;
    public const CAN_SEND_AUDIOS = 1 << 32;
    public const CAN_SEND_DOCUMENTS = 1 << 33;
    public const CAN_SEND_PHOTOS = 1 << 34;
    public const CAN_SEND_VIDEOS = 1 << 35;
    public const CAN_SEND_VIDEO_NOTES = 1 << 36;
    public const CAN_SEND_VOICE_NOTES = 1 << 37;
    public const CAN_EDIT_RANK = 1 << 38;
    public const CAN_SEND_REACTIONS = 1 << 39;
    public const CAN_MANAGE_LINKED_PEERS = 1 << 40;

    public const ALL_ADMIN_PERMISSION_RIGHTS = self::CAN_CHANGE_INFO_AND_SETTINGS | self::CAN_INVITE_USERS
        | self::CAN_PIN_MESSAGES | self::CAN_MANAGE_TOPICS | self::CAN_MANAGE_LINKED_PEERS;

    public const ALL_RESTRICTED_RIGHTS = self::CAN_SEND_MESSAGES | self::CAN_SEND_STICKERS | self::CAN_SEND_ANIMATIONS
        | self::CAN_SEND_GAMES | self::CAN_USE_INLINE_BOTS | self::CAN_ADD_WEB_PAGE_PREVIEWS | self::CAN_SEND_POLLS
        | self::ALL_ADMIN_PERMISSION_RIGHTS | self::CAN_SEND_AUDIOS | self::CAN_SEND_DOCUMENTS | self::CAN_SEND_PHOTOS
        | self::CAN_SEND_VIDEOS | self::CAN_SEND_VIDEO_NOTES | self::CAN_SEND_VOICE_NOTES | self::CAN_EDIT_RANK
        | self::CAN_SEND_REACTIONS;

    private const CAN_BE_EDITED = 1 << 15;
    private const IS_MEMBER = 1 << 27;

    /** td_api::chatPermissions field → RestrictedRights flags (the "other messages" field covers four). */
    private const PERMISSION_FLAGS = [
        'can_send_basic_messages' => self::CAN_SEND_MESSAGES,
        'can_send_audios' => self::CAN_SEND_AUDIOS,
        'can_send_documents' => self::CAN_SEND_DOCUMENTS,
        'can_send_photos' => self::CAN_SEND_PHOTOS,
        'can_send_videos' => self::CAN_SEND_VIDEOS,
        'can_send_video_notes' => self::CAN_SEND_VIDEO_NOTES,
        'can_send_voice_notes' => self::CAN_SEND_VOICE_NOTES,
        'can_send_polls' => self::CAN_SEND_POLLS,
        'can_send_other_messages' => self::CAN_SEND_STICKERS | self::CAN_SEND_ANIMATIONS | self::CAN_SEND_GAMES | self::CAN_USE_INLINE_BOTS,
        'can_add_link_previews' => self::CAN_ADD_WEB_PAGE_PREVIEWS,
        'can_react_to_messages' => self::CAN_SEND_REACTIONS,
        'can_edit_tag' => self::CAN_EDIT_RANK,
        'can_change_info' => self::CAN_CHANGE_INFO_AND_SETTINGS,
        'can_invite_users' => self::CAN_INVITE_USERS,
        'can_pin_messages' => self::CAN_PIN_MESSAGES,
        'can_create_topics' => self::CAN_MANAGE_TOPICS,
    ];

    public function __construct(
        public int $type,
        public int $flags,
        public int $untilDate = 0,
        public string $rank = '',
    ) {
    }

    // ═════════════════════════════════════════════════════════════════
    //  Constructors
    // ═════════════════════════════════════════════════════════════════

    public static function creator(bool $isMember, bool $isAnonymous, string $rank = ''): self
    {
        return new self(self::CREATOR, self::ALL_ADMINISTRATOR_RIGHTS | self::ALL_RESTRICTED_RIGHTS
            | ($isMember ? self::IS_MEMBER : 0) | ($isAnonymous ? self::IS_ANONYMOUS : 0), 0, $rank);
    }

    public static function administrator(int $administratorRights, string $rank, bool $canBeEdited): self
    {
        if ($administratorRights === 0) {
            return self::member(0, $rank);
        }

        return new self(self::ADMINISTRATOR, self::IS_MEMBER
            | (self::ALL_RESTRICTED_RIGHTS & ~self::ALL_ADMIN_PERMISSION_RIGHTS)
            | $administratorRights | ($canBeEdited ? self::CAN_BE_EDITED : 0), 0, $rank);
    }

    public static function member(int $untilDate = 0, string $rank = ''): self
    {
        return new self(self::MEMBER, self::IS_MEMBER | self::ALL_RESTRICTED_RIGHTS, $untilDate, $rank);
    }

    public static function restricted(int $restrictedRights, bool $isMember, int $untilDate, string $channelType, string $rank = ''): self
    {
        if ($restrictedRights === self::ALL_RESTRICTED_RIGHTS || $channelType === 'broadcast') {
            return $isMember ? self::member(0, $rank) : self::left();
        }

        return new self(self::RESTRICTED, $restrictedRights | ($isMember ? self::IS_MEMBER : 0), self::fixUntilDate($untilDate), $rank);
    }

    public static function left(): self
    {
        return new self(self::LEFT, self::ALL_RESTRICTED_RIGHTS);
    }

    public static function banned(int $untilDate = 0, string $rank = ''): self
    {
        return new self(self::BANNED, 0, self::fixUntilDate($untilDate), $rank);
    }

    /**
     * DialogParticipantStatus::GroupAdministrator (legacy basic group admins).
     */
    public static function groupAdministrator(bool $isCurrentUserCreator, string $rank = ''): self
    {
        return self::administrator(self::administratorRights([
            'can_manage_chat' => true, 'can_change_info' => true, 'can_delete_messages' => true,
            'can_invite_users' => true, 'can_restrict_members' => true, 'can_pin_messages' => true,
            'can_manage_video_chats' => true, 'can_manage_tags' => true, 'can_send_welcome_messages' => true,
        ], 'unknown'), $rank, $isCurrentUserCreator);
    }

    private static function fixUntilDate(int $date): int
    {
        return $date === 2147483647 || $date < 0 ? 0 : $date;
    }

    /**
     * AdministratorRights from Bot API ChatAdministratorRights fields.
     *
     * @param array<string, bool> $r
     */
    public static function administratorRights(array $r, string $channelType): int
    {
        $r += [
            'is_anonymous' => false, 'can_manage_chat' => false, 'can_change_info' => false, 'can_post_messages' => false,
            'can_edit_messages' => false, 'can_delete_messages' => false, 'can_invite_users' => false,
            'can_restrict_members' => false, 'can_pin_messages' => false, 'can_manage_topics' => false,
            'can_promote_members' => false, 'can_manage_video_chats' => false, 'can_post_stories' => false,
            'can_edit_stories' => false, 'can_delete_stories' => false, 'can_manage_direct_messages' => false,
            'can_manage_tags' => false, 'can_manage_linked_peers' => false, 'can_send_welcome_messages' => false,
        ];
        switch ($channelType) {
            case 'broadcast':
                $r['can_pin_messages'] = $r['can_manage_topics'] = $r['can_manage_tags'] = $r['is_anonymous'] = false;
                break;
            case 'megagroup':
                $r['can_post_messages'] = $r['can_edit_messages'] = $r['can_manage_direct_messages'] = false;
                break;
            default:
                $r['can_manage_topics'] = false;
        }

        $flags = ($r['can_manage_chat'] ? self::CAN_MANAGE_DIALOG : 0)
            | ($r['can_change_info'] ? self::CAN_CHANGE_INFO_AND_SETTINGS_ADMIN : 0)
            | ($r['can_post_messages'] ? self::CAN_POST_MESSAGES : 0)
            | ($r['can_edit_messages'] ? self::CAN_EDIT_MESSAGES : 0)
            | ($r['can_delete_messages'] ? self::CAN_DELETE_MESSAGES : 0)
            | ($r['can_invite_users'] ? self::CAN_INVITE_USERS_ADMIN : 0)
            | ($r['can_restrict_members'] ? self::CAN_RESTRICT_MEMBERS : 0)
            | ($r['can_pin_messages'] ? self::CAN_PIN_MESSAGES_ADMIN : 0)
            | ($r['can_manage_topics'] ? self::CAN_MANAGE_TOPICS_ADMIN : 0)
            | ($r['can_promote_members'] ? self::CAN_PROMOTE_MEMBERS : 0)
            | ($r['can_manage_video_chats'] ? self::CAN_MANAGE_CALLS : 0)
            | ($r['can_post_stories'] ? self::CAN_POST_STORIES : 0)
            | ($r['can_edit_stories'] ? self::CAN_EDIT_STORIES : 0)
            | ($r['can_delete_stories'] ? self::CAN_DELETE_STORIES : 0)
            | ($r['can_manage_direct_messages'] ? self::CAN_MANAGE_DIRECT_MESSAGES : 0)
            | ($r['can_manage_tags'] ? self::CAN_MANAGE_RANKS : 0)
            | ($r['can_manage_linked_peers'] ? self::CAN_MANAGE_LINKED_PEERS_ADMIN : 0)
            | ($r['can_send_welcome_messages'] ? self::CAN_MANAGE_WELCOME_MESSAGES : 0)
            | ($r['is_anonymous'] ? self::IS_ANONYMOUS : 0);
        if ($flags !== 0) {
            $flags |= self::CAN_MANAGE_DIALOG;
        }

        return $flags;
    }

    /**
     * AdministratorRights(telegram_api::chatAdminRights, channel_type).
     *
     * @param array<string, mixed>|null $rights
     */
    public static function administratorRightsFromServer(?array $rights, string $channelType): int
    {
        if ($rights === null) {
            return 0;
        }
        $other = !empty($rights['other']) || $channelType === 'unknown';

        return self::administratorRights([
            'is_anonymous' => !empty($rights['anonymous']),
            'can_manage_chat' => $other,
            'can_change_info' => !empty($rights['change_info']),
            'can_post_messages' => !empty($rights['post_messages']),
            'can_edit_messages' => !empty($rights['edit_messages']),
            'can_delete_messages' => !empty($rights['delete_messages']),
            'can_invite_users' => !empty($rights['invite_users']),
            'can_restrict_members' => !empty($rights['ban_users']),
            'can_pin_messages' => !empty($rights['pin_messages']),
            'can_manage_topics' => !empty($rights['manage_topics']),
            'can_promote_members' => !empty($rights['add_admins']),
            'can_manage_video_chats' => !empty($rights['manage_call']),
            'can_post_stories' => !empty($rights['post_stories']),
            'can_edit_stories' => !empty($rights['edit_stories']),
            'can_delete_stories' => !empty($rights['delete_stories']),
            'can_manage_direct_messages' => !empty($rights['manage_direct_messages']),
            'can_manage_tags' => !empty($rights['manage_ranks']),
            'can_manage_linked_peers' => !empty($rights['manage_linked_peers']),
            'can_send_welcome_messages' => !empty($rights['manage_welcome_messages']),
        ], $channelType);
    }

    /**
     * RestrictedRights(telegram_api::chatBannedRights, channel_type): every
     * right not banned.
     *
     * @param array<string, mixed>|null $rights
     */
    public static function restrictedRightsFromServer(?array $rights, string $channelType): int
    {
        if ($rights === null || $channelType === 'broadcast') {
            return 0;
        }

        $flags = 0;
        foreach (self::BANNED_RIGHTS as $field => $flag) {
            if (empty($rights[$field])) {
                $flags |= $flag;
            }
        }

        return $flags;
    }

    /** chatBannedRights field → the RestrictedRights flag it removes. */
    private const BANNED_RIGHTS = [
        'send_plain' => self::CAN_SEND_MESSAGES,
        'send_audios' => self::CAN_SEND_AUDIOS,
        'send_docs' => self::CAN_SEND_DOCUMENTS,
        'send_photos' => self::CAN_SEND_PHOTOS,
        'send_videos' => self::CAN_SEND_VIDEOS,
        'send_roundvideos' => self::CAN_SEND_VIDEO_NOTES,
        'send_voices' => self::CAN_SEND_VOICE_NOTES,
        'send_stickers' => self::CAN_SEND_STICKERS,
        'send_gifs' => self::CAN_SEND_ANIMATIONS,
        'send_games' => self::CAN_SEND_GAMES,
        'send_inline' => self::CAN_USE_INLINE_BOTS,
        'embed_links' => self::CAN_ADD_WEB_PAGE_PREVIEWS,
        'send_polls' => self::CAN_SEND_POLLS,
        'change_info' => self::CAN_CHANGE_INFO_AND_SETTINGS,
        'invite_users' => self::CAN_INVITE_USERS,
        'pin_messages' => self::CAN_PIN_MESSAGES,
        'manage_topics' => self::CAN_MANAGE_TOPICS,
        'edit_rank' => self::CAN_EDIT_RANK,
        'send_reactions' => self::CAN_SEND_REACTIONS,
        'manage_linked_peers' => self::CAN_MANAGE_LINKED_PEERS,
    ];

    /**
     * RestrictedRights(td_api::chatPermissions); can_manage_linked_peers is
     * always kept.
     *
     * @param array<string, bool> $permissions
     */
    public static function restrictedRights(array $permissions, string $channelType): int
    {
        if ($channelType === 'broadcast') {
            return 0;
        }
        $flags = self::CAN_MANAGE_LINKED_PEERS;
        foreach (self::PERMISSION_FLAGS as $field => $flag) {
            if (!empty($permissions[$field])) {
                $flags |= $flag;
            }
        }

        return $flags;
    }

    /**
     * RestrictedRights::get_chat_banned_rights.
     *
     * @return array<string, mixed>
     */
    public static function chatBannedRights(int $restrictedRights, bool $viewMessages = false, int $untilDate = 0): array
    {
        $result = ['_' => 'chatBannedRights'];
        if ($viewMessages) {
            $result['view_messages'] = true;
        }
        foreach (self::BANNED_RIGHTS as $field => $flag) {
            if (($restrictedRights & $flag) === 0) {
                $result[$field] = true;
            }
        }
        // TL field order is fixed by the schema; serialization ignores PHP order.
        $result['until_date'] = $untilDate;

        return $result;
    }

    /**
     * The status of the current user in a TL chat (ChatManager::on_get_chat).
     *
     * @param array<string, mixed> $chat
     */
    public static function fromServerChat(array $chat): self
    {
        if (($chat['_'] ?? '') === 'chatForbidden') {
            return self::banned();
        }
        if (!empty($chat['creator'])) {
            return self::creator(empty($chat['left']), false);
        }
        if (isset($chat['admin_rights'])) {
            return self::administrator(self::administratorRightsFromServer($chat['admin_rights'], 'unknown'), '', false);
        }
        if (!empty($chat['left'])) {
            return self::left();
        }

        return self::member();
    }

    /**
     * The status of the current user in a TL channel (ChatManager::on_get_channel).
     *
     * @param array<string, mixed> $channel
     */
    public static function fromServerChannel(array $channel): self
    {
        if (($channel['_'] ?? '') === 'channelForbidden') {
            return self::banned((int) ($channel['until_date'] ?? 0));
        }
        $channelType = empty($channel['megagroup']) ? 'broadcast' : 'megagroup';
        if (!empty($channel['creator'])) {
            return self::creator(empty($channel['left']), !empty($channel['admin_rights']['anonymous']));
        }
        if (isset($channel['admin_rights'])) {
            return self::administrator(self::administratorRightsFromServer($channel['admin_rights'], $channelType), '', false);
        }
        if (isset($channel['banned_rights'])) {
            $banned = $channel['banned_rights'];
            if (!empty($banned['view_messages'])) {
                return self::banned((int) ($banned['until_date'] ?? 0));
            }
            if ($channelType === 'broadcast') {
                return empty($channel['left']) ? self::member() : self::left();
            }

            return new self(self::RESTRICTED, self::restrictedRightsFromServer($banned, $channelType) | (empty($channel['left']) ? self::IS_MEMBER : 0),
                self::fixUntilDate((int) ($banned['until_date'] ?? 0)));
        }
        if (!empty($channel['left'])) {
            return self::left();
        }

        return self::member((int) ($channel['subscription_until_date'] ?? 0));
    }

    // ═════════════════════════════════════════════════════════════════
    //  Queries
    // ═════════════════════════════════════════════════════════════════

    /**
     * DialogParticipantStatus::update_restrictions + apply_restrictions for a bot.
     */
    public function applyRestrictions(int $defaultRestrictions, int $now): self
    {
        $status = clone $this;
        if ($status->untilDate !== 0 && $now > $status->untilDate) {
            $status->untilDate = 0;
            if ($status->type === self::RESTRICTED) {
                $status->type = $status->isMember() ? self::MEMBER : self::LEFT;
                $status->flags |= self::ALL_RESTRICTED_RIGHTS;
            } elseif ($status->type === self::BANNED || $status->type === self::MEMBER) {
                $status->type = self::LEFT;
            }
        }

        $flags = $status->flags;
        switch ($status->type) {
            case self::MEMBER:
            case self::RESTRICTED:
            case self::LEFT:
                $flags &= ~self::ALL_RESTRICTED_RIGHTS | $defaultRestrictions;
                $flags &= ~self::ALL_ADMIN_PERMISSION_RIGHTS;
                break;
        }

        return new self($status->type, $flags);
    }

    public function has(int $flag): bool
    {
        return ($this->flags & $flag) !== 0;
    }

    public function isMember(): bool
    {
        return ($this->flags & self::IS_MEMBER) !== 0;
    }

    public function isAnonymous(): bool
    {
        return ($this->flags & self::IS_ANONYMOUS) !== 0;
    }

    public function canBeEdited(): bool
    {
        return ($this->flags & self::CAN_BE_EDITED) !== 0;
    }

    public function isAdministrator(): bool
    {
        return $this->type === self::CREATOR || $this->type === self::ADMINISTRATOR;
    }

    /**
     * DialogParticipantStatus::is_member semantics of Client::is_chat_member.
     */
    public function isChatMember(): bool
    {
        return match ($this->type) {
            self::BANNED, self::LEFT => false,
            self::RESTRICTED => $this->isMember(),
            default => true,
        };
    }

    public function canSendMessages(): bool { return $this->has(self::CAN_SEND_MESSAGES); }
    public function canSendAudios(): bool { return $this->has(self::CAN_SEND_AUDIOS); }
    public function canSendDocuments(): bool { return $this->has(self::CAN_SEND_DOCUMENTS); }
    public function canSendPhotos(): bool { return $this->has(self::CAN_SEND_PHOTOS); }
    public function canSendVideos(): bool { return $this->has(self::CAN_SEND_VIDEOS); }
    public function canSendVideoNotes(): bool { return $this->has(self::CAN_SEND_VIDEO_NOTES); }
    public function canSendVoiceNotes(): bool { return $this->has(self::CAN_SEND_VOICE_NOTES); }
    public function canSendStickers(): bool { return $this->has(self::CAN_SEND_STICKERS); }
    public function canSendAnimations(): bool { return $this->has(self::CAN_SEND_ANIMATIONS); }
    public function canSendGames(): bool { return $this->has(self::CAN_SEND_GAMES); }
    public function canUseInlineBots(): bool { return $this->has(self::CAN_USE_INLINE_BOTS); }
    public function canAddWebPagePreviews(): bool { return $this->has(self::CAN_ADD_WEB_PAGE_PREVIEWS); }
    public function canSendPolls(): bool { return $this->has(self::CAN_SEND_POLLS); }
    public function canSendReactions(): bool { return $this->has(self::CAN_SEND_REACTIONS); }
    public function canEditRank(): bool { return $this->has(self::CAN_EDIT_RANK); }
    public function canPostMessages(): bool { return $this->has(self::CAN_POST_MESSAGES); }
    public function canEditMessages(): bool { return $this->has(self::CAN_EDIT_MESSAGES); }
    public function canDeleteMessages(): bool { return $this->has(self::CAN_DELETE_MESSAGES); }
    public function canRestrictMembers(): bool { return $this->has(self::CAN_RESTRICT_MEMBERS); }
    public function canPromoteMembers(): bool { return $this->has(self::CAN_PROMOTE_MEMBERS); }
    public function canManageCalls(): bool { return $this->has(self::CAN_MANAGE_CALLS); }
    public function canManageDialog(): bool { return $this->has(self::CAN_MANAGE_DIALOG); }
    public function canManageDirectMessages(): bool { return $this->has(self::CAN_MANAGE_DIRECT_MESSAGES); }
    public function canManageRanks(): bool { return $this->has(self::CAN_MANAGE_RANKS); }
    public function canPostStories(): bool { return $this->has(self::CAN_POST_STORIES); }
    public function canEditStories(): bool { return $this->has(self::CAN_EDIT_STORIES); }
    public function canDeleteStories(): bool { return $this->has(self::CAN_DELETE_STORIES); }

    public function canChangeInfoAndSettings(): bool
    {
        return $this->has(self::CAN_CHANGE_INFO_AND_SETTINGS_ADMIN) || $this->has(self::CAN_CHANGE_INFO_AND_SETTINGS);
    }

    public function canInviteUsers(): bool
    {
        return $this->has(self::CAN_INVITE_USERS_ADMIN) || $this->has(self::CAN_INVITE_USERS);
    }

    public function canManageInviteLinks(): bool
    {
        return $this->has(self::CAN_INVITE_USERS_ADMIN);
    }

    public function canPinMessages(): bool
    {
        return $this->has(self::CAN_PIN_MESSAGES_ADMIN) || $this->has(self::CAN_PIN_MESSAGES);
    }

    public function canEditTopics(): bool
    {
        return $this->has(self::CAN_MANAGE_TOPICS_ADMIN);
    }

    public function canCreateTopics(): bool
    {
        return $this->has(self::CAN_MANAGE_TOPICS_ADMIN) || $this->has(self::CAN_MANAGE_TOPICS);
    }

    /**
     * RestrictedRights::get_chat_permissions_object (td_api names, like
     * Objects\ChatPermissions).
     *
     * @return array<string, bool>
     */
    public static function permissionsObject(int $flags): array
    {
        $has = static fn (int $flag): bool => ($flags & $flag) !== 0;

        return [
            'can_send_basic_messages' => $has(self::CAN_SEND_MESSAGES),
            'can_send_audios' => $has(self::CAN_SEND_AUDIOS),
            'can_send_documents' => $has(self::CAN_SEND_DOCUMENTS),
            'can_send_photos' => $has(self::CAN_SEND_PHOTOS),
            'can_send_videos' => $has(self::CAN_SEND_VIDEOS),
            'can_send_video_notes' => $has(self::CAN_SEND_VIDEO_NOTES),
            'can_send_voice_notes' => $has(self::CAN_SEND_VOICE_NOTES),
            'can_send_polls' => $has(self::CAN_SEND_POLLS),
            'can_send_other_messages' => $has(self::CAN_SEND_STICKERS) || $has(self::CAN_SEND_ANIMATIONS)
                || $has(self::CAN_SEND_GAMES) || $has(self::CAN_USE_INLINE_BOTS),
            'can_add_link_previews' => $has(self::CAN_ADD_WEB_PAGE_PREVIEWS),
            'can_react_to_messages' => $has(self::CAN_SEND_REACTIONS),
            'can_edit_tag' => $has(self::CAN_EDIT_RANK),
            'can_change_info' => $has(self::CAN_CHANGE_INFO_AND_SETTINGS),
            'can_invite_users' => $has(self::CAN_INVITE_USERS),
            'can_pin_messages' => $has(self::CAN_PIN_MESSAGES),
            'can_create_topics' => $has(self::CAN_MANAGE_TOPICS),
        ];
    }

    /**
     * The Bot API ChatAdministratorRights of administrator rights
     * (AdministratorRights::get_chat_administrator_rights_object).
     *
     * @return array<string, bool>
     */
    public static function administratorRightsObject(int $flags): array
    {
        $has = static fn (int $flag): bool => ($flags & $flag) !== 0;

        return [
            'can_manage_chat' => $has(self::CAN_MANAGE_DIALOG),
            'can_change_info' => $has(self::CAN_CHANGE_INFO_AND_SETTINGS_ADMIN),
            'can_post_messages' => $has(self::CAN_POST_MESSAGES),
            'can_edit_messages' => $has(self::CAN_EDIT_MESSAGES),
            'can_delete_messages' => $has(self::CAN_DELETE_MESSAGES),
            'can_invite_users' => $has(self::CAN_INVITE_USERS_ADMIN),
            'can_restrict_members' => $has(self::CAN_RESTRICT_MEMBERS),
            'can_pin_messages' => $has(self::CAN_PIN_MESSAGES_ADMIN),
            'can_manage_topics' => $has(self::CAN_MANAGE_TOPICS_ADMIN),
            'can_promote_members' => $has(self::CAN_PROMOTE_MEMBERS),
            'can_manage_video_chats' => $has(self::CAN_MANAGE_CALLS),
            'can_post_stories' => $has(self::CAN_POST_STORIES),
            'can_edit_stories' => $has(self::CAN_EDIT_STORIES),
            'can_delete_stories' => $has(self::CAN_DELETE_STORIES),
            'can_manage_direct_messages' => $has(self::CAN_MANAGE_DIRECT_MESSAGES),
            'can_manage_tags' => $has(self::CAN_MANAGE_RANKS),
            'can_send_welcome_messages' => $has(self::CAN_MANAGE_WELCOME_MESSAGES),
            'is_anonymous' => $has(self::IS_ANONYMOUS),
        ];
    }

    /**
     * AdministratorRights::get_chat_admin_rights.
     *
     * @return array<string, mixed>
     */
    public static function chatAdminRights(int $flags): array
    {
        $has = static fn (int $flag): bool => ($flags & $flag) !== 0;

        return array_filter([
            '_' => 'chatAdminRights',
            'change_info' => $has(self::CAN_CHANGE_INFO_AND_SETTINGS_ADMIN),
            'post_messages' => $has(self::CAN_POST_MESSAGES),
            'edit_messages' => $has(self::CAN_EDIT_MESSAGES),
            'delete_messages' => $has(self::CAN_DELETE_MESSAGES),
            'ban_users' => $has(self::CAN_RESTRICT_MEMBERS),
            'invite_users' => $has(self::CAN_INVITE_USERS_ADMIN),
            'pin_messages' => $has(self::CAN_PIN_MESSAGES_ADMIN),
            'add_admins' => $has(self::CAN_PROMOTE_MEMBERS),
            'anonymous' => $has(self::IS_ANONYMOUS),
            'manage_call' => $has(self::CAN_MANAGE_CALLS),
            'other' => $has(self::CAN_MANAGE_DIALOG),
            'manage_topics' => $has(self::CAN_MANAGE_TOPICS_ADMIN),
            'post_stories' => $has(self::CAN_POST_STORIES),
            'edit_stories' => $has(self::CAN_EDIT_STORIES),
            'delete_stories' => $has(self::CAN_DELETE_STORIES),
            'manage_direct_messages' => $has(self::CAN_MANAGE_DIRECT_MESSAGES),
            'manage_ranks' => $has(self::CAN_MANAGE_RANKS),
            'manage_linked_peers' => $has(self::CAN_MANAGE_LINKED_PEERS_ADMIN),
            'manage_welcome_messages' => $has(self::CAN_MANAGE_WELCOME_MESSAGES),
        ]);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Member
    // ═════════════════════════════════════════════════════════════════

    /**
     * get_dialog_participant_status(td_api::ChatMemberStatus): a status the
     * bot sets, with td's until_date rules.
     */
    public static function fixInputUntilDate(int $untilDate, int $now): int
    {
        if ($untilDate === 0) {
            return 0;
        }
        if ($untilDate < $now + 30 || $untilDate > $now + 366 * 86400) {
            return 0;
        }

        return $untilDate;
    }

    /**
     * DialogParticipantStatus::set_is_member: only the member flag changes.
     */
    public function withIsMember(bool $isMember): self
    {
        $status = clone $this;
        $status->flags = $isMember ? $status->flags | self::IS_MEMBER : $status->flags & ~self::IS_MEMBER;

        return $status;
    }

    /**
     * DialogParticipantStatus::toggle_can_be_edited.
     */
    public function withToggledCanBeEdited(): self
    {
        $status = clone $this;
        $status->flags ^= self::CAN_BE_EDITED;

        return $status;
    }

    /**
     * DialogParticipantStatus::is_restricted.
     */
    public function isRestricted(): bool
    {
        return $this->type === self::RESTRICTED;
    }

    /**
     * DialogParticipantStatus::is_banned.
     */
    public function isBanned(): bool
    {
        return $this->type === self::BANNED;
    }

    public function isCreator(): bool
    {
        return $this->type === self::CREATOR;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->flags === $other->flags && $this->untilDate === $other->untilDate;
    }

    /**
     * Client::get_chat_member_status.
     */
    public function statusName(): string
    {
        return match ($this->type) {
            self::CREATOR => 'creator',
            self::ADMINISTRATOR => 'administrator',
            self::MEMBER => 'member',
            self::RESTRICTED => 'restricted',
            self::LEFT => 'left',
            default => 'kicked',
        };
    }

    /**
     * The status of a TL ChannelParticipant (DialogParticipant(ChannelParticipant)).
     *
     * @param array<string, mixed> $participant
     * @return array{peer: array<string, mixed>|null, user_id: int, status: self, rank: string}
     */
    public static function fromChannelParticipant(array $participant, string $channelType): array
    {
        $rank = (string) ($participant['rank'] ?? '');
        $userId = (int) ($participant['user_id'] ?? 0);
        $peer = $participant['peer'] ?? null;

        $status = match ($participant['_'] ?? '') {
            'channelParticipant', 'channelParticipantSelf' => self::member((int) ($participant['subscription_until_date'] ?? 0)),
            'channelParticipantCreator' => self::creator(true, !empty($participant['admin_rights']['anonymous'])),
            'channelParticipantAdmin' => self::administrator(self::administratorRightsFromServer($participant['admin_rights'] ?? [], $channelType), $rank, !empty($participant['can_edit'])),
            'channelParticipantLeft' => self::left(),
            'channelParticipantBanned' => self::fromBannedRights(empty($participant['left']), $participant['banned_rights'] ?? [], $channelType),
            default => self::left(),
        };

        return ['peer' => $peer, 'user_id' => $userId, 'status' => $status, 'rank' => $rank];
    }

    /**
     * DialogParticipantStatus(is_member, chatBannedRights, channel_type).
     *
     * @param array<string, mixed> $banned
     */
    public static function fromBannedRights(bool $isMember, array $banned, string $channelType): self
    {
        if (!empty($banned['view_messages'])) {
            return self::banned((int) ($banned['until_date'] ?? 0));
        }
        if ($channelType === 'broadcast') {
            return $isMember ? self::member() : self::left();
        }

        return new self(self::RESTRICTED, self::restrictedRightsFromServer($banned, $channelType) | ($isMember ? self::IS_MEMBER : 0),
            self::fixUntilDate((int) ($banned['until_date'] ?? 0)));
    }

    /**
     * The Bot API ChatMember of a status (Client::JsonChatMember).
     *
     * @param string $chatType 'private', 'group', 'supergroup' or 'channel'
     * @param array<string, mixed>|null $user the JSON user, null for a chat member
     * @return array<string, mixed>
     */
    public function chatMemberObject(?array $user, string $chatType, string $tag): array
    {
        $object = [];
        if ($user !== null) {
            $object['user'] = $user;
        }
        $object['status'] = $this->statusName();
        switch ($this->type) {
            case self::CREATOR:
                if ($tag !== '') {
                    $object['custom_title'] = $tag;
                }
                $object['is_anonymous'] = $this->isAnonymous();
                break;
            case self::ADMINISTRATOR:
                $object['can_be_edited'] = $this->canBeEdited();
                $rights = self::administratorRightsObject($this->flags);
                $object['can_manage_chat'] = $rights['can_manage_chat'];
                $object['can_change_info'] = $rights['can_change_info'];
                if ($chatType === 'channel') {
                    $object['can_post_messages'] = $rights['can_post_messages'];
                    $object['can_edit_messages'] = $rights['can_edit_messages'];
                }
                $object['can_delete_messages'] = $rights['can_delete_messages'];
                $object['can_invite_users'] = $rights['can_invite_users'];
                $object['can_restrict_members'] = $rights['can_restrict_members'];
                if ($chatType === 'group' || $chatType === 'supergroup') {
                    $object['can_pin_messages'] = $rights['can_pin_messages'];
                }
                if ($chatType === 'supergroup') {
                    $object['can_manage_topics'] = $rights['can_manage_topics'];
                }
                $object['can_promote_members'] = $rights['can_promote_members'];
                $object['can_manage_video_chats'] = $rights['can_manage_video_chats'];
                $object['can_post_stories'] = $rights['can_post_stories'];
                $object['can_edit_stories'] = $rights['can_edit_stories'];
                $object['can_delete_stories'] = $rights['can_delete_stories'];
                if ($chatType === 'channel') {
                    $object['can_manage_direct_messages'] = $rights['can_manage_direct_messages'];
                }
                if ($chatType === 'group' || $chatType === 'supergroup') {
                    $object['can_manage_tags'] = $rights['can_manage_tags'];
                }
                $object['can_send_welcome_messages'] = $rights['can_send_welcome_messages'];
                $object['is_anonymous'] = $rights['is_anonymous'];
                $object['can_manage_voice_chats'] = $rights['can_manage_video_chats'];
                if ($tag !== '') {
                    $object['custom_title'] = $tag;
                }
                break;
            case self::MEMBER:
                if ($tag !== '') {
                    $object['tag'] = $tag;
                }
                if ($this->untilDate > 0) {
                    $object['until_date'] = $this->untilDate;
                }
                break;
            case self::RESTRICTED:
                if ($tag !== '') {
                    $object['tag'] = $tag;
                }
                if ($chatType === 'supergroup') {
                    $object['until_date'] = $this->untilDate;
                    $object += \LaraGram\Server\Objects\JsonChatPermissions::make(self::permissionsObject($this->flags));
                    $object['is_member'] = $this->isMember();
                }
                break;
            case self::BANNED:
                $object['until_date'] = $this->untilDate;
                break;
        }

        return $object;
    }
}
