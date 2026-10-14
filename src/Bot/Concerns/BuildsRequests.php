<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Concerns;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Entities\TgLinks;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Support\LinkChecker;

/**
 * td_api → telegram_api conversions TDLib performs before sending a request:
 * reply markup (ReplyMarkup.cpp, KeyboardButton.cpp, InlineKeyboardButton.cpp),
 * administrator rights and chat permissions (DialogParticipant.cpp).
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait BuildsRequests
{
    /**
     * A TL object without its absent (null) and unset (false) optional fields.
     *
     * @param array<string, mixed> $fields
     * @param list<string> $keepFalse Bool fields whose false value is meaningful
     * @return array<string, mixed>
     */
    public static function tl(string $constructor, array $fields = [], array $keepFalse = []): array
    {
        $object = ['_' => $constructor];
        foreach ($fields as $name => $value) {
            if ($value === null || ($value === false && !in_array($name, $keepFalse, true))) {
                continue;
            }
            $object[$name] = $value;
        }

        return $object;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Reply markup
    // ═════════════════════════════════════════════════════════════════

    /**
     * Resolve the bot usernames of login URL buttons
     * (Client::resolve_reply_markup_bot_usernames).
     *
     * @param array<string, mixed>|null $markup td_api ReplyMarkup
     * @return array<string, mixed>|null
     */
    public function resolveReplyMarkupBotUsernames(?array $markup): ?array
    {
        if (($markup['_'] ?? '') !== 'replyMarkupInlineKeyboard') {
            return $markup;
        }

        foreach ($markup['rows'] as &$row) {
            foreach ($row as &$button) {
                $button['type'] = $this->resolveLoginUrlBot($button['type']);
            }
            unset($button);
        }
        unset($row);

        return $markup;
    }

    /**
     * @param array<string, mixed> $type td_api InlineKeyboardButtonType
     * @return array<string, mixed>
     */
    public function resolveLoginUrlBot(array $type): array
    {
        if ($type['_'] !== 'inlineKeyboardButtonTypeLoginUrl' || isset($type['id'])) {
            return $type;
        }

        $botUserId = 0;
        $username = $type['bot_username'];
        if ($username !== '') {
            $botUserId = $this->resolveBotUsername($username);
            if ($botUserId === 0) {
                throw new QueryError(400, "Bad Request: bot \"{$username}\" not found");
            }
        }
        if (!$type['request_write_access']) {
            $botUserId = -$botUserId - 1;
        }

        return ['_' => 'inlineKeyboardButtonTypeLoginUrl', 'url' => $type['url'], 'id' => $botUserId, 'forward_text' => $type['forward_text']];
    }

    /**
     * TdOnResolveBotUsernameCallback: the bot's user identifier, or 0.
     */
    public function resolveBotUsername(string $username): int
    {
        static $cache = [];
        if (isset($cache[$username])) {
            return $cache[$username];
        }

        try {
            $chatId = $this->searchPublicChat($username);
        } catch (QueryError) {
            return 0;
        }
        if (ChatId::type($chatId) !== ChatId::TYPE_PRIVATE || empty($this->getUser($chatId)['bot'])) {
            return 0;
        }

        return $cache[$username] = $chatId;
    }

    /**
     * get_reply_markup(td_api, dialog_type, is_admined_monoforum, is_bot, is_anonymous)
     * + ReplyMarkup::get_input_reply_markup.
     *
     * @param array<string, mixed>|null $markup td_api ReplyMarkup
     * @return array<string, mixed>|null TL ReplyMarkup
     */
    public function getChatInputReplyMarkup(?array $markup, int $chatId, bool $isAdminedMonoforum, bool $isAnonymous): ?array
    {
        $isPrivate = ChatId::type($chatId) === ChatId::TYPE_PRIVATE;

        return $this->getInputReplyMarkup($markup, $isAnonymous && !$isAdminedMonoforum, $isPrivate, !$isAnonymous, !$isPrivate);
    }

    /**
     * get_inline_reply_markup + ReplyMarkup::get_input_reply_markup.
     *
     * @param array<string, mixed>|null $markup
     * @return array<string, mixed>|null
     */
    public function getInlineInputReplyMarkup(?array $markup, bool $switchInlineButtonsAllowed): ?array
    {
        return $this->getInputReplyMarkup($markup, true, false, $switchInlineButtonsAllowed, false);
    }

    /**
     * @param array<string, mixed>|null $markup
     * @return array<string, mixed>|null
     */
    private function getInputReplyMarkup(?array $markup, bool $onlyInline, bool $requestButtonsAllowed, bool $switchInlineButtonsAllowed, bool $allowPersonal): ?array
    {
        if ($markup === null) {
            return null;
        }

        if ($onlyInline && $markup['_'] !== 'replyMarkupInlineKeyboard') {
            throw new QueryError(400, 'Inline keyboard expected');
        }

        switch ($markup['_']) {
            case 'replyMarkupShowKeyboard':
                $rows = $this->convertKeyboardRows($markup['rows'], fn (array $button): array => $this->getInputKeyboardButton($button, $requestButtonsAllowed));
                if ($rows === []) {
                    return null;
                }

                return self::tl('replyKeyboardMarkup', [
                    'resize' => $markup['resize_keyboard'],
                    'single_use' => $markup['one_time'],
                    'selective' => $markup['is_personal'] && $allowPersonal,
                    'persistent' => $markup['is_persistent'],
                    'rows' => array_map(static fn (array $buttons): array => ['_' => 'keyboardButtonRow', 'buttons' => $buttons], $rows),
                    'placeholder' => $markup['input_field_placeholder'] === '' ? null : $markup['input_field_placeholder'],
                ]);

            case 'replyMarkupInlineKeyboard':
                $rows = $this->convertKeyboardRows($markup['rows'], fn (array $button): array => $this->getInputInlineKeyboardButton($button, $switchInlineButtonsAllowed));
                if ($rows === []) {
                    return null;
                }

                return self::tl('replyInlineMarkup', [
                    'force_reply' => $markup['force_reply'] ?? false,
                    'rows' => array_map(static fn (array $buttons): array => ['_' => 'keyboardInlineButtonRow', 'buttons' => $buttons], $rows),
                ]);

            case 'replyMarkupRemoveKeyboard':
                return self::tl('replyKeyboardHide', ['selective' => $markup['is_personal'] && $allowPersonal]);

            case 'replyMarkupForceReply':
                return self::tl('replyKeyboardForceReply', [
                    'selective' => $markup['is_personal'] && $allowPersonal,
                    'placeholder' => $markup['input_field_placeholder'] === '' ? null : $markup['input_field_placeholder'],
                ]);
        }

        return null;
    }

    /**
     * Rows of at most 12 buttons and 300 buttons in total; empty buttons are skipped.
     *
     * @param list<list<array<string, mixed>>> $rows
     * @return list<list<array<string, mixed>>>
     */
    private function convertKeyboardRows(array $rows, callable $convert): array
    {
        $result = [];
        $total = 0;
        foreach ($rows as $row) {
            $buttons = [];
            foreach ($row as $button) {
                if ($button['text'] === '' && $button['icon_custom_emoji_id'] === 0) {
                    continue;
                }
                $buttons[] = $convert($button);
                $total++;
                if (count($buttons) >= 12 || $total >= 300) {
                    break;
                }
            }
            if ($buttons !== []) {
                $result[] = $buttons;
            }
            if ($total >= 300) {
                break;
            }
        }

        return $result;
    }

    /**
     * KeyboardButtonStyle::get_input_keyboard_button_style.
     *
     * @return array<string, mixed>|null
     */
    private static function inputButtonStyle(string $style, int $iconCustomEmojiId): ?array
    {
        if ($style === 'default' && $iconCustomEmojiId === 0) {
            return null;
        }

        return self::tl('keyboardButtonStyle', [
            'bg_primary' => $style === 'primary',
            'bg_danger' => $style === 'danger',
            'bg_success' => $style === 'success',
            'icon' => $iconCustomEmojiId === 0 ? null : $iconCustomEmojiId,
        ]);
    }

    /**
     * KeyboardButton::get_keyboard_button + get_input_keyboard_button.
     *
     * @param array<string, mixed> $button
     * @return array<string, mixed>
     */
    private function getInputKeyboardButton(array $button, bool $requestButtonsAllowed): array
    {
        $text = TextFormatting::cleanInputString($button['text']);
        if ($text === null) {
            throw new QueryError(400, 'Keyboard button text must be encoded in UTF-8');
        }
        if ($text === '') {
            throw new QueryError(400, 'Keyboard button text must be non-empty');
        }

        $type = $button['type'] ?? null;
        $denied = static function (string $what) use ($requestButtonsAllowed): void {
            if (!$requestButtonsAllowed) {
                throw new QueryError(400, $what);
            }
        };

        $tlType = match ($type['_'] ?? 'keyboardButtonTypeText') {
            'keyboardButtonTypeText' => ['_' => 'buttonTypeDefault'],
            'keyboardButtonTypeRequestPhoneNumber' => $denied('Phone number can be requested in private chats only') ?? ['_' => 'buttonTypeRequestPhone'],
            'keyboardButtonTypeRequestLocation' => $denied('Location can be requested in private chats only') ?? ['_' => 'buttonTypeRequestGeoLocation'],
            'keyboardButtonTypeRequestPoll' => $denied('Poll can be requested in private chats only') ?? $this->requestPollButtonType($type),
            'keyboardButtonTypeWebApp' => $denied('Web App buttons can be used in private chats only') ?? [
                '_' => 'buttonTypeSimpleWebView',
                'url' => $this->checkButtonUrl($type['url'], 'Keyboard button Web App ', 'Link to a user can\'t be used in Web App URL buttons', false),
            ],
            'keyboardButtonTypeRequestUsers' => $denied('Users can be requested in private chats only') ?? $this->requestPeerButtonType($type),
            'keyboardButtonTypeRequestChat' => $denied('Chats can be requested in private chats only') ?? $this->requestPeerButtonType($type),
            'keyboardButtonTypeRequestManagedBot' => $denied('Managed bots can be requested in private chats only') ?? $this->requestPeerButtonType($type),
        };

        return self::tl('keyboardButton', [
            'style' => self::inputButtonStyle($button['style'], $button['icon_custom_emoji_id']),
            'text' => $text,
            'type' => $tlType,
        ]);
    }

    /**
     * @param array<string, mixed> $type
     * @return array<string, mixed>
     */
    private function requestPollButtonType(array $type): array
    {
        if ($type['force_quiz'] && $type['force_regular']) {
            throw new QueryError(400, 'Can\'t force quiz mode and regular poll simultaneously');
        }
        if ($type['force_quiz'] || $type['force_regular']) {
            return ['_' => 'buttonTypeRequestPoll', 'quiz' => $type['force_quiz']];
        }

        return ['_' => 'buttonTypeRequestPoll'];
    }

    /**
     * RequestedDialogType: td_api request type → inputButtonTypeRequestPeer.
     *
     * @param array<string, mixed> $type
     * @return array<string, mixed>
     */
    private function requestPeerButtonType(array $type): array
    {
        switch ($type['_']) {
            case 'keyboardButtonTypeRequestUsers':
                $peerType = ['_' => 'requestPeerTypeUser'];
                if ($type['restrict_user_is_bot']) {
                    $peerType['bot'] = $type['user_is_bot'];
                }
                if ($type['restrict_user_is_premium']) {
                    $peerType['premium'] = $type['user_is_premium'];
                }

                return self::tl('inputButtonTypeRequestPeer', [
                    'name_requested' => $type['request_name'],
                    'username_requested' => $type['request_username'],
                    'photo_requested' => $type['request_photo'],
                    'button_id' => $type['id'],
                    'peer_type' => $peerType,
                    'max_quantity' => max($type['max_quantity'], 1),
                ]);

            case 'keyboardButtonTypeRequestChat':
                $isChannel = $type['chat_is_channel'];
                $userRights = $type['user_administrator_rights'] === null ? null : $this->getChatAdminRights($type['user_administrator_rights'], $isChannel ? 'broadcast' : 'megagroup');
                $botRights = $type['bot_administrator_rights'] === null ? null : $this->getChatAdminRights($type['bot_administrator_rights'], $isChannel ? 'broadcast' : 'megagroup');
                if ($isChannel) {
                    $peerType = self::tl('requestPeerTypeBroadcast', ['creator' => $type['chat_is_created']]);
                } else {
                    $peerType = self::tl('requestPeerTypeChat', [
                        'creator' => $type['chat_is_created'],
                        'bot_participant' => $type['bot_is_member'],
                    ]);
                    if ($type['restrict_chat_is_forum']) {
                        $peerType['forum'] = $type['chat_is_forum'];
                    }
                }
                if ($type['restrict_chat_has_username']) {
                    $peerType['has_username'] = $type['chat_has_username'];
                }
                if ($userRights !== null) {
                    $peerType['user_admin_rights'] = $userRights;
                }
                if ($botRights !== null) {
                    $peerType['bot_admin_rights'] = $botRights;
                }

                return self::tl('inputButtonTypeRequestPeer', [
                    'name_requested' => $type['request_title'],
                    'username_requested' => $type['request_username'],
                    'photo_requested' => $type['request_photo'],
                    'button_id' => $type['id'],
                    'peer_type' => $peerType,
                    'max_quantity' => 1,
                ]);

            default:
                $name = TextFormatting::cleanInputString($type['suggested_name']) ?? '';
                $username = TextFormatting::cleanInputString($type['suggested_username']) ?? '';

                return self::tl('inputButtonTypeRequestPeer', [
                    'button_id' => $type['id'],
                    'peer_type' => self::tl('requestPeerTypeCreateBot', [
                        'bot_managed' => true,
                        'suggested_name' => $name === '' ? null : $name,
                        'suggested_username' => $username === '' ? null : $username,
                    ]),
                    'max_quantity' => 1,
                ]);
        }
    }

    /**
     * LinkManager::check_link for http(s)-only buttons, with TDLib's error prefixes.
     */
    private function checkButtonUrl(string $url, string $errorPrefix, string $userLinkError, bool $allowUserLink): string
    {
        if (!$allowUserLink && TgLinks::userId($url) > 0) {
            throw new QueryError(400, $userLinkError);
        }

        try {
            return LinkChecker::check($url, true, !$this->isTestDc());
        } catch (\InvalidArgumentException $e) {
            throw new QueryError(400, $errorPrefix.$e->getMessage());
        }
    }

    /**
     * get_inline_keyboard_button + get_input_keyboard_inline_button.
     *
     * @param array<string, mixed> $button
     * @return array<string, mixed>
     */
    private function getInputInlineKeyboardButton(array $button, bool $switchInlineButtonsAllowed): array
    {
        $text = TextFormatting::cleanInputString($button['text']);
        if ($text === null) {
            throw new QueryError(400, 'Inline keyboard button text must be encoded in UTF-8');
        }
        if ($text === '') {
            throw new QueryError(400, 'Inline keyboard button text must be non-empty');
        }

        $type = $button['type'];
        $clean = static function (string $value, string $error): string {
            $value = TextFormatting::cleanInputString($value);
            if ($value === null) {
                throw new QueryError(400, $error);
            }

            return $value;
        };

        switch ($type['_']) {
            case 'inlineKeyboardButtonTypeUrl':
                $userId = TgLinks::userId($type['url']);
                if ($userId > 0) {
                    $tlType = ['_' => 'inputInlineButtonTypeUserProfile', 'user_id' => $this->inputUser($userId)];
                    break;
                }
                try {
                    $url = LinkChecker::check($type['url']);
                } catch (\InvalidArgumentException $e) {
                    throw new QueryError(400, 'Inline keyboard button '.$e->getMessage());
                }
                $tlType = ['_' => 'inlineButtonTypeUrl', 'url' => $clean($url, 'Inline keyboard button URL must be encoded in UTF-8')];
                break;

            case 'inlineKeyboardButtonTypeCallback':
                $tlType = ['_' => 'inlineButtonTypeCallback', 'data' => $type['data']];
                break;

            case 'inlineKeyboardButtonTypeCallbackGame':
                $tlType = ['_' => 'inlineButtonTypeGame'];
                break;

            case 'inlineKeyboardButtonTypeSwitchInline':
                $target = $type['target'];
                $peerTypes = [];
                if ($target !== null) {
                    if (!$target['users'] && !$target['bots'] && !$target['groups'] && !$target['channels']) {
                        throw new QueryError(400, 'At least one chat type must be allowed');
                    }
                    if (!($target['users'] && $target['bots'] && $target['groups'] && $target['channels'])) {
                        if ($target['users']) {
                            $peerTypes[] = ['_' => 'inlineQueryPeerTypePM'];
                        }
                        if ($target['bots']) {
                            $peerTypes[] = ['_' => 'inlineQueryPeerTypeBotPM'];
                        }
                        if ($target['groups']) {
                            $peerTypes[] = ['_' => 'inlineQueryPeerTypeChat'];
                            $peerTypes[] = ['_' => 'inlineQueryPeerTypeMegagroup'];
                        }
                        if ($target['channels']) {
                            $peerTypes[] = ['_' => 'inlineQueryPeerTypeBroadcast'];
                        }
                    }
                }
                if (!$switchInlineButtonsAllowed) {
                    $name = $target === null ? 'switch_inline_query_current_chat' : 'switch_inline_query';
                    throw new QueryError(400, "Can't use {$name} button in a channel chat, because users will not be able to use the button without knowing bot's username");
                }
                $tlType = self::tl('inlineButtonTypeSwitchInline', [
                    'same_peer' => $target === null,
                    'query' => $clean($type['query'], 'Inline keyboard button switch inline query must be encoded in UTF-8'),
                    'peer_types' => $peerTypes === [] ? null : $peerTypes,
                ]);
                break;

            case 'inlineKeyboardButtonTypeBuy':
                $tlType = ['_' => 'inlineButtonTypeBuy'];
                break;

            case 'inlineKeyboardButtonTypeLoginUrl':
                $type = $this->resolveLoginUrlBot($type);
                $url = $this->checkButtonUrl($type['url'], 'Inline keyboard button login ', 'Link to a user can\'t be used in login URL buttons', false);
                $url = $clean($url, 'Inline keyboard button login URL must be encoded in UTF-8');
                $forwardText = $clean($type['forward_text'], 'Inline keyboard button forward text must be encoded in UTF-8');
                $id = (int) $type['id'];
                $requestWriteAccess = $id >= 0;
                $botUserId = $id >= 0 ? $id : -$id - 1;
                $tlType = self::tl('inputInlineButtonTypeUrlAuth', [
                    'request_write_access' => $requestWriteAccess,
                    'fwd_text' => $forwardText === '' ? null : $forwardText,
                    'url' => $url,
                    'bot' => $botUserId === 0 ? null : $this->inputUser($botUserId),
                ]);
                break;

            case 'inlineKeyboardButtonTypeWebApp':
                $url = $this->checkButtonUrl($type['url'], 'Inline keyboard button Web App ', 'Link to a user can\'t be used in Web App URL buttons', false);
                $tlType = ['_' => 'inlineButtonTypeWebView', 'url' => $clean($url, 'Inline keyboard button Web App URL must be encoded in UTF-8')];
                break;

            case 'inlineKeyboardButtonTypeCopyText':
                $tlType = ['_' => 'inlineButtonTypeCopy', 'copy_text' => $clean($type['text'], 'Inline keyboard button copied text must be encoded in UTF-8')];
                break;

            case 'inlineKeyboardButtonTypeDisabled':
                $tlType = ['_' => 'inlineButtonTypeDisabled'];
                break;

            default:
                throw new QueryError(400, 'Inline keyboard button type must be non-empty');
        }

        return self::tl('keyboardInlineButton', [
            'style' => self::inputButtonStyle($button['style'], $button['icon_custom_emoji_id']),
            'text' => $text,
            'type' => $tlType,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Rights
    // ═════════════════════════════════════════════════════════════════

    /**
     * AdministratorRights(td_api rights, channel_type) + get_chat_admin_rights.
     *
     * @param array<string, bool> $rights Bot API ChatAdministratorRights fields
     * @param string $channelType 'broadcast', 'megagroup' or 'unknown'
     * @return array<string, mixed> TL chatAdminRights
     */
    public function getChatAdminRights(array $rights, string $channelType): array
    {
        $r = $rights + ['can_manage_tags' => false, 'can_send_welcome_messages' => false];
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

        $fields = [
            'change_info' => $r['can_change_info'],
            'post_messages' => $r['can_post_messages'],
            'edit_messages' => $r['can_edit_messages'],
            'delete_messages' => $r['can_delete_messages'],
            'ban_users' => $r['can_restrict_members'],
            'invite_users' => $r['can_invite_users'],
            'pin_messages' => $r['can_pin_messages'],
            'add_admins' => $r['can_promote_members'],
            'anonymous' => $r['is_anonymous'],
            'manage_call' => $r['can_manage_video_chats'],
            'other' => $r['can_manage_chat'],
            'manage_topics' => $r['can_manage_topics'],
            'post_stories' => $r['can_post_stories'],
            'edit_stories' => $r['can_edit_stories'],
            'delete_stories' => $r['can_delete_stories'],
            'manage_direct_messages' => $r['can_manage_direct_messages'],
            'manage_ranks' => $r['can_manage_tags'],
            'manage_welcome_messages' => $r['can_send_welcome_messages'],
        ];
        if (in_array(true, $fields, true)) {
            $fields['other'] = true;
        }

        return self::tl('chatAdminRights', $fields);
    }

    public function isTestDc(): bool
    {
        return $this->isTestDc;
    }
}
