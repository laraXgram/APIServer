<?php

declare(strict_types=1);

namespace LaraGram\Server\Objects;

/**
 * The `reply_markup` of a received message (Client::JsonReplyMarkup): only
 * inline keyboards are kept, as in Client::set_message_reply_markup.
 * Buttons follow TDLib's TL → td_api conversion (InlineKeyboardButton.cpp).
 */
final class JsonReplyMarkup
{
    /**
     * @param array<string, mixed> $markup TL ReplyMarkup
     * @return array<string, mixed>|null
     */
    public static function make(array $markup): ?array
    {
        if (($markup['_'] ?? '') !== 'replyInlineMarkup') {
            return null;
        }

        $rows = [];
        foreach ($markup['rows'] ?? [] as $row) {
            $buttons = [];
            foreach ($row['buttons'] ?? [] as $button) {
                if (($object = self::button($button)) !== null) {
                    $buttons[] = $object;
                }
            }
            $rows[] = $buttons;
        }

        $object = ['inline_keyboard' => $rows];
        if (!empty($markup['force_reply'])) {
            $object['force_reply'] = true;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $button TL keyboardInlineButton
     * @return array<string, mixed>|null
     */
    private static function button(array $button): ?array
    {
        $type = $button['type'] ?? [];
        $object = ['text' => (string) ($button['text'] ?? '')];

        $style = $button['style'] ?? null;
        if (!empty($style['icon'])) {
            $object['icon_custom_emoji_id'] = (string) $style['icon'];
        }
        if (!empty($style['bg_primary'])) {
            $object['style'] = 'primary';
        } elseif (!empty($style['bg_danger'])) {
            $object['style'] = 'danger';
        } elseif (!empty($style['bg_success'])) {
            $object['style'] = 'success';
        }

        if (!self::storeButtonType($object, $type)) {
            return null;
        }

        return $object;
    }

    /**
     * Stores the action of an inline keyboard button; false for unsupported button types.
     *
     * @param array<string, mixed> $object
     * @param array<string, mixed> $type TL KeyboardButtonType
     */
    public static function storeButtonType(array &$object, array $type): bool
    {
        switch ($type['_'] ?? '') {
            case 'inlineButtonTypeUrl':
            case 'inlineButtonTypeUrlAuth':
                $object['url'] = (string) $type['url'];
                break;
            case 'inlineButtonTypeCallback':
                $data = (string) ($type['data'] ?? '');
                $object['callback_data'] = mb_check_encoding($data, 'UTF-8') ? $data : 'INVALID';
                break;
            case 'inlineButtonTypeGame':
                $object['callback_game'] = new \stdClass();
                break;
            case 'inlineButtonTypeSwitchInline':
                $query = (string) ($type['query'] ?? '');
                if (!empty($type['same_peer'])) {
                    $object['switch_inline_query_current_chat'] = $query;
                    break;
                }
                $types = self::targetChatTypes($type['peer_types'] ?? null);
                if ($types['allow_user_chats'] && $types['allow_bot_chats'] && $types['allow_group_chats'] && $types['allow_channel_chats']) {
                    $object['switch_inline_query'] = $query;
                } else {
                    $object['switch_inline_query_chosen_chat'] = ['query' => $query] + $types;
                }
                break;
            case 'inlineButtonTypeBuy':
                $object['pay'] = true;
                break;
            case 'inlineButtonTypeUserProfile':
                $object['url'] = 'tg://user?id='.(int) $type['user_id'];
                break;
            case 'inlineButtonTypeWebView':
                $object['web_app'] = ['url' => (string) $type['url']];
                break;
            case 'inlineButtonTypeCopy':
                $object['copy_text'] = ['text' => (string) $type['copy_text']];
                break;
            case 'inlineButtonTypeDisabled':
                $object['disabled'] = new \stdClass();
                break;
            default:
                return false;
        }

        return true;
    }

    /**
     * TDLib TargetDialogTypes: no peer types means every chat type.
     *
     * @param list<array<string, mixed>>|null $peerTypes
     * @return array{allow_user_chats: bool, allow_bot_chats: bool, allow_group_chats: bool, allow_channel_chats: bool}
     */
    private static function targetChatTypes(?array $peerTypes): array
    {
        if (empty($peerTypes)) {
            return ['allow_user_chats' => true, 'allow_bot_chats' => true, 'allow_group_chats' => true, 'allow_channel_chats' => true];
        }

        $types = ['allow_user_chats' => false, 'allow_bot_chats' => false, 'allow_group_chats' => false, 'allow_channel_chats' => false];
        foreach ($peerTypes as $peerType) {
            match ($peerType['_'] ?? '') {
                'inlineQueryPeerTypePM' => $types['allow_user_chats'] = true,
                'inlineQueryPeerTypeBotPM' => $types['allow_bot_chats'] = true,
                'inlineQueryPeerTypeChat', 'inlineQueryPeerTypeMegagroup' => $types['allow_group_chats'] = true,
                'inlineQueryPeerTypeBroadcast' => $types['allow_channel_chats'] = true,
                default => null,
            };
        }

        return $types;
    }
}
