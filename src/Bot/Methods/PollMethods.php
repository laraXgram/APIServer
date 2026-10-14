<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\FormattedText;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Messages\MessageConverter;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Objects\JsonMessage;
use LaraGram\Server\Objects\JsonPoll;
use LaraGram\Server\Support\JsonError;
use LaraGram\Server\Support\JsonObject;
use LaraGram\Server\Support\JsonValue;
use LaraGram\Server\Support\TdInteger;
use LaraGram\Server\Support\TdJson;
use LaraGram\Server\Support\Utf8;

/**
 * sendPoll, stopPoll, sendChecklist and editMessageChecklist, with the TDLib
 * parts they rely on (inputMessagePoll, inputMessageChecklist, stop_poll).
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait PollMethods
{
    /** MAX_POLL_QUESTION_LENGTH for bots (server-side limit). */
    private const MAX_POLL_QUESTION_LENGTH = 300;

    /** MAX_POLL_OPTION_LENGTH (server-side limit). */
    private const MAX_POLL_OPTION_LENGTH = 100;

    // ═════════════════════════════════════════════════════════════════
    //  Bot API side
    // ═════════════════════════════════════════════════════════════════

    public function processSendPollQuery(Query $query): void
    {
        $question = $this->getFormattedText($query->arg('question'), $query->arg('question_parse_mode'), self::getInputEntities($query, 'question_entities'));
        $options = $this->getInputPollOptions($query);
        $description = $this->getFormattedText($query->arg('description'), $query->arg('description_parse_mode'), self::getInputEntities($query, 'description_entities'));
        $isAnonymous = true;
        if ($query->hasArg('is_anonymous')) {
            $isAnonymous = self::boolArg($query, 'is_anonymous');
        }

        $allowsMultipleAnswers = self::boolArg($query, 'allows_multiple_answers');
        $type = $query->arg('type');
        if ($type === 'quiz') {
            $explanation = $this->getFormattedText($query->arg('explanation'), $query->arg('explanation_parse_mode'), self::getInputEntities($query, 'explanation_entities'));
            $explanationMedia = $this->getInputPollMediaArg($query, 'explanation_media');
            $correctOptionIds = [];
            if ($query->hasArg('correct_option_ids')) {
                $value = self::jsonArg($query->arg('correct_option_ids'), 'Can\'t parse correct option identifiers JSON object');
                if (!$value->isArray()) {
                    throw QueryError::badRequest('Expected an Array of correct option identifiers');
                }
                foreach ($value->array() as $item) {
                    if ($item->type !== TdJson::NUMBER) {
                        throw QueryError::badRequest('Correct option identifier must be of type Number');
                    }
                    $parsed = TdInteger::toInt32Safe($item->string());
                    if ($parsed === null) {
                        throw QueryError::badRequest('Invalid correct option identifier specified');
                    }
                    $correctOptionIds[] = $parsed;
                }
            } elseif ($query->hasArg('correct_option_id')) {
                $correctOptionIds[] = self::getIntegerArg($query, 'correct_option_id', -1);
            }

            $pollType = [
                '_' => 'inputPollTypeQuiz',
                'correct_option_ids' => $correctOptionIds,
                'explanation' => $explanation,
                'explanation_media' => $explanationMedia,
            ];
        } elseif ($type === '' || $type === 'regular') {
            $pollType = ['_' => 'inputPollTypeRegular', 'allow_adding_options' => self::boolArg($query, 'allow_adding_options')];
        } else {
            throw QueryError::badRequest('Unsupported poll type specified');
        }
        $allowsRevoting = $type !== 'quiz';
        if ($query->hasArg('allows_revoting')) {
            $allowsRevoting = self::boolArg($query, 'allows_revoting');
        }
        $openPeriod = self::getIntegerArg($query, 'open_period', 0, 0, 2628000);
        $closeDate = self::getIntegerArg($query, 'close_date', 0);
        $isClosed = self::boolArg($query, 'is_closed');
        $shuffleOptions = self::boolArg($query, 'shuffle_options');
        $hideResultsUntilCloses = self::boolArg($query, 'hide_results_until_closes');
        $membersOnly = self::boolArg($query, 'members_only');
        $countryCodes = self::getStrings($query, 12, 'country_codes');
        $media = $this->getInputPollMediaArg($query, 'media');

        $this->doSendMessage([
            '_' => 'inputMessagePoll',
            'question' => $question,
            'options' => $options,
            'description' => $description,
            'media' => $media,
            'is_anonymous' => $isAnonymous,
            'allows_multiple_answers' => $allowsMultipleAnswers,
            'allows_revoting' => $allowsRevoting,
            'members_only' => $membersOnly,
            'country_codes' => $countryCodes,
            'shuffle_options' => $shuffleOptions,
            'hide_results_until_closes' => $hideResultsUntilCloses,
            'type' => $pollType,
            'open_period' => $openPeriod,
            'close_date' => $closeDate,
            'is_closed' => $isClosed,
        ], $query);
    }

    public function processStopPollQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $chatIdStr = $query->arg('chat_id');
        $messageId = self::getMessageId($query);
        $replyMarkup = $this->resolveReplyMarkupBotUsernames($this->getReplyMarkup($query));

        if ($businessConnectionId !== '') {
            // Client::TdOnStopBusinessPollCallback
            $message = $this->stopBusinessPoll($businessConnectionId, $chatIdStr, $messageId, $replyMarkup);
            if ($message->content['_'] !== 'messagePoll') {
                throw new QueryError(400, 'message poll not found');
            }
            $content = $message->content;
            $query->answerQuery(JsonPoll::make($content['poll'], $content['description'], $content['media'], $this));

            return;
        }

        [$chatId, $messageId] = $this->checkMessage($chatIdStr, $messageId, false, self::ACCESS_EDIT, 'message with poll to stop');
        $this->tdStopPoll($chatId, $messageId, $replyMarkup);

        // Client::TdOnStopPollCallback
        $message = $this->getMessage($chatId, $messageId, true);
        if ($message === null) {
            throw new QueryError(400, 'message not found');
        }
        if ($message->content['_'] !== 'messagePoll') {
            throw new QueryError(400, 'message poll not found');
        }
        $content = $message->content;
        $query->answerQuery(JsonPoll::make($content['poll'], $content['description'], $content['media'], $this));
    }

    public function processSendChecklistQuery(Query $query): void
    {
        $checklist = $this->getInputChecklistArg($query, 'checklist');
        $this->doSendMessage(['_' => 'inputMessageChecklist', 'checklist' => $checklist], $query);
    }

    public function processEditMessageChecklistQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $chatIdStr = $query->arg('chat_id');
        $messageId = self::getMessageId($query);
        $replyMarkup = $this->getReplyMarkup($query);
        $checklist = $this->getInputChecklistArg($query, 'checklist');
        $replyMarkup = $this->resolveReplyMarkupBotUsernames($replyMarkup);

        if ($businessConnectionId !== '') {
            $this->answerBusinessMessage($query, $this->editBusinessMessageChecklist($businessConnectionId, $chatIdStr, $messageId,
                $replyMarkup, $checklist));

            return;
        }

        [$chatId, $messageId] = $this->checkMessage($chatIdStr, $messageId, false, self::ACCESS_EDIT, 'message to edit');

        // MessagesManager::edit_message_to_do_list
        $message = $this->getMessage($chatId, $messageId, true);
        if ($message === null) {
            throw new QueryError(400, 'Message not found');
        }
        if (!$this->canEditMessage($chatId, $message, true)) {
            throw new QueryError(400, 'Message can\'t be edited');
        }
        if ($message->content['_'] !== 'messageChecklist') {
            throw new QueryError(400, 'There is no checklist in the message to edit');
        }
        $media = $this->getInputMediaTodo($checklist);
        $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, $this->hasMessageSenderUserId($chatId, $message));

        $edited = $this->editMessage($chatId, $messageId, false, null, false, $media, false, $tlReplyMarkup);
        $edited->isContentChanged = false;
        $query->answerQuery(JsonMessage::make($edited, false, $this));
    }

    /**
     * Client::get_input_poll_options.
     *
     * @return list<array{text: FormattedText, media: array<string, mixed>|null}>
     */
    protected function getInputPollOptions(Query $query): array
    {
        $value = TdJson::tryDecode($query->arg('options'));
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse options JSON object');
        }

        return $this->parseArray($value, 'InputPollOption', function (JsonValue $option) use ($query): array {
            if ($option->type === TdJson::STRING) {
                return ['text' => new FormattedText($option->string()), 'media' => null];
            }
            if (!$option->isObject()) {
                throw QueryError::badRequest('Expected InputPollOption to be an Object');
            }
            $object = $option->object();
            $text = self::json(static fn (): string => $object->getRequiredStringField('text'));
            $parseMode = self::json(static fn (): string => $object->getOptionalStringField('text_parse_mode'));
            $optionText = $this->getFormattedText($text, $parseMode, $object->extractField('text_entities'));
            try {
                $media = $this->getInputPollMediaJson($query, $object->extractField('media'), true);
            } catch (JsonError|QueryError $e) {
                throw QueryError::badRequest('Failed to parse poll option media: '.$e->getMessage());
            }

            return ['text' => $optionText, 'media' => $media];
        });
    }

    /**
     * Client::get_input_poll_media (query argument).
     *
     * @return array<string, mixed>|null td_api InputPollMedia
     */
    protected function getInputPollMediaArg(Query $query, string $field): ?array
    {
        $media = $query->arg($field);
        if ($media === '') {
            return null;
        }

        $value = TdJson::tryDecode($media);
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse InputPollMedia JSON object');
        }

        try {
            return $this->getInputPollMediaJson($query, $value, false);
        } catch (JsonError|QueryError $e) {
            throw QueryError::badRequest('Failed to parse InputPollMedia: '.$e->getMessage());
        }
    }

    /**
     * Client::get_input_poll_media (JSON value).
     *
     * @return array<string, mixed>|null
     */
    protected function getInputPollMediaJson(Query $query, JsonValue $inputMedia, bool $forOption): ?array
    {
        if (!$inputMedia->isObject()) {
            if ($inputMedia->isNull()) {
                return null;
            }
            throw new JsonError('expected an Object');
        }
        $object = $inputMedia->object();

        $type = $object->getRequiredStringField('type');
        if ($type === 'location') {
            return ['_' => 'inputPollMediaLocation', 'location' => self::getLocationFromJson($object)];
        }
        if ($type === 'venue') {
            return ['_' => 'inputPollMediaVenue', 'venue' => self::getVenueFromJson($object)];
        }
        if ($type === 'link') {
            if (!$forOption) {
                throw new JsonError("type \"{$type}\" is unsupported");
            }

            return ['_' => 'inputPollMediaLink', 'url' => $object->getRequiredStringField('url')];
        }

        $media = $object->getOptionalStringField('media');
        $inputFile = $this->getInputFile($query, '', false, $media);
        if ($inputFile === null) {
            throw new JsonError('media not found');
        }
        $thumbnail = $this->getInputThumbnailFromJson($query, $object, true);

        if ($type === 'photo' || $type === 'live_photo') {
            return ['_' => 'inputPollMediaPhoto', 'photo' => $this->getInputPhotoJson($query, $object, $inputFile, $type === 'live_photo')];
        }
        if ($type === 'video') {
            return ['_' => 'inputPollMediaVideo', 'video' => $this->getInputVideoJson($query, $object, $inputFile, $thumbnail)];
        }
        if ($type === 'animation') {
            return ['_' => 'inputPollMediaAnimation', 'animation' => self::getInputAnimationJson($object, $inputFile, $thumbnail)];
        }

        if ($forOption ? $type !== 'sticker' : $type !== 'audio' && $type !== 'document') {
            throw new JsonError("type \"{$type}\" is unsupported");
        }
        if ($type === 'sticker') {
            return ['_' => 'inputPollMediaSticker', 'sticker' => ['sticker' => $inputFile, 'thumbnail' => $thumbnail, 'width' => 0, 'height' => 0]];
        }
        if ($type === 'audio') {
            return ['_' => 'inputPollMediaAudio', 'audio' => self::getInputAudioJson($object, $inputFile, $thumbnail)];
        }

        return ['_' => 'inputPollMediaDocument', 'document' => [
            'document' => $inputFile, 'thumbnail' => $thumbnail,
            'disable_content_type_detection' => $object->getOptionalBoolField('disable_content_type_detection'),
        ]];
    }

    /**
     * Client::get_venue (JSON object).
     *
     * @return array<string, mixed>
     */
    protected static function getVenueFromJson(JsonObject $object): array
    {
        $location = self::getLocationFromJson($object);
        $title = $object->getRequiredStringField('title');
        $address = $object->getRequiredStringField('address');
        $foursquareId = $object->getOptionalStringField('foursquare_id');
        $foursquareType = $object->getOptionalStringField('foursquare_type');
        $googlePlaceId = $object->getOptionalStringField('google_place_id');
        $googlePlaceType = $object->getOptionalStringField('google_place_type');

        $provider = $venueId = $venueType = '';
        if ($googlePlaceId !== '' || $googlePlaceType !== '') {
            $provider = 'gplaces';
            $venueId = $googlePlaceId;
            $venueType = $googlePlaceType;
        }
        if ($foursquareId !== '' || $foursquareType !== '') {
            $provider = 'foursquare';
            $venueId = $foursquareId;
            $venueType = $foursquareType;
        }

        return ['location' => $location, 'title' => $title, 'address' => $address, 'provider' => $provider, 'id' => $venueId, 'type' => $venueType];
    }

    /**
     * Client::get_strings.
     *
     * @return list<string>
     */
    protected static function getStrings(Query $query, int $maxCount, string $field): array
    {
        $value = $query->arg($field);
        if ($value === '') {
            return [];
        }

        $value = self::jsonArg($value, "Can't parse {$field} JSON object");
        if (!$value->isArray()) {
            throw QueryError::badRequest('Expected an Array of String');
        }
        if (count($value->array()) > $maxCount) {
            throw QueryError::badRequest('Too many items specified');
        }

        $strings = [];
        foreach ($value->array() as $item) {
            if ($item->type !== TdJson::STRING) {
                throw QueryError::badRequest('Expected an Array of String');
            }
            $strings[] = $item->string();
        }

        return $strings;
    }

    /**
     * Client::get_input_checklist (query argument).
     *
     * @return array<string, mixed> td_api inputChecklist
     */
    protected function getInputChecklistArg(Query $query, string $field): array
    {
        $checklist = self::getRequiredStringArg($query, $field);
        $value = TdJson::tryDecode($checklist);
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse InputChecklist JSON object');
        }

        try {
            return $this->getInputChecklistJson($value);
        } catch (JsonError|QueryError $e) {
            throw QueryError::badRequest('Can\'t parse InputChecklist: '.$e->getMessage());
        }
    }

    /**
     * Client::get_input_checklist (JSON value).
     *
     * @return array<string, mixed>
     */
    private function getInputChecklistJson(JsonValue $value): array
    {
        if (!$value->isObject()) {
            throw new JsonError('expected an Object');
        }
        $object = $value->object();
        $inputTitle = $object->getRequiredStringField('title');
        $parseMode = $object->getOptionalStringField('parse_mode');
        $title = $this->getFormattedText($inputTitle, $parseMode, $object->extractField('title_entities'));
        $othersCanAddTasks = $object->getOptionalBoolField('others_can_add_tasks');
        $othersCanMarkTasksAsDone = $object->getOptionalBoolField('others_can_mark_tasks_as_done');
        $inputTasks = $object->extractRequiredField('tasks', TdJson::ARRAY);
        $tasks = $this->parseArray($inputTasks, 'InputChecklistTask', function (JsonValue $task): array {
            if (!$task->isObject()) {
                throw new JsonError('expected an Object');
            }
            $object = $task->object();
            $id = $object->getRequiredIntField('id');
            $text = $object->getRequiredStringField('text');
            $parseMode = $object->getOptionalStringField('parse_mode');

            return ['id' => $id, 'text' => $this->getFormattedText($text, $parseMode, $object->extractField('text_entities'))];
        });

        return [
            'title' => $title,
            'tasks' => $tasks,
            'others_can_add_tasks' => $othersCanAddTasks,
            'others_can_mark_tasks_as_done' => $othersCanMarkTasksAsDone,
        ];
    }

    // ═════════════════════════════════════════════════════════════════
    //  TDLib side
    // ═════════════════════════════════════════════════════════════════

    /**
     * create_input_message_content for inputMessagePoll and PollManager::get_input_media.
     *
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function getInputPollContent(int $chatId, array $content): array
    {
        $caption = $this->getOutgoingFormattedText($content['description'] ?? new FormattedText(), true);
        if (Utf8::length($caption->text) > self::MESSAGE_CAPTION_LENGTH_MAX) {
            throw new QueryError(400, 'Message caption is too long');
        }

        $question = $this->getOutgoingFormattedText($content['question'], false);
        if (Utf8::length($question->text) > self::MAX_POLL_QUESTION_LENGTH) {
            throw new QueryError(400, 'Poll question length must not exceed '.self::MAX_POLL_QUESTION_LENGTH);
        }
        $media = $this->getTdInputPollMedia($chatId, $content['media'], false);

        // PollOption::get_poll_options
        if ($content['options'] === []) {
            throw new QueryError(400, 'Poll must have at least one answer option');
        }
        $maxOptions = $this->getAppConfigInteger('poll_answers_max', 12);
        if (count($content['options']) > $maxOptions) {
            throw new QueryError(400, "Poll can't have more than {$maxOptions} options");
        }
        $options = [];
        foreach ($content['options'] as $option) {
            $text = $this->getOutgoingFormattedText($option['text'], false);
            if (Utf8::length($text->text) > self::MAX_POLL_OPTION_LENGTH) {
                throw new QueryError(400, 'Poll options length must not exceed '.self::MAX_POLL_OPTION_LENGTH);
            }
            $options[] = ['text' => $text->keepOnlyCustomEmoji(), 'media' => $this->getTdInputPollMedia($chatId, $option['media'], true)];
        }

        $isQuiz = false;
        $hasOpenAnswers = false;
        $correctOptionIds = [];
        $explanation = new FormattedText();
        $explanationMedia = null;
        $type = $content['type'];
        if ($type['_'] === 'inputPollTypeRegular') {
            $hasOpenAnswers = $type['allow_adding_options'];
        } else {
            $isQuiz = true;
            $correctOptionIds = $type['correct_option_ids'];
            self::checkQuizCorrectOptionIds($correctOptionIds, count($options), false);
            $explanation = $this->getOutgoingFormattedText($type['explanation'], true);
            $explanationMedia = $this->getTdInputPollMedia($chatId, $type['explanation_media'], false);
        }

        foreach ($content['country_codes'] as $countryCode) {
            if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
                throw new QueryError(400, 'Invalid country code specified');
            }
        }
        if (count($content['country_codes']) > $this->getAppConfigInteger('poll_countries_max', 12)) {
            throw new QueryError(400, 'Too many countries specified');
        }
        if (!$this->isBroadcastChannel($chatId) && ($content['members_only'] || $content['country_codes'] !== [])) {
            throw new QueryError(400, 'Poll voters can be restricted only in channel chats');
        }

        $openPeriod = $content['open_period'];
        $closeDate = $openPeriod !== 0 ? 0 : $content['close_date'];
        $question = $question->keepOnlyCustomEmoji();
        $inputUser = fn (int $userId): array => $this->inputUser($userId);

        $answers = [];
        foreach ($options as $option) {
            $answers[] = self::tl('inputPollAnswer', [
                'text' => ['_' => 'textWithEntities', 'text' => $option['text']->text, 'entities' => $option['text']->inputEntities($inputUser)],
                'media' => $option['media'],
            ]);
        }

        $poll = self::tl('poll', [
            'id' => 0,
            'closed' => $content['is_closed'],
            'public_voters' => !$content['is_anonymous'],
            'multiple_choice' => $content['allows_multiple_answers'],
            'quiz' => $isQuiz,
            'open_answers' => !$isQuiz && $hasOpenAnswers,
            'revoting_disabled' => !$content['allows_revoting'],
            'shuffle_answers' => $content['shuffle_options'],
            'hide_results_until_close' => $content['hide_results_until_closes'],
            'creator' => true,
            'subscribers_only' => $content['members_only'],
            'question' => ['_' => 'textWithEntities', 'text' => $question->text, 'entities' => $question->inputEntities($inputUser)],
            'answers' => $answers,
            'close_period' => $openPeriod !== 0 ? $openPeriod : null,
            'close_date' => $closeDate !== 0 ? $closeDate : null,
            'countries_iso2' => $content['country_codes'] !== [] ? $content['country_codes'] : null,
            'hash' => 0,
        ]);

        $hasSolution = $isQuiz && $explanation->text !== '';
        $inputMedia = self::tl('inputMediaPoll', [
            'poll' => $poll,
            'correct_answers' => $isQuiz ? $correctOptionIds : null,
            'attached_media' => $media,
            'solution' => $hasSolution ? $explanation->text : null,
            'solution_entities' => $hasSolution ? $explanation->inputEntities($inputUser) : null,
            'solution_media' => $isQuiz ? $explanationMedia : null,
        ]);

        return [
            'type' => 'Poll',
            'text' => $caption,
            'media' => $inputMedia,
            'no_webpage' => false,
            'invert_media' => false,
            'is_anonymous' => $content['is_anonymous'],
        ];
    }

    /**
     * PollManager::check_quiz_correct_option_ids.
     *
     * @param list<int> $correctOptionIds
     */
    protected static function checkQuizCorrectOptionIds(array $correctOptionIds, int $optionCount, bool $allowEmpty): void
    {
        if (!$allowEmpty && $correctOptionIds === []) {
            throw new QueryError(400, 'Correct quiz option list must be non-empty');
        }
        for ($i = 0; $i + 1 < count($correctOptionIds); $i++) {
            if ($correctOptionIds[$i] >= $correctOptionIds[$i + 1]) {
                throw new QueryError(400, 'Correct quiz option list must be increasing');
            }
        }
        foreach ($correctOptionIds as $correctOptionId) {
            if ($correctOptionId < 0 || $correctOptionId >= $optionCount) {
                // Status::Error without a code
                throw new QueryError(0, 'Wrong quiz correct_option_id');
            }
        }
    }

    /**
     * get_input_poll_media: the TL InputMedia of a poll, option or explanation
     * media; files are uploaded with messages.uploadMedia before sending.
     *
     * @param array<string, mixed>|null $media td_api InputPollMedia
     * @return array<string, mixed>|null
     */
    private function getTdInputPollMedia(int $chatId, ?array $media, bool $forOption): ?array
    {
        if ($media === null) {
            return null;
        }
        if ($media['_'] === 'inputPollMediaLink') {
            if (!$forOption) {
                throw new QueryError(400, 'Invalid poll option media content specified');
            }
            if ($media['url'] === '') {
                throw new QueryError(400, 'Poll option link must be non-empty');
            }

            return ['_' => 'inputMediaWebPage', 'optional' => true, 'url' => $media['url']];
        }

        $content = match ($media['_']) {
            'inputPollMediaAnimation' => ['_' => 'inputMessageAnimation', 'animation' => $media['animation'], 'caption' => null, 'show_caption_above_media' => false, 'has_spoiler' => false],
            'inputPollMediaAudio' => ['_' => 'inputMessageAudio', 'audio' => $media['audio'], 'caption' => null],
            'inputPollMediaDocument' => ['_' => 'inputMessageDocument', 'document' => $media['document'], 'caption' => null],
            'inputPollMediaLocation' => ['_' => 'inputMessageLocation', 'location' => $media['location']],
            'inputPollMediaPhoto' => ['_' => 'inputMessagePhoto', 'photo' => $media['photo'], 'caption' => null, 'show_caption_above_media' => false, 'has_spoiler' => false],
            'inputPollMediaSticker' => ['_' => 'inputMessageSticker', 'sticker' => $media['sticker'], 'emoji' => ''],
            'inputPollMediaVenue' => ['_' => 'inputMessageVenue', 'venue' => $media['venue']],
            'inputPollMediaVideo' => ['_' => 'inputMessageVideo', 'video' => $media['video'], 'caption' => null, 'show_caption_above_media' => false, 'has_spoiler' => false],
        };
        $prepared = $this->getInputMessageContent($chatId, $content);
        $allowed = $forOption
            ? ['Animation', 'Location', 'Photo', 'Sticker', 'Text', 'Venue', 'Video']
            : ['Animation', 'Audio', 'Document', 'Location', 'Photo', 'Venue', 'Video'];
        if (!in_array($prepared['type'], $allowed, true)) {
            throw new QueryError(400, $forOption ? 'Invalid poll option media content specified' : 'Invalid poll media content specified');
        }

        $input = $prepared['media'];
        if (in_array($input['_'], ['inputMediaGeoPoint', 'inputMediaVenue'], true)) {
            return $input;
        }

        return ['_' => '@uploadMedia', 'media' => $input];
    }

    /**
     * ToDoList::get_to_do_list + get_input_media_todo.
     *
     * @param array<string, mixed>|null $list td_api inputChecklist
     * @return array<string, mixed> TL inputMediaTodo
     */
    protected function getInputMediaTodo(?array $list): array
    {
        if ($list === null) {
            throw new QueryError(400, 'Checklist must be non-empty');
        }
        $title = $this->getOutgoingFormattedText($list['title'], false);
        $maxLength = $this->getAppConfigInteger('todo_title_length_max', 255);
        if (Utf8::length($title->text) > $maxLength) {
            throw new QueryError(400, "Checklist title length must not exceed {$maxLength}");
        }
        $title = $title->removeUnallowedQuoteEntities();

        $inputUser = fn (int $userId): array => $this->inputUser($userId);
        $items = [];
        foreach ($list['tasks'] as $task) {
            $text = $this->getOutgoingFormattedText($task['text'], false);
            $maxLength = $this->getAppConfigInteger('todo_item_length_max', 100);
            if (Utf8::length($text->text) > $maxLength) {
                throw new QueryError(400, "Checklist task text length must not exceed {$maxLength}");
            }
            if ($task['id'] <= 0) {
                throw new QueryError(400, 'Checklist task identifier must be positive');
            }
            $text = (new FormattedText(str_replace("\n", ' ', $text->text), $text->entities))->removeUnallowedQuoteUserEntities(true, true);
            $items[] = [
                '_' => 'todoItem',
                'id' => $task['id'],
                'title' => ['_' => 'textWithEntities', 'text' => $text->text, 'entities' => $text->inputEntities($inputUser)],
            ];
        }
        if ($items === []) {
            throw new QueryError(400, 'Checklist must have at least 1 task');
        }
        $maxTaskCount = $this->getAppConfigInteger('todo_items_max', $this->isTestDc() ? 10 : 30);
        if (count($items) > $maxTaskCount) {
            throw new QueryError(400, "Checklist must have at most {$maxTaskCount} tasks");
        }

        return ['_' => 'inputMediaTodo', 'todo' => self::tl('todoList', [
            'others_can_append' => $list['others_can_add_tasks'],
            'others_can_complete' => $list['others_can_mark_tasks_as_done'],
            'title' => ['_' => 'textWithEntities', 'text' => $title->text, 'entities' => $title->inputEntities($inputUser)],
            'list' => $items,
        ])];
    }

    /**
     * create_input_message_content for inputMessageChecklist.
     *
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function getInputChecklistContent(array $content): array
    {
        return [
            'type' => 'ToDoList',
            'text' => new FormattedText(),
            'media' => $this->getInputMediaTodo($content['checklist'] ?? null),
            'no_webpage' => false,
            'invert_media' => false,
        ];
    }

    /**
     * PollManager::stop_poll.
     *
     * @param array<string, mixed>|null $replyMarkup
     */
    protected function tdStopPoll(int $chatId, int $messageId, ?array $replyMarkup): void
    {
        // MessagesManager::get_message_poll_id
        $message = $this->getMessage($chatId, $messageId, true) ?? $this->tdGetMessage($chatId, $messageId);
        if ($message->content['_'] !== 'messagePoll') {
            throw new QueryError(400, 'Message is not a poll');
        }
        if (!$this->canEditMessage($chatId, $message, true)) {
            throw new QueryError(400, 'Poll can\'t be stopped');
        }
        $pollId = (int) $message->content['poll']['id'];
        $polls = $this->polls();
        $poll = $polls->getPoll($pollId);
        if ($poll === null || $poll['is_closed']) {
            throw new QueryError(400, 'Poll has already been closed');
        }
        $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, $this->hasMessageSenderUserId($chatId, $message));

        $polls->close($pollId);
        $polls->markBeingClosed($pollId);
        try {
            $result = $this->invoke('messages.editMessage', self::stripConstructor(self::tl('messages.editMessage', [
                'peer' => $this->inputPeer($chatId),
                'id' => $messageId,
                'media' => ['_' => 'inputMediaPoll', 'poll' => [
                    '_' => 'poll',
                    'id' => $pollId,
                    'closed' => true,
                    'creator' => true,
                    'question' => ['_' => 'textWithEntities', 'text' => '', 'entities' => []],
                    'answers' => [],
                    'hash' => 0,
                ]],
                'reply_markup' => $tlReplyMarkup,
            ])));
        } catch (\Throwable $e) {
            $polls->forgetBeingClosed($pollId);
            throw QueryError::fromThrowable($e);
        }

        // UpdatesManager::on_get_updates before PollManager::on_stop_poll_finished
        $converter = new MessageConverter($this);
        foreach ($this->updatesOf(is_array($result) ? $result : []) as $update) {
            switch ($update['_'] ?? '') {
                case 'updateMessagePoll':
                    $polls->onGetPoll((int) $update['poll_id'], $update['poll'] ?? null, $update['results'] ?? [], $converter);
                    break;
                case 'updateEditMessage':
                case 'updateEditChannelMessage':
                    $info = $converter->convert($update['message'] ?? []);
                    if ($info !== null && $info->chatId === $chatId && $info->id === $messageId) {
                        $this->addSentMessage($info);
                    }
                    break;
            }
        }
        $polls->forgetBeingClosed($pollId);

        // on_stop_poll_finished → on_external_update_message_content → Client::update_message_content
        $cached = $this->getMessage($chatId, $messageId, true);
        if ($cached !== null && $cached->content['_'] === 'messagePoll' && (int) $cached->content['poll']['id'] === $pollId) {
            $cached->content['poll'] = $polls->getPollObject($pollId);
            $cached->isContentChanged = true;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function stripConstructor(array $params): array
    {
        unset($params['_']);

        return $params;
    }
}
