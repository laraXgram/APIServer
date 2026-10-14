<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Concerns;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\EntityParser;
use LaraGram\Server\Entities\FormattedDate;
use LaraGram\Server\Entities\FormattedText;
use LaraGram\Server\Entities\FormattingError;
use LaraGram\Server\Entities\TgLinks;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Support\JsonError;
use LaraGram\Server\Support\JsonObject;
use LaraGram\Server\Support\JsonValue;
use LaraGram\Server\Support\TdInteger;
use LaraGram\Server\Support\TdJson;

/**
 * Argument parsing shared by the method handlers: ports of the Client::get_*
 * helpers of the official server. Failures throw {@see QueryError} with the
 * official message; the client turns it into the Bot API error.
 *
 * Parsed values keep TDLib's td_api shapes as arrays.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait ParsesArguments
{
    /**
     * Client::to_bool.
     */
    public static function toBool(string $value): bool
    {
        $value = trim(strtolower($value), " \t\r\n\0\x0B");

        return $value === 'true' || $value === 'yes' || $value === '1';
    }

    protected static function boolArg(Query $query, string $field): bool
    {
        return self::toBool($query->arg($field));
    }

    /**
     * json_decode of an argument; an error becomes $errorMessage.
     */
    protected static function jsonArg(string $value, string $errorMessage): JsonValue
    {
        try {
            return TdJson::decode($value);
        } catch (JsonError) {
            throw QueryError::badRequest($errorMessage);
        }
    }

    /**
     * Run a parser that reports TDLib JSON field errors, as code 400.
     *
     * @template T
     * @param callable(): T $parse
     * @return T
     */
    protected static function json(callable $parse): mixed
    {
        try {
            return $parse();
        } catch (JsonError $e) {
            throw QueryError::badRequest($e->getMessage());
        }
    }

    /**
     * Client::get_forum_topic_id.
     */
    protected static function getForumTopicId(Query $query, string $field = 'message_thread_id'): int
    {
        $arg = $query->arg($field);
        if ($arg === '') {
            return 0;
        }
        $value = TdInteger::toInt32($arg);

        return $value < 0 ? 0 : $value;
    }

    /**
     * Client::get_message_id (server message identifier).
     */
    protected static function getMessageId(Query $query, string $field = 'message_id'): int
    {
        $arg = $query->arg($field);
        if ($arg === '') {
            return 0;
        }
        $value = TdInteger::toInt32($arg);

        return $value < 0 ? 0 : $value;
    }

    /**
     * Client::get_message_ids.
     *
     * @return list<int>
     */
    protected static function getMessageIds(Query $query, int $maxCount, string $field = 'message_ids'): array
    {
        $arg = $query->arg($field);
        if ($arg === '') {
            throw QueryError::badRequest('Message identifiers are not specified');
        }
        $value = self::jsonArg($arg, "Can't parse {$field} JSON object");
        if (!$value->isArray()) {
            throw QueryError::badRequest('Expected an Array of message identifiers');
        }
        if (count($value->array()) > $maxCount) {
            throw QueryError::badRequest('Too many message identifiers specified');
        }

        $ids = [];
        foreach ($value->array() as $item) {
            if ($item->type !== TdJson::NUMBER && $item->type !== TdJson::STRING) {
                throw QueryError::badRequest('Message identifier must be a Number');
            }
            $id = TdInteger::toInt32Safe($item->string());
            if ($id === null) {
                throw QueryError::badRequest("Can't parse message identifier as a Number");
            }
            if ($id <= 0) {
                throw QueryError::badRequest('Invalid message identifier specified');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Client::get_user_id.
     */
    protected static function getUserIdArg(Query $query, string $field = 'user_id'): int
    {
        $id = max(TdInteger::toInt64($query->arg($field)), 0);
        if ($id === 0) {
            throw QueryError::badRequest("Invalid {$field} specified");
        }

        return $id;
    }

    /**
     * Client::get_user_ids.
     *
     * @return list<int>
     */
    protected static function getUserIds(Query $query, int $maxCount, string $field = 'user_ids'): array
    {
        $arg = $query->arg($field);
        if ($arg === '') {
            throw QueryError::badRequest('User identifiers are not specified');
        }
        $value = self::jsonArg($arg, "Can't parse {$field} JSON object");
        if (!$value->isArray()) {
            throw QueryError::badRequest('Expected an Array of user identifiers');
        }
        if (count($value->array()) > $maxCount) {
            throw QueryError::badRequest('Too many user identifiers specified');
        }

        $ids = [];
        foreach ($value->array() as $item) {
            if ($item->type !== TdJson::NUMBER && $item->type !== TdJson::STRING) {
                throw QueryError::badRequest('User identifier must be a Number');
            }
            $id = TdInteger::toInt64Safe($item->string());
            if ($id === null) {
                throw QueryError::badRequest("Can't parse user identifier as a Number");
            }
            if ($id <= 0) {
                throw QueryError::badRequest('Invalid user identifier specified');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Text
    // ═════════════════════════════════════════════════════════════════

    /**
     * Client::get_input_entities: the parsed `entities` JSON, or a null value.
     */
    protected static function getInputEntities(Query $query, string $field): JsonValue
    {
        $arg = $query->arg($field);
        if ($arg !== '' && ($value = TdJson::tryDecode($arg)) !== null) {
            return $value;
        }

        return new JsonValue(TdJson::NULL, null);
    }

    /**
     * Client::get_formatted_text: parse_mode, or Bot API entities.
     */
    protected function getFormattedText(string $text, string $parseMode, JsonValue $inputEntities): FormattedText
    {
        if (strlen($text) > (1 << 15)) {
            throw QueryError::badRequest('Text is too long');
        }

        $parseMode = strtolower($parseMode);
        if ($text !== '' && $parseMode !== '' && $parseMode !== 'none') {
            if (!in_array($parseMode, ['markdown', 'markdownv2', 'html'], true)) {
                throw QueryError::badRequest('Unsupported parse_mode');
            }
            try {
                return EntityParser::parse($text, $parseMode);
            } catch (FormattingError $e) {
                throw QueryError::badRequest($e->getMessage());
            }
        }

        $entities = [];
        if ($inputEntities->isArray()) {
            foreach ($inputEntities->array() as $value) {
                try {
                    $entity = $this->getTextEntity($value);
                } catch (JsonError|QueryError $e) {
                    throw QueryError::badRequest("Can't parse MessageEntity: ".$e->getMessage());
                }
                if ($entity !== null) {
                    $entities[] = $entity;
                }
            }
        }

        return new FormattedText($text, $entities);
    }

    /**
     * Client::get_text_entity followed by td::get_message_entities for
     * td_api entities (user entities only).
     *
     * @return array<string, mixed>|null
     */
    protected function getTextEntity(JsonValue $value): ?array
    {
        if (!$value->isObject()) {
            throw new JsonError('expected an Object');
        }
        $object = $value->object();
        $offset = $object->getRequiredIntField('offset');
        $length = $object->getRequiredIntField('length');
        $type = $object->getRequiredStringField('type');
        if ($type === '') {
            throw new JsonError('Type is not specified');
        }

        $entity = ['offset' => $offset, 'length' => $length];
        switch ($type) {
            case 'bold': return $entity + ['type' => 'Bold'];
            case 'italic': return $entity + ['type' => 'Italic'];
            case 'underline': return $entity + ['type' => 'Underline'];
            case 'strikethrough': return $entity + ['type' => 'Strikethrough'];
            case 'spoiler': return $entity + ['type' => 'Spoiler'];
            case 'code': return $entity + ['type' => 'Code'];
            case 'blockquote': return $entity + ['type' => 'BlockQuote'];
            case 'expandable_blockquote': return $entity + ['type' => 'ExpandableBlockQuote'];
            case 'pre':
                $language = $object->getOptionalStringField('language');

                return $language === '' ? $entity + ['type' => 'Pre'] : $entity + ['type' => 'PreCode', 'language' => $language];
            case 'text_link':
                $url = $object->getRequiredStringField('url');
                $userId = TgLinks::userId($url);
                if ($userId > 0) {
                    return $entity + ['type' => 'MentionName', 'user_id' => $userId];
                }
                try {
                    $url = \LaraGram\Server\Support\LinkChecker::check($url);
                } catch (\InvalidArgumentException $e) {
                    throw new QueryError(400, 'Entity '.$e->getMessage());
                }

                return $entity + ['type' => 'TextUrl', 'url' => $url];
            case 'text_mention':
                $user = $object->extractRequiredField('user', TdJson::OBJECT);

                return $entity + ['type' => 'MentionName', 'user_id' => $user->object()->getRequiredLongField('id')];
            case 'custom_emoji':
                $id = $object->getRequiredLongField('custom_emoji_id');
                if ($id === 0) {
                    throw new QueryError(400, 'Invalid custom emoji identifier specified');
                }

                return $entity + ['type' => 'CustomEmoji', 'custom_emoji_id' => $id];
            case 'date_time':
                $unixTime = $object->getRequiredIntField('unix_time');
                $format = $object->getOptionalStringField('date_time_format');
                $flags = self::dateTimeFormatFlags($format);
                if ($unixTime <= 0) {
                    throw new QueryError(400, 'Invalid date specified');
                }

                return $entity + ['type' => 'FormattedDate', 'unix_time' => $unixTime, 'flags' => $flags];
            case 'mention': case 'hashtag': case 'cashtag': case 'bot_command': case 'url':
            case 'email': case 'phone_number': case 'bank_card_number':
                return null;
            default:
                throw new JsonError('Unsupported type specified');
        }
    }

    /**
     * Client::get_date_time_formatting_type → FormattedDate flags.
     */
    private static function dateTimeFormatFlags(string $format): int
    {
        if ($format === '') {
            return 0;
        }
        if ($format === 'r' || $format === 'R') {
            return FormattedDate::RELATIVE;
        }

        $flags = 0;
        for ($i = 0, $n = strlen($format); $i < $n; $i++) {
            $flags = match ($format[$i]) {
                't' => ($flags & ~FormattedDate::LONG_TIME) | FormattedDate::SHORT_TIME,
                'T' => ($flags & ~FormattedDate::SHORT_TIME) | FormattedDate::LONG_TIME,
                'd' => ($flags & ~FormattedDate::LONG_DATE) | FormattedDate::SHORT_DATE,
                'D' => ($flags & ~FormattedDate::SHORT_DATE) | FormattedDate::LONG_DATE,
                'w', 'W' => $flags | FormattedDate::DAY_OF_WEEK,
                default => throw new JsonError('Invalid date-time format specified'),
            };
        }

        return $flags;
    }

    protected function getCaption(Query $query): FormattedText
    {
        return $this->getFormattedText($query->arg('caption'), $query->arg('parse_mode'), self::getInputEntities($query, 'caption_entities'));
    }

    /**
     * Client::get_link_preview_options.
     *
     * @return array<string, mixed>|null td_api linkPreviewOptions
     */
    protected static function getLinkPreviewOptions(Query $query): ?array
    {
        $arg = $query->arg('link_preview_options');
        if ($arg === '') {
            return self::boolArg($query, 'disable_web_page_preview')
                ? ['is_disabled' => true, 'url' => '', 'force_small_media' => false, 'force_large_media' => false, 'show_above_text' => false]
                : null;
        }

        $value = self::jsonArg($arg, "Can't parse link preview options JSON object");

        return self::linkPreviewOptionsFromJson($value);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function linkPreviewOptionsFromJson(JsonValue $value): array
    {
        if (!$value->isObject()) {
            throw QueryError::badRequest('Object expected as link preview options');
        }
        $object = $value->object();

        return self::json(static fn (): array => [
            'is_disabled' => $object->getOptionalBoolField('is_disabled'),
            'url' => $object->getOptionalStringField('url'),
            'force_small_media' => $object->getOptionalBoolField('prefer_small_media'),
            'force_large_media' => $object->getOptionalBoolField('prefer_large_media'),
            'show_above_text' => $object->getOptionalBoolField('show_above_text'),
        ]);
    }

    /**
     * Client::get_input_message_text.
     *
     * @return array{text: FormattedText, link_preview_options: array<string, mixed>|null}
     */
    protected function getInputMessageText(Query $query): array
    {
        $linkPreviewOptions = self::getLinkPreviewOptions($query);
        $text = $query->arg('text');
        if ($text === '') {
            throw QueryError::badRequest('Message text is empty');
        }

        return [
            'text' => $this->getFormattedText($text, $query->arg('parse_mode'), self::getInputEntities($query, 'entities')),
            'link_preview_options' => $linkPreviewOptions,
        ];
    }

    // ═════════════════════════════════════════════════════════════════
    //  Reply parameters
    // ═════════════════════════════════════════════════════════════════

    /**
     * Client::get_reply_parameters.
     *
     * @return array<string, mixed>
     */
    protected function getReplyParameters(Query $query): array
    {
        $empty = [
            'reply_in_chat_id' => '', 'reply_to_message_id' => 0, 'reply_to_ephemeral_message_id' => 0,
            'allow_sending_without_reply' => false, 'quote' => null, 'quote_position' => 0,
            'checklist_task_id' => 0, 'poll_option_id' => '',
        ];

        if (!$query->hasArg('reply_parameters')) {
            return ['reply_to_message_id' => self::getMessageId($query, 'reply_to_message_id'),
                'allow_sending_without_reply' => self::boolArg($query, 'allow_sending_without_reply')] + $empty;
        }

        $arg = $query->arg('reply_parameters');
        if ($arg === '') {
            return $empty;
        }

        return $this->replyParametersFromJson(self::jsonArg($arg, "Can't parse reply parameters JSON object"), $empty);
    }

    /**
     * @param array<string, mixed> $empty
     * @return array<string, mixed>
     */
    protected function replyParametersFromJson(JsonValue $value, array $empty): array
    {
        if (!$value->isObject()) {
            throw QueryError::badRequest('Object expected as reply parameters');
        }
        $object = $value->object();
        if ($object->fieldCount() === 0) {
            return $empty;
        }

        return self::json(function () use ($object): array {
            $chatId = $object->getOptionalStringField('chat_id');
            $messageId = $object->getOptionalIntField('message_id');
            $allowSendingWithoutReply = $object->getOptionalBoolField('allow_sending_without_reply');
            $quoteText = $object->getOptionalStringField('quote');
            $parseMode = $object->getOptionalStringField('quote_parse_mode');
            $quote = $this->getFormattedText($quoteText, $parseMode, $object->extractField('quote_entities'));
            $quotePosition = $object->getOptionalIntField('quote_position');
            $checklistTaskId = $object->getOptionalIntField('checklist_task_id');
            $pollOptionId = $object->getOptionalStringField('poll_option_id');
            $ephemeralMessageId = $object->getOptionalIntField('ephemeral_message_id');

            return [
                'reply_in_chat_id' => $chatId,
                'reply_to_message_id' => max($messageId, 0),
                'reply_to_ephemeral_message_id' => max($ephemeralMessageId, 0),
                'allow_sending_without_reply' => $allowSendingWithoutReply,
                'quote' => $quote,
                'quote_position' => $quotePosition,
                'checklist_task_id' => $checklistTaskId,
                'poll_option_id' => $pollOptionId,
            ];
        });
    }

    // ═════════════════════════════════════════════════════════════════
    //  Reply markup
    // ═════════════════════════════════════════════════════════════════

    /**
     * Client::get_reply_markup: the td_api reply markup, or null.
     *
     * @return array<string, mixed>|null
     */
    protected function getReplyMarkup(Query $query, string $field = 'reply_markup'): ?array
    {
        $arg = $query->arg($field);
        if ($arg === '') {
            return null;
        }

        return $this->replyMarkupFromJson(self::jsonArg($arg, "Can't parse reply keyboard markup JSON object"));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function replyMarkupFromJson(JsonValue $value): ?array
    {
        if (!$value->isObject()) {
            throw QueryError::badRequest('Object expected as reply markup');
        }
        $object = $value->object();

        return self::json(function () use ($object): ?array {
            $rows = [];
            $keyboard = $object->extractOptionalField('keyboard', TdJson::ARRAY);
            if ($keyboard->isArray()) {
                foreach ($keyboard->array() as $row) {
                    $rows[] = $this->parseArray($row, 'KeyboardButton', fn (JsonValue $b): array => $this->getKeyboardButton($b));
                }
            }

            $inlineRows = [];
            $inlineKeyboard = $object->extractOptionalField('inline_keyboard', TdJson::ARRAY);
            if ($inlineKeyboard->isArray()) {
                foreach ($inlineKeyboard->array() as $row) {
                    $inlineRows[] = $this->parseArray($row, 'InlineKeyboardButton', fn (JsonValue $b): array => $this->getInlineKeyboardButton($b));
                }
            }

            $hideKeyboard = $object->getOptionalBoolField('hide_keyboard');
            $removeKeyboard = $object->getOptionalBoolField('remove_keyboard');
            $personalKeyboard = $object->getOptionalBoolField('personal_keyboard');
            $selective = $object->getOptionalBoolField('selective');
            $forceReplyKeyboard = $object->getOptionalBoolField('force_reply_keyboard');
            $forceReply = $object->getOptionalBoolField('force_reply');
            $placeholder = $object->getOptionalStringField('input_field_placeholder');
            $isPersonal = $personalKeyboard || $selective;

            if ($rows !== []) {
                return [
                    '_' => 'replyMarkupShowKeyboard',
                    'rows' => $rows,
                    'is_persistent' => $object->getOptionalBoolField('is_persistent'),
                    'resize_keyboard' => $object->getOptionalBoolField('resize_keyboard'),
                    'one_time' => $object->getOptionalBoolField('one_time_keyboard'),
                    'is_personal' => $isPersonal,
                    'force_reply' => $forceReply,
                    'input_field_placeholder' => $placeholder,
                ];
            }
            if ($inlineRows !== []) {
                return ['_' => 'replyMarkupInlineKeyboard', 'rows' => $inlineRows, 'force_reply' => $forceReply];
            }
            if ($hideKeyboard || $removeKeyboard) {
                return ['_' => 'replyMarkupRemoveKeyboard', 'is_personal' => $isPersonal];
            }
            if ($forceReply || $forceReplyKeyboard) {
                return ['_' => 'replyMarkupForceReply', 'is_personal' => $isPersonal, 'input_field_placeholder' => $placeholder];
            }

            return null;
        });
    }

    /**
     * Client::get_array: items parsed by $parse, errors prefixed with the class name.
     *
     * @return list<mixed>
     */
    protected function parseArray(JsonValue $values, string $className, callable $parse): array
    {
        if (!$values->isArray()) {
            if ($values->isNull()) {
                return [];
            }
            throw QueryError::badRequest("Expected an Array of {$className}");
        }

        $items = [];
        foreach ($values->array() as $value) {
            try {
                $items[] = $parse($value);
            } catch (JsonError|QueryError $e) {
                throw QueryError::badRequest("Can't parse {$className}: ".$e->getMessage());
            }
        }

        return $items;
    }

    /**
     * Client::get_button_style.
     */
    protected static function getButtonStyle(string $style, bool $forRichMessage = false): string
    {
        $style = strtolower($style);

        return match (true) {
            $style === '' || $style === 'default' => 'default',
            in_array($style, ['primary', 'danger', 'success'], true) => $style,
            $forRichMessage && $style === 'link' => 'link',
            default => throw new JsonError('Invalid button style specified'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function getKeyboardButton(JsonValue $button): array
    {
        if ($button->type === TdJson::STRING) {
            return ['text' => $button->string(), 'icon_custom_emoji_id' => 0, 'style' => 'default', 'type' => null];
        }
        if (!$button->isObject()) {
            throw new QueryError(400, 'KeyboardButton must be a String or an Object');
        }

        $object = $button->object();

        return [
            'text' => $object->getRequiredStringField('text'),
            'icon_custom_emoji_id' => $object->getOptionalLongField('icon_custom_emoji_id'),
            'style' => self::getButtonStyle($object->getOptionalStringField('style')),
            'type' => $this->getKeyboardButtonType($object),
        ];
    }

    /**
     * Client::get_keyboard_button_type.
     *
     * @return array<string, mixed>|null
     */
    protected function getKeyboardButtonType(JsonObject $object): ?array
    {
        $requestPhoneNumber = $object->getOptionalBoolField('request_phone_number');
        $requestContact = $object->getOptionalBoolField('request_contact');
        if ($requestPhoneNumber || $requestContact) {
            return ['_' => 'keyboardButtonTypeRequestPhoneNumber'];
        }
        if ($object->getOptionalBoolField('request_location')) {
            return ['_' => 'keyboardButtonTypeRequestLocation'];
        }
        if ($object->hasField('request_poll')) {
            $poll = $object->extractRequiredField('request_poll', TdJson::OBJECT)->object();
            $forceRegular = $forceQuiz = false;
            if ($poll->hasField('type')) {
                $type = $poll->getOptionalStringField('type');
                $forceQuiz = $type === 'quiz';
                $forceRegular = $type === 'regular';
            }

            return ['_' => 'keyboardButtonTypeRequestPoll', 'force_regular' => $forceRegular, 'force_quiz' => $forceQuiz];
        }
        if ($object->hasField('web_app')) {
            $webApp = $object->extractRequiredField('web_app', TdJson::OBJECT)->object();

            return ['_' => 'keyboardButtonTypeWebApp', 'url' => $webApp->getRequiredStringField('url')];
        }
        if ($object->hasField('request_user') || $object->hasField('request_users')) {
            $request = $object->hasField('request_users')
                ? $object->extractRequiredField('request_users', TdJson::OBJECT)->object()
                : $object->extractRequiredField('request_user', TdJson::OBJECT)->object();

            return [
                '_' => 'keyboardButtonTypeRequestUsers',
                'id' => $request->getRequiredIntField('request_id'),
                'restrict_user_is_bot' => $request->hasField('user_is_bot'),
                'user_is_bot' => $request->getOptionalBoolField('user_is_bot'),
                'restrict_user_is_premium' => $request->hasField('user_is_premium'),
                'user_is_premium' => $request->getOptionalBoolField('user_is_premium'),
                'max_quantity' => $request->getOptionalIntField('max_quantity', 1),
                'request_name' => $request->getOptionalBoolField('request_name'),
                'request_username' => $request->getOptionalBoolField('request_username'),
                'request_photo' => $request->getOptionalBoolField('request_photo'),
            ];
        }
        if ($object->hasField('request_chat')) {
            $request = $object->extractRequiredField('request_chat', TdJson::OBJECT)->object();
            $result = [
                '_' => 'keyboardButtonTypeRequestChat',
                'id' => $request->getRequiredIntField('request_id'),
                'chat_is_channel' => $request->getOptionalBoolField('chat_is_channel'),
                'restrict_chat_is_forum' => $request->hasField('chat_is_forum'),
                'chat_is_forum' => $request->getOptionalBoolField('chat_is_forum'),
                'restrict_chat_has_username' => $request->hasField('chat_has_username'),
                'chat_has_username' => $request->getOptionalBoolField('chat_has_username'),
                'chat_is_created' => $request->getOptionalBoolField('chat_is_created'),
                'user_administrator_rights' => null,
                'bot_administrator_rights' => null,
            ];
            if ($request->hasField('user_administrator_rights')) {
                $result['user_administrator_rights'] = $this->chatAdministratorRightsFromJson($request->extractField('user_administrator_rights'));
            }
            if ($request->hasField('bot_administrator_rights')) {
                $result['bot_administrator_rights'] = $this->chatAdministratorRightsFromJson($request->extractField('bot_administrator_rights'));
            }
            $result['bot_is_member'] = $request->getOptionalBoolField('bot_is_member');
            $result['request_title'] = $request->getOptionalBoolField('request_title');
            $result['request_username'] = $request->getOptionalBoolField('request_username');
            $result['request_photo'] = $request->getOptionalBoolField('request_photo');

            return $result;
        }
        if ($object->hasField('request_managed_bot')) {
            $request = $object->extractRequiredField('request_managed_bot', TdJson::OBJECT)->object();

            return [
                '_' => 'keyboardButtonTypeRequestManagedBot',
                'id' => $request->getRequiredIntField('request_id'),
                'suggested_name' => $request->getOptionalStringField('suggested_name'),
                'suggested_username' => $request->getOptionalStringField('suggested_username'),
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getInlineKeyboardButton(JsonValue $button): array
    {
        if (!$button->isObject()) {
            throw new QueryError(400, 'InlineKeyboardButton must be an Object');
        }
        $object = $button->object();

        return [
            'text' => $object->getRequiredStringField('text'),
            'icon_custom_emoji_id' => $object->getOptionalLongField('icon_custom_emoji_id'),
            'style' => self::getButtonStyle($object->getOptionalStringField('style')),
            'type' => $this->getInlineKeyboardButtonType($object, true),
        ];
    }

    /**
     * Client::get_inline_keyboard_button_type.
     *
     * @return array<string, mixed>
     */
    protected function getInlineKeyboardButtonType(JsonObject $object, bool $allowBotUsername): array
    {
        $url = $object->getOptionalStringField('url');
        if ($url !== '') {
            return ['_' => 'inlineKeyboardButtonTypeUrl', 'url' => $url];
        }
        $callbackData = $object->getOptionalStringField('callback_data');
        if ($callbackData !== '') {
            return ['_' => 'inlineKeyboardButtonTypeCallback', 'data' => $callbackData];
        }
        if ($object->hasField('callback_game')) {
            return ['_' => 'inlineKeyboardButtonTypeCallbackGame'];
        }
        if ($object->hasField('pay')) {
            return ['_' => 'inlineKeyboardButtonTypeBuy'];
        }
        if ($object->hasField('switch_inline_query')) {
            return ['_' => 'inlineKeyboardButtonTypeSwitchInline', 'query' => $object->getRequiredStringField('switch_inline_query'),
                'target' => ['users' => true, 'bots' => true, 'groups' => true, 'channels' => true]];
        }
        if ($object->hasField('switch_inline_query_chosen_chat')) {
            $chosen = $object->extractRequiredField('switch_inline_query_chosen_chat', TdJson::OBJECT)->object();

            return ['_' => 'inlineKeyboardButtonTypeSwitchInline', 'query' => $chosen->getOptionalStringField('query'), 'target' => [
                'users' => $chosen->getOptionalBoolField('allow_user_chats'),
                'bots' => $chosen->getOptionalBoolField('allow_bot_chats'),
                'groups' => $chosen->getOptionalBoolField('allow_group_chats'),
                'channels' => $chosen->getOptionalBoolField('allow_channel_chats'),
            ]];
        }
        if ($object->hasField('switch_inline_query_current_chat')) {
            return ['_' => 'inlineKeyboardButtonTypeSwitchInline', 'query' => $object->getRequiredStringField('switch_inline_query_current_chat'), 'target' => null];
        }
        if ($object->hasField('login_url')) {
            $login = $object->extractRequiredField('login_url', TdJson::OBJECT)->object();
            $loginUrl = $login->getRequiredStringField('url');
            $botUsername = $login->getOptionalStringField('bot_username');
            $requestWriteAccess = $login->getOptionalBoolField('request_write_access');
            $forwardText = $login->getOptionalStringField('forward_text');
            if ($botUsername !== '') {
                if (!$allowBotUsername) {
                    throw new QueryError(400, 'Bot username must be empty for login_url buttons in rich messages');
                }
                if ($botUsername[0] === '@') {
                    $botUsername = substr($botUsername, 1);
                }
                if ($botUsername === '' || strspn($botUsername, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_') !== strlen($botUsername)) {
                    throw new QueryError(400, 'LoginUrl bot username is invalid');
                }
            }

            return ['_' => 'inlineKeyboardButtonTypeLoginUrl', 'url' => $loginUrl, 'bot_username' => $botUsername,
                'request_write_access' => $requestWriteAccess, 'forward_text' => $forwardText];
        }
        if ($object->hasField('web_app')) {
            $webApp = $object->extractRequiredField('web_app', TdJson::OBJECT)->object();

            return ['_' => 'inlineKeyboardButtonTypeWebApp', 'url' => $webApp->getRequiredStringField('url')];
        }
        if ($object->hasField('copy_text')) {
            $copy = $object->extractRequiredField('copy_text', TdJson::OBJECT)->object();

            return ['_' => 'inlineKeyboardButtonTypeCopyText', 'text' => $copy->getRequiredStringField('text')];
        }
        if ($object->hasField('disabled')) {
            return ['_' => 'inlineKeyboardButtonTypeDisabled'];
        }

        throw new QueryError(400, 'Text buttons are not allowed in the inline keyboard');
    }

    /**
     * Client::get_chat_administrator_rights (JSON object).
     *
     * @return array<string, bool>
     */
    protected function chatAdministratorRightsFromJson(JsonValue $value): array
    {
        if (!$value->isObject()) {
            throw QueryError::badRequest('ChatAdministratorRights must be an Object');
        }
        $o = $value->object();

        return self::json(static fn (): array => [
            'can_manage_chat' => $o->getOptionalBoolField('can_manage_chat'),
            'can_change_info' => $o->getOptionalBoolField('can_change_info'),
            'can_post_messages' => $o->getOptionalBoolField('can_post_messages'),
            'can_edit_messages' => $o->getOptionalBoolField('can_edit_messages'),
            'can_delete_messages' => $o->getOptionalBoolField('can_delete_messages'),
            'can_invite_users' => $o->getOptionalBoolField('can_invite_users'),
            'can_restrict_members' => $o->getOptionalBoolField('can_restrict_members'),
            'can_pin_messages' => $o->getOptionalBoolField('can_pin_messages'),
            'can_manage_topics' => $o->getOptionalBoolField('can_manage_topics'),
            'can_promote_members' => $o->getOptionalBoolField('can_promote_members'),
            'can_manage_video_chats' => $o->getOptionalBoolField('can_manage_video_chats'),
            'can_post_stories' => $o->getOptionalBoolField('can_post_stories'),
            'can_edit_stories' => $o->getOptionalBoolField('can_edit_stories'),
            'can_delete_stories' => $o->getOptionalBoolField('can_delete_stories'),
            'can_manage_direct_messages' => $o->getOptionalBoolField('can_manage_direct_messages'),
            'can_manage_tags' => $o->getOptionalBoolField('can_manage_tags'),
            'can_send_welcome_messages' => $o->getOptionalBoolField('can_send_welcome_messages'),
            'is_anonymous' => $o->getOptionalBoolField('is_anonymous'),
        ]);
    }
}
