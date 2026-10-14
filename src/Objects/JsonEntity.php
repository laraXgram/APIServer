<?php

declare(strict_types=1);

namespace LaraGram\Server\Objects;

use LaraGram\Server\Bot\Client;
use LaraGram\Server\Entities\FormattedDate;

/**
 * The Bot API `MessageEntity` (Client::JsonEntity / JsonVectorEntities).
 */
final class JsonEntity
{
    private const TYPES = [
        'Mention' => 'mention', 'Hashtag' => 'hashtag', 'Cashtag' => 'cashtag', 'BotCommand' => 'bot_command',
        'Url' => 'url', 'EmailAddress' => 'email', 'PhoneNumber' => 'phone_number', 'BankCardNumber' => 'bank_card_number',
        'Bold' => 'bold', 'Italic' => 'italic', 'Underline' => 'underline', 'Strikethrough' => 'strikethrough',
        'Spoiler' => 'spoiler', 'Code' => 'code', 'Pre' => 'pre', 'BlockQuote' => 'blockquote',
        'ExpandableBlockQuote' => 'expandable_blockquote',
    ];

    /**
     * JsonVectorEntities: bank card numbers and media timestamps are not exposed.
     *
     * @param list<array<string, mixed>> $entities
     * @return list<array<string, mixed>>
     */
    public static function vector(array $entities, Client $client): array
    {
        $result = [];
        foreach ($entities as $entity) {
            if ($entity['type'] !== 'BankCardNumber' && $entity['type'] !== 'MediaTimestamp') {
                $result[] = self::make($entity, $client);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $entity
     * @return array<string, mixed>
     */
    public static function make(array $entity, Client $client): array
    {
        $object = ['offset' => $entity['offset'], 'length' => $entity['length']];

        switch ($entity['type']) {
            case 'PreCode':
                $object['type'] = 'pre';
                $object['language'] = $entity['language'];
                break;
            case 'TextUrl':
                $object['type'] = 'text_link';
                $object['url'] = $entity['url'];
                break;
            case 'MentionName':
                $object['type'] = 'text_mention';
                $object['user'] = JsonUser::make($entity['user_id'], $client);
                break;
            case 'CustomEmoji':
                $object['type'] = 'custom_emoji';
                $object['custom_emoji_id'] = (string) $entity['custom_emoji_id'];
                break;
            case 'FormattedDate':
                $object['type'] = 'date_time';
                $object['unix_time'] = $entity['unix_time'];
                $object['date_time_format'] = FormattedDate::botApiFormat($entity['flags']);
                break;
            default:
                $object['type'] = self::TYPES[$entity['type']];
        }

        return $object;
    }
}
