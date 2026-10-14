<?php

declare(strict_types=1);

namespace LaraGram\Server\Entities;

use LaraGram\Server\Support\LinkChecker;

/**
 * Text with entities as TDLib keeps it (td::FormattedText). Entities are
 * arrays: ['type' => td type name, 'offset' => int, 'length' => int, ...extra].
 *
 * Offsets and lengths are UTF-16 code units, as on the wire.
 */
final class FormattedText
{
    /** is_user_entity: the entity types a client sends to the server. */
    public const USER_ENTITIES = [
        'Bold' => true, 'Italic' => true, 'Underline' => true, 'Strikethrough' => true, 'BlockQuote' => true,
        'Code' => true, 'Pre' => true, 'PreCode' => true, 'TextUrl' => true, 'MentionName' => true,
        'Spoiler' => true, 'CustomEmoji' => true, 'ExpandableBlockQuote' => true, 'FormattedDate' => true,
    ];

    private const MAX_USER_ENTITY_COUNT = 100;

    /**
     * @param list<array<string, mixed>> $entities
     */
    public function __construct(
        public string $text = '',
        public array $entities = [],
    ) {
    }

    /**
     * td::get_message_text for a message received by a bot: the server
     * entities, fixed and trimmed; no new entities are searched for.
     *
     * @param list<array<string, mixed>> $serverEntities TL MessageEntity objects
     */
    public static function fromServer(string $text, array $serverEntities, bool $skipNewEntities = true, bool $skipMediaTimestamps = true): self
    {
        $entities = self::convertServerEntities($serverEntities);

        try {
            [$text, $entities] = TextFormatting::fixFormattedText($text, $entities, true, false, $skipNewEntities, true, $skipMediaTimestamps, false);
        } catch (FormattingError) {
            $text = TextFormatting::cleanInputString($text) ?? '';
            $entities = EntityFinder::find($text, false, $skipMediaTimestamps);
        }

        return new self($text, $entities);
    }

    /**
     * td::get_formatted_text(user_manager, text, server_entities, skip_media_timestamps, skip_trim):
     * text from the server outside of message texts (poll questions, explanations, …).
     *
     * @param list<array<string, mixed>> $serverEntities
     */
    public static function fromServerText(string $text, array $serverEntities, bool $skipMediaTimestamps, bool $skipTrim): self
    {
        $entities = self::convertServerEntities($serverEntities);

        try {
            [$text, $entities] = TextFormatting::fixFormattedText($text, $entities, true, false, true, true, $skipMediaTimestamps, $skipTrim);
        } catch (FormattingError) {
            $text = TextFormatting::cleanInputString($text) ?? '';
            $entities = EntityFinder::find($text, true, $skipMediaTimestamps);
        }

        return new self($text, $entities);
    }

    /**
     * td::get_formatted_text of a TL textWithEntities.
     *
     * @param array<string, mixed>|null $textWithEntities
     */
    public static function fromTextWithEntities(?array $textWithEntities, bool $skipMediaTimestamps, bool $skipTrim): self
    {
        if ($textWithEntities === null) {
            return new self();
        }

        return self::fromServerText((string) ($textWithEntities['text'] ?? ''), $textWithEntities['entities'] ?? [], $skipMediaTimestamps, $skipTrim);
    }

    private const QUOTE_ENTITIES = [
        'Bold' => true, 'Italic' => true, 'Underline' => true, 'Strikethrough' => true, 'Spoiler' => true,
        'CustomEmoji' => true, 'FormattedDate' => true,
    ];

    /**
     * td::remove_unallowed_quote_entities.
     */
    public function removeUnallowedQuoteEntities(): self
    {
        return new self($this->text, array_values(array_filter($this->entities, static fn (array $entity): bool => isset(self::QUOTE_ENTITIES[$entity['type']]))));
    }

    /**
     * td::remove_unallowed_quote_user_entities: quote entities and the automatically found ones are kept.
     */
    public function removeUnallowedQuoteUserEntities(bool $skipBotCommands, bool $skipMediaTimestamps): self
    {
        return new self($this->text, array_values(array_filter($this->entities, static fn (array $entity): bool => isset(self::QUOTE_ENTITIES[$entity['type']])
            || in_array($entity['type'], ['Mention', 'Hashtag', 'Cashtag', 'PhoneNumber', 'BankCardNumber', 'Url', 'EmailAddress'], true)
            || ($entity['type'] === 'BotCommand' && !$skipBotCommands)
            || ($entity['type'] === 'MediaTimestamp' && !$skipMediaTimestamps))));
    }

    /**
     * td::keep_only_custom_emoji.
     */
    public function keepOnlyCustomEmoji(): self
    {
        return new self($this->text, array_values(array_filter($this->entities, static fn (array $entity): bool => $entity['type'] === 'CustomEmoji')));
    }

    /**
     * td::get_message_entities(user_manager, vector<telegram_api::MessageEntity>).
     *
     * @param list<array<string, mixed>> $serverEntities
     * @return list<array<string, mixed>>
     */
    public static function convertServerEntities(array $serverEntities): array
    {
        $entities = [];

        foreach ($serverEntities as $entity) {
            $offset = (int) ($entity['offset'] ?? 0);
            $length = (int) ($entity['length'] ?? 0);

            $converted = match ($entity['_'] ?? '') {
                'messageEntityMention' => ['type' => 'Mention'],
                'messageEntityHashtag' => ['type' => 'Hashtag'],
                'messageEntityCashtag' => ['type' => 'Cashtag'],
                'messageEntityPhone' => ['type' => 'PhoneNumber'],
                'messageEntityBotCommand' => ['type' => 'BotCommand'],
                'messageEntityBankCard' => ['type' => 'BankCardNumber'],
                'messageEntityUrl' => ['type' => 'Url'],
                'messageEntityEmail' => ['type' => 'EmailAddress'],
                'messageEntityBold' => ['type' => 'Bold'],
                'messageEntityItalic' => ['type' => 'Italic'],
                'messageEntityUnderline' => ['type' => 'Underline'],
                'messageEntityStrike' => ['type' => 'Strikethrough'],
                'messageEntitySpoiler' => ['type' => 'Spoiler'],
                'messageEntityBlockquote' => ['type' => !empty($entity['collapsed']) ? 'ExpandableBlockQuote' : 'BlockQuote'],
                'messageEntityCode' => ['type' => 'Code'],
                'messageEntityPre' => ($entity['language'] ?? '') === ''
                    ? ['type' => 'Pre']
                    : ['type' => 'PreCode', 'language' => (string) $entity['language']],
                'messageEntityTextUrl' => ($url = LinkChecker::getCheckedLink((string) ($entity['url'] ?? ''))) === '' ? null : ['type' => 'TextUrl', 'url' => $url],
                'messageEntityMentionName' => ((int) ($entity['user_id'] ?? 0)) > 0
                    ? ['type' => 'MentionName', 'user_id' => (int) $entity['user_id']]
                    : null,
                'messageEntityCustomEmoji' => ['type' => 'CustomEmoji', 'custom_emoji_id' => (int) ($entity['document_id'] ?? 0)],
                'messageEntityFormattedDate' => ((int) ($entity['date'] ?? 0)) > 0 ? [
                    'type' => 'FormattedDate',
                    'unix_time' => (int) $entity['date'],
                    'flags' => FormattedDate::flagsFromServer($entity),
                ] : null,
                default => null,
            };

            if ($converted === null) {
                continue;
            }

            $entities[] = ['offset' => $offset, 'length' => $length] + $converted;
        }

        return $entities;
    }

    /**
     * td::get_formatted_text for a bot's outgoing text: the user entities
     * are fixed and the automatic ones (except media timestamps) searched for.
     *
     * @throws FormattingError
     */
    public function fixOutgoing(bool $allowEmpty, bool $skipTrim = false): self
    {
        [$text, $entities] = TextFormatting::fixFormattedText($this->text, $this->entities, $allowEmpty, $allowEmpty, false, false, true, $skipTrim);

        return new self($text, $entities);
    }

    /**
     * td::get_input_message_entities: the TL entities sent to the server,
     * user entities only, at most 100 besides custom emoji.
     *
     * @param callable(int): array<string, mixed> $inputUser
     * @return list<array<string, mixed>>
     */
    public function inputEntities(callable $inputUser): array
    {
        $result = [];
        $splittable = [];
        $count = 0;

        foreach ($this->entities as $entity) {
            $type = $entity['type'];
            if (!isset(self::USER_ENTITIES[$type])) {
                continue;
            }
            if (isset(EntityType::SPLITTABLE[$type])) {
                $splittable[] = $entity;
                continue;
            }
            $offset = $entity['offset'];
            $length = $entity['length'];
            if ($type === 'CustomEmoji') {
                $result[] = ['_' => 'messageEntityCustomEmoji', 'offset' => $offset, 'length' => $length, 'document_id' => $entity['custom_emoji_id']];
                continue;
            }
            if ($count >= self::MAX_USER_ENTITY_COUNT) {
                continue;
            }
            $count++;
            $result[] = match ($type) {
                'BlockQuote' => ['_' => 'messageEntityBlockquote', 'offset' => $offset, 'length' => $length],
                'ExpandableBlockQuote' => ['_' => 'messageEntityBlockquote', 'collapsed' => true, 'offset' => $offset, 'length' => $length],
                'Code' => ['_' => 'messageEntityCode', 'offset' => $offset, 'length' => $length],
                'Pre' => ['_' => 'messageEntityPre', 'offset' => $offset, 'length' => $length, 'language' => ''],
                'PreCode' => ['_' => 'messageEntityPre', 'offset' => $offset, 'length' => $length, 'language' => $entity['language']],
                'TextUrl' => ['_' => 'messageEntityTextUrl', 'offset' => $offset, 'length' => $length, 'url' => $entity['url']],
                'MentionName' => ['_' => 'inputMessageEntityMentionName', 'offset' => $offset, 'length' => $length, 'user_id' => $inputUser((int) $entity['user_id'])],
                'FormattedDate' => ['_' => 'messageEntityFormattedDate'] + FormattedDate::toServer((int) $entity['flags'])
                    + ['offset' => $offset, 'length' => $length, 'date' => $entity['unix_time']],
            };
        }

        foreach (TextFormatting::splitEntities($splittable, []) as $entity) {
            if ($count >= self::MAX_USER_ENTITY_COUNT) {
                break;
            }
            $count++;
            $result[] = [
                '_' => match ($entity['type']) {
                    'Bold' => 'messageEntityBold',
                    'Italic' => 'messageEntityItalic',
                    'Underline' => 'messageEntityUnderline',
                    'Strikethrough' => 'messageEntityStrike',
                    'Spoiler' => 'messageEntitySpoiler',
                },
                'offset' => $entity['offset'],
                'length' => $entity['length'],
            ];
        }

        return $result;
    }
}
