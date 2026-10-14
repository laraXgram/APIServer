<?php

declare(strict_types=1);

namespace LaraGram\Server\Updates;

/**
 * Client::UpdateType: bit positions in the allowed-updates mask and the
 * field names of the Bot API `Update` object.
 */
final class UpdateType
{
    public const MESSAGE = 0;
    public const EDITED_MESSAGE = 1;
    public const CHANNEL_POST = 2;
    public const EDITED_CHANNEL_POST = 3;
    public const INLINE_QUERY = 4;
    public const CHOSEN_INLINE_RESULT = 5;
    public const CALLBACK_QUERY = 6;
    public const CUSTOM_EVENT = 7;
    public const CUSTOM_QUERY = 8;
    public const SHIPPING_QUERY = 9;
    public const PRE_CHECKOUT_QUERY = 10;
    public const POLL = 11;
    public const POLL_ANSWER = 12;
    public const MY_CHAT_MEMBER = 13;
    public const CHAT_MEMBER = 14;
    public const CHAT_JOIN_REQUEST = 15;
    public const CHAT_BOOST_UPDATED = 16;
    public const CHAT_BOOST_REMOVED = 17;
    public const MESSAGE_REACTION = 18;
    public const MESSAGE_REACTION_COUNT = 19;
    public const BUSINESS_CONNECTION = 20;
    public const BUSINESS_MESSAGE = 21;
    public const EDITED_BUSINESS_MESSAGE = 22;
    public const BUSINESS_MESSAGES_DELETED = 23;
    public const PURCHASED_PAID_MEDIA = 24;
    public const MANAGED_BOT = 25;
    public const GUEST_MESSAGE = 26;
    public const SUBSCRIPTION = 27;
    public const STOP_MESSAGE_DRAFT = 28;
    public const SIZE = 29;

    public const NAMES = [
        'message', 'edited_message', 'channel_post', 'edited_channel_post', 'inline_query', 'chosen_inline_result',
        'callback_query', 'custom_event', 'custom_query', 'shipping_query', 'pre_checkout_query', 'poll', 'poll_answer',
        'my_chat_member', 'chat_member', 'chat_join_request', 'chat_boost', 'removed_chat_boost', 'message_reaction',
        'message_reaction_count', 'business_connection', 'business_message', 'edited_business_message',
        'deleted_business_messages', 'purchased_paid_media', 'managed_bot', 'guest_message', 'subscription',
        'stopped_message_generation',
    ];

    /** Everything but chat_member, message_reaction and message_reaction_count. */
    public const DEFAULT_ALLOWED = ((1 << self::SIZE) - 1)
        - (1 << self::CHAT_MEMBER) - (1 << self::MESSAGE_REACTION) - (1 << self::MESSAGE_REACTION_COUNT);

    /**
     * Client::get_allowed_update_types.
     */
    public static function parseAllowed(string $allowedUpdates, bool $isInternal): int
    {
        if ($allowedUpdates === '') {
            return 0;
        }

        try {
            $value = json_decode($allowedUpdates, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return 0;
        }

        if (!is_array($value)) {
            if ($isInternal && (is_int($value) || is_string($value)) && ctype_digit((string) $value) && (int) $value > 0) {
                return (int) $value;
            }

            return 0;
        }

        $result = 0;
        foreach ($value as $name) {
            if (!is_string($name)) {
                return 0;
            }
            $index = array_search(strtolower($name), self::NAMES, true);
            if ($index !== false) {
                $result |= 1 << $index;
            }
        }

        return $result === 0 ? self::DEFAULT_ALLOWED : $result;
    }
}
