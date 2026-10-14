<?php

declare(strict_types=1);

namespace LaraGram\Server\Objects;

use LaraGram\Server\Bot\Client;
use LaraGram\Server\Entities\FormattedText;

/**
 * The Bot API `Poll` object (Client::JsonPoll) and its parts.
 */
final class JsonPoll
{
    /**
     * @param array<string, mixed> $poll td_api poll
     * @param array<string, mixed>|null $media td_api PollMedia
     * @return array<string, mixed>
     */
    public static function make(array $poll, ?FormattedText $description, ?array $media, Client $client): array
    {
        $object = ['id' => (string) $poll['id'], 'question' => $poll['question']->text];
        if ($poll['question']->entities !== []) {
            $object['question_entities'] = JsonEntity::vector($poll['question']->entities, $client);
        }
        $object['options'] = array_map(static fn (array $option): array => self::option($option, $client), $poll['options']);
        $object['total_voter_count'] = $poll['total_voter_count'];
        if ($poll['open_period'] !== 0 && $poll['close_date'] !== 0) {
            $object['open_period'] = $poll['open_period'];
            $object['close_date'] = $poll['close_date'];
        }
        $object['is_closed'] = $poll['is_closed'];
        $object['is_anonymous'] = $poll['is_anonymous'];
        $object['allows_multiple_answers'] = $poll['allows_multiple_answers'];
        $object['allows_revoting'] = $poll['allows_revoting'];
        $object['members_only'] = $poll['members_only'];
        if ($poll['country_codes'] !== []) {
            $object['country_codes'] = $poll['country_codes'];
        }
        $type = $poll['type'];
        if ($type['_'] === 'pollTypeQuiz') {
            $object['type'] = 'quiz';
            if (count($type['correct_option_ids']) === 1) {
                $object['correct_option_id'] = $type['correct_option_ids'][0];
            }
            if ($type['correct_option_ids'] !== []) {
                $object['correct_option_ids'] = $type['correct_option_ids'];
            }
            if ($type['explanation']->text !== '') {
                $object['explanation'] = $type['explanation']->text;
                $object['explanation_entities'] = JsonEntity::vector($type['explanation']->entities, $client);
            }
            if ($type['explanation_media'] !== null) {
                $object['explanation_media'] = self::media($type['explanation_media'], $client);
            }
        } else {
            $object['type'] = 'regular';
        }
        if ($description !== null && $description->text !== '') {
            $object['description'] = $description->text;
            if ($description->entities !== []) {
                $object['description_entities'] = JsonEntity::vector($description->entities, $client);
            }
        }
        if ($media !== null) {
            $object['media'] = self::media($media, $client);
        }

        return $object;
    }

    /**
     * Client::JsonPollOption.
     *
     * @param array<string, mixed> $option td_api pollOption
     * @return array<string, mixed>
     */
    public static function option(array $option, Client $client): array
    {
        $object = ['persistent_id' => $option['id'], 'text' => $option['text']->text];
        if ($option['text']->entities !== []) {
            $object['text_entities'] = JsonEntity::vector($option['text']->entities, $client);
        }
        if ($option['media'] !== null) {
            $object['media'] = self::media($option['media'], $client);
        }
        $object['voter_count'] = $option['voter_count'];
        if ($option['author'] !== null && $option['addition_date'] > 0) {
            self::storeMessageSender($object, $option['author'], 'added_by_user', 'added_by_chat', 0, $client);
            $object['addition_date'] = $option['addition_date'];
        }

        return $object;
    }

    /**
     * Client::JsonPollMedia.
     *
     * @param array<string, mixed> $media td_api PollMedia
     * @return array<string, mixed>
     */
    public static function media(array $media, Client $client): array
    {
        return match ($media['_']) {
            'pollMediaAnimation' => ['animation' => JsonMedia::animation($media['animation'], false)],
            'pollMediaAudio' => ['audio' => JsonMedia::audio($media['audio'])],
            'pollMediaDocument' => ['document' => JsonMedia::document($media['document'])],
            'pollMediaLink' => ['link' => ['url' => $media['url']]],
            'pollMediaLocation' => ['location' => JsonMedia::location($media['location'])],
            'pollMediaPhoto' => $media['video'] !== null
                ? ['live_photo' => JsonMedia::livePhoto($media['photo'], $media['video'])]
                : ['photo' => JsonMedia::photo($media['photo'])],
            'pollMediaSticker' => ['sticker' => JsonSticker::make($media['sticker'], $client)],
            'pollMediaVenue' => ['venue' => JsonMedia::venue($media['venue'])],
            'pollMediaVideo' => ['video' => JsonMedia::video($media['video'], $media['cover'], $media['start_timestamp'], $media['alternative_videos'])],
        };
    }

    /**
     * Client::JsonPollAnswer.
     *
     * @param array<string, mixed> $answer td_api updatePollAnswer
     * @return array<string, mixed>
     */
    public static function answer(array $answer, Client $client): array
    {
        $object = ['poll_id' => (string) $answer['poll_id']];
        self::storeMessageSender($object, $answer['voter_id'], 'user', 'voter_chat', $client->getChannelBotUserId(), $client);
        $object['option_ids'] = $answer['option_positions'];
        $object['option_persistent_ids'] = $answer['option_ids'];

        return $object;
    }

    /**
     * Client::json_store_message_sender.
     *
     * @param array<string, mixed> $object
     * @param array<string, mixed> $sender td_api MessageSender
     */
    public static function storeMessageSender(array &$object, array $sender, string $userField, string $chatField, int $backwardCompatibilityUserId, Client $client): void
    {
        if ($sender['_'] === 'messageSenderUser') {
            $object[$userField] = JsonUser::make((int) $sender['user_id'], $client);

            return;
        }
        if ($backwardCompatibilityUserId > 0) {
            $object[$userField] = JsonUser::make($backwardCompatibilityUserId, $client);
        }
        $object[$chatField] = JsonChat::make((int) $sender['chat_id'], $client);
    }
}
