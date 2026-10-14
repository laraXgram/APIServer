<?php

declare(strict_types=1);

namespace LaraGram\Server\Entities;

/**
 * td::MessageEntity::Type: order, sort priority and the groups the entity
 * fixing rules use. Entities are arrays: ['type' => name, 'offset', 'length', ...].
 */
final class EntityType
{
    /** name => index, in TDLib order. */
    public const INDEX = [
        'Mention' => 0, 'Hashtag' => 1, 'BotCommand' => 2, 'Url' => 3, 'EmailAddress' => 4, 'Bold' => 5,
        'Italic' => 6, 'Code' => 7, 'Pre' => 8, 'PreCode' => 9, 'TextUrl' => 10, 'MentionName' => 11,
        'Cashtag' => 12, 'PhoneNumber' => 13, 'Underline' => 14, 'Strikethrough' => 15, 'BlockQuote' => 16,
        'BankCardNumber' => 17, 'MediaTimestamp' => 18, 'Spoiler' => 19, 'CustomEmoji' => 20,
        'ExpandableBlockQuote' => 21, 'FormattedDate' => 22,
    ];

    /** MessageEntity::get_type_priority */
    public const PRIORITY = [
        'Mention' => 50, 'Hashtag' => 50, 'BotCommand' => 50, 'Url' => 50, 'EmailAddress' => 50, 'Bold' => 90,
        'Italic' => 91, 'Code' => 20, 'Pre' => 11, 'PreCode' => 10, 'TextUrl' => 49, 'MentionName' => 49,
        'Cashtag' => 50, 'PhoneNumber' => 50, 'Underline' => 92, 'Strikethrough' => 93, 'BlockQuote' => 0,
        'BankCardNumber' => 50, 'MediaTimestamp' => 50, 'Spoiler' => 94, 'CustomEmoji' => 99,
        'ExpandableBlockQuote' => 0, 'FormattedDate' => 30,
    ];

    public const SPLITTABLE = ['Bold' => 0, 'Italic' => 1, 'Underline' => 2, 'Strikethrough' => 3, 'Spoiler' => 4];

    public const BLOCKQUOTE = ['BlockQuote' => true, 'ExpandableBlockQuote' => true];

    public const CONTINUOUS = [
        'Mention' => true, 'Hashtag' => true, 'BotCommand' => true, 'Url' => true, 'EmailAddress' => true,
        'TextUrl' => true, 'MentionName' => true, 'Cashtag' => true, 'PhoneNumber' => true,
        'BankCardNumber' => true, 'MediaTimestamp' => true, 'CustomEmoji' => true,
    ];

    public const PRE = ['Pre' => true, 'Code' => true, 'PreCode' => true, 'FormattedDate' => true];

    public static function mask(string $type): int
    {
        return 1 << self::INDEX[$type];
    }

    public static function maskOf(array $types): int
    {
        $mask = 0;
        foreach ($types as $type => $_) {
            $mask |= 1 << self::INDEX[$type];
        }

        return $mask;
    }

    /**
     * MessageEntity::operator<.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function compare(array $a, array $b): int
    {
        return [$a['offset'], -$a['length'], self::PRIORITY[$a['type']]] <=> [$b['offset'], -$b['length'], self::PRIORITY[$b['type']]];
    }

    /**
     * sort_entities: stable for equal keys (std::sort makes no promise there).
     *
     * @param list<array<string, mixed>> $entities
     */
    public static function sort(array &$entities): void
    {
        $n = count($entities);
        for ($i = 1; $i < $n; $i++) {
            if (self::compare($entities[$i - 1], $entities[$i]) > 0) {
                usort($entities, self::compare(...));

                return;
            }
        }
    }
}
