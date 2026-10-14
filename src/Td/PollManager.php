<?php

declare(strict_types=1);

namespace LaraGram\Server\Td;

use LaraGram\Server\Bot\Client;
use LaraGram\Server\Entities\FormattedText;
use LaraGram\Server\Messages\MessageConverter;
use LaraGram\Server\Messages\ServiceConverter;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Objects\JsonPoll;
use LaraGram\Server\Http\JsonWriter;
use LaraGram\Server\Updates\UpdateType;

/**
 * The bot side of td::PollManager: the known polls, merged from the server
 * polls and results, and the td_api `poll` objects built from them.
 */
final class PollManager
{
    /** Polls kept in memory; the oldest are forgotten first. */
    private const MAX_POLL_COUNT = 50000;

    /** Content types a poll can have attached (is_allowed_poll_content). */
    private const POLL_CONTENT = [
        'messageAnimation' => true, 'messageAudio' => true, 'messageDocument' => true, 'messageLocation' => true,
        'messagePhoto' => true, 'messageVenue' => true, 'messageVideo' => true,
    ];

    /** Content types a poll option can have attached (is_allowed_poll_option_content). */
    private const POLL_OPTION_CONTENT = [
        'messageAnimation' => true, 'messageLocation' => true, 'messagePhoto' => true, 'messageSticker' => true,
        'messageText' => true, 'messageVenue' => true, 'messageVideo' => true,
    ];

    /** @var array<int, array<string, mixed>> poll id => poll */
    private array $polls = [];

    /** @var array<int, true> polls closed by stopPoll that still wait for their update */
    private array $beingClosedPolls = [];

    public function __construct(private readonly Client $client)
    {
    }

    public function hasPoll(int $pollId): bool
    {
        return isset($this->polls[$pollId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPoll(int $pollId): ?array
    {
        return $this->polls[$pollId] ?? null;
    }

    /**
     * PollManager::stop_poll: the poll's next update is sent even if nothing changes.
     */
    public function markBeingClosed(int $pollId): void
    {
        $this->beingClosedPolls[$pollId] = true;
    }

    private function eraseBeingClosed(int $pollId): bool
    {
        if (!isset($this->beingClosedPolls[$pollId])) {
            return false;
        }
        unset($this->beingClosedPolls[$pollId]);

        return true;
    }

    public function forgetBeingClosed(int $pollId): void
    {
        unset($this->beingClosedPolls[$pollId]);
    }

    /**
     * PollManager::stop_poll: the poll is closed before the request is sent.
     */
    public function close(int $pollId): void
    {
        if (isset($this->polls[$pollId])) {
            $this->polls[$pollId]['is_closed'] = true;
        }
    }

    /**
     * Content types allowed to be attached to a poll.
     */
    public static function isAllowedPollContent(string $type): bool
    {
        return isset(self::POLL_CONTENT[$type]);
    }

    /**
     * PollManager::on_get_poll.
     *
     * @param array<string, mixed>|null $server TL poll
     * @param array<string, mixed> $results TL pollResults
     * @return int the poll id, or 0 when the poll is unusable
     */
    public function onGetPoll(int $pollId, ?array $server, array $results, MessageConverter $converter): int
    {
        $needUpdatePoll = $pollId !== 0;
        if ($pollId === 0 && $server !== null) {
            $pollId = (int) $server['id'];
        }
        if ($pollId === 0 || self::isLocalPollId($pollId)) {
            return 0;
        }
        if ($server !== null && (int) $server['id'] !== $pollId) {
            return 0;
        }
        if ($server !== null) {
            $answers = $server['answers'] ?? [];
            if ($answers === [] || count($answers) > 10 * 1000) {
                return 0;
            }
            $datas = [];
            foreach ($answers as $answer) {
                if (($answer['_'] ?? '') !== 'pollAnswer' || (string) ($answer['option'] ?? '') === '') {
                    return 0;
                }
                $datas[(string) $answer['option']] = true;
            }
            if (count($datas) !== count($answers)) {
                return 0;
            }
        }

        $poll = $this->polls[$pollId] ?? null;
        if ($poll === null) {
            if ($server === null) {
                return 0;
            }
            $poll = self::newPoll();
        }
        $before = $poll;

        $serverIsClosed = false;
        if ($server !== null) {
            $options = array_map(fn (array $answer): array => $this->pollOption($answer, $converter), $server['answers']);
            if (!self::optionsEqual($poll['options'], $options)) {
                $correctDatas = [];
                foreach ($poll['correct_option_ids'] as $correctOptionId) {
                    $correctDatas[] = $poll['options'][$correctOptionId]['data'];
                }
                $poll['options'] = $options;
                if ($correctDatas !== []) {
                    $poll['correct_option_ids'] = [];
                    foreach ($poll['options'] as $index => $option) {
                        if (in_array($option['data'], $correctDatas, true)) {
                            $poll['correct_option_ids'][] = $index;
                        }
                    }
                }
            }
            $poll['question'] = FormattedText::fromTextWithEntities($server['question'] ?? null, true, true)->keepOnlyCustomEmoji();
            $serverIsClosed = !empty($server['closed']);
            if ($serverIsClosed) {
                $poll['is_closed'] = true;
            }

            $openPeriod = (int) ($server['close_period'] ?? 0);
            $closeDate = (int) ($server['close_date'] ?? 0);
            if ($closeDate === 0 || $openPeriod === 0) {
                $closeDate = 0;
                $openPeriod = 0;
            }
            $poll['open_period'] = $openPeriod;
            if ($closeDate !== $poll['close_date']) {
                $poll['close_date'] = $closeDate;
                if (!$poll['is_closed'] && $closeDate !== 0 && $closeDate <= $this->client->getUnixTime()) {
                    $poll['is_closed'] = true;
                }
            }
            $isQuiz = !empty($server['quiz']);
            $poll['is_anonymous'] = empty($server['public_voters']);
            $poll['allow_multiple_answers'] = !empty($server['multiple_choice']);
            $poll['has_open_answers'] = !$isQuiz && !empty($server['open_answers']);
            $poll['has_revoting_disabled'] = !empty($server['revoting_disabled']);
            $poll['shuffle_answers'] = !empty($server['shuffle_answers']);
            $poll['subscribers_only'] = !empty($server['subscribers_only']);
            $poll['country_codes'] = array_values(array_map('strval', $server['countries_iso2'] ?? []));
            $poll['hide_results_until_close'] = !empty($server['hide_results_until_close']);
            $poll['is_quiz'] = $isQuiz;
            if (!empty($server['creator'])) {
                $poll['is_creator'] = true;
            }
            $poll['hash'] = (int) ($server['hash'] ?? 0);
        }

        $isMin = !empty($results['min']);
        $hasTotalVoters = array_key_exists('total_voters', $results);
        if ($hasTotalVoters) {
            $poll['total_voter_count'] = max(0, (int) $results['total_voters']);
        }
        $correctOptionIds = [];
        $optionCount = count($poll['options']);
        foreach ($results['results'] ?? [] as $result) {
            $data = (string) ($result['option'] ?? '');
            foreach ($poll['options'] as $index => &$option) {
                if ($option['data'] !== $data) {
                    continue;
                }
                if (!$isMin) {
                    $option['is_chosen'] = !empty($result['chosen']);
                }
                if (!empty($result['correct'])) {
                    $correctOptionIds[] = $index;
                }
                $voters = max(0, (int) ($result['voters'] ?? 0));
                if ($option['is_chosen'] && $voters === 0 && !$poll['hide_results_until_close']) {
                    $voters = 1;
                }
                if ($voters > $poll['total_voter_count']) {
                    $poll['total_voter_count'] = $voters;
                }
                $maxVoterCount = intdiv(2147483647, $optionCount) - 2;
                $option['voter_count'] = min($voters, $maxVoterCount);
            }
            unset($option);
        }
        if (!$isMin && ($results['results'] ?? []) === [] && $poll['hide_results_until_close']) {
            foreach ($poll['options'] as &$option) {
                $option['is_chosen'] = false;
                $option['voter_count'] = 0;
            }
            unset($option);
        }
        if (($results['results'] ?? []) !== [] && $hasTotalVoters) {
            $maxTotalVoterCount = 0;
            foreach ($poll['options'] as $option) {
                $maxTotalVoterCount += $option['voter_count'];
            }
            if ($poll['total_voter_count'] > $maxTotalVoterCount && $maxTotalVoterCount !== 0) {
                $poll['total_voter_count'] = $maxTotalVoterCount;
            }
        }
        if (!$isMin) {
            $poll['can_view_stats'] = !empty($results['can_view_stats']);
        }

        $explanation = FormattedText::fromServerText((string) ($results['solution'] ?? ''), $results['solution_entities'] ?? [], true, false);
        if ($poll['is_quiz'] && (!$isMin || $serverIsClosed)) {
            if ($poll['correct_option_ids'] !== $correctOptionIds
                && !($correctOptionIds === [] && $poll['correct_option_ids'] !== [] && $poll['has_revoting_disabled'])) {
                $poll['correct_option_ids'] = $correctOptionIds;
            }
            if (($poll['explanation']->text !== $explanation->text || $poll['explanation']->entities !== $explanation->entities)
                && !($explanation->text === '' && $poll['explanation']->text !== '' && $poll['has_revoting_disabled'])) {
                $poll['explanation'] = $explanation;
            }
            $explanationMedia = null;
            if (isset($results['solution_media'])) {
                $explanationMedia = $converter->contentFromMedia($results['solution_media']);
                if (!self::isAllowedPollContent($explanationMedia['_'])) {
                    $explanationMedia = null;
                }
            }
            $poll['explanation_media'] = $explanationMedia;
        }

        $isChanged = self::isChanged($before, $poll);
        unset($this->polls[$pollId]);
        $this->polls[$pollId] = $poll;
        if (count($this->polls) > self::MAX_POLL_COUNT) {
            unset($this->polls[array_key_first($this->polls)]);
        }

        if ($needUpdatePoll && ($isChanged || ($poll['is_closed'] && $this->eraseBeingClosed($pollId)))) {
            $object = $this->getPollObject($pollId);
            $this->client->addUpdate(UpdateType::POLL, JsonWriter::encode(JsonPoll::make($object, null, null, $this->client)), 86400, $pollId);
        }

        return $pollId;
    }

    /**
     * PollManager::on_get_poll_vote: the `poll_answer` update.
     *
     * @param list<string> $options
     * @param list<int> $positions
     */
    public function onGetPollVote(int $pollId, int $dialogId, array $options, array $positions): ?array
    {
        if ($pollId === 0 || $dialogId === 0 || count($options) !== count($positions)) {
            return null;
        }
        foreach ($options as $option) {
            if ($option === '' || !mb_check_encoding($option, 'UTF-8')) {
                return null;
            }
        }
        foreach ($positions as $position) {
            if ($position < 0 || $position > 100) {
                return null;
            }
        }

        return [
            'poll_id' => $pollId,
            'voter_id' => ServiceConverter::sender($dialogId),
            'option_ids' => array_values($options),
            'option_positions' => array_values($positions),
        ];
    }

    /**
     * PollManager::get_poll_object for a bot.
     *
     * @return array<string, mixed> td_api poll
     */
    public function getPollObject(int $pollId): array
    {
        $poll = &$this->polls[$pollId];
        $now = $this->client->getUnixTime();
        // on_close_poll_timeout: no update is sent for bots
        if (!$poll['is_closed'] && $poll['close_date'] !== 0 && $poll['close_date'] <= $now) {
            $poll['is_closed'] = true;
        }

        $options = [];
        $needBase64 = false;
        foreach ($poll['options'] as $option) {
            if (!mb_check_encoding($option['data'], 'UTF-8')) {
                $needBase64 = true;
            }
            $options[] = [
                'id' => $option['data'],
                'text' => $option['text'],
                'media' => self::pollMediaObject($option['media']),
                'voter_count' => $option['voter_count'],
                'author' => $option['added_by'] === 0 ? null : ServiceConverter::sender($option['added_by']),
                'addition_date' => $option['added_by'] === 0 ? 0 : $option['added_date'],
            ];
        }
        if ($needBase64) {
            foreach ($options as &$option) {
                $option['id'] = base64_encode($option['id']);
            }
            unset($option);
        }

        $totalVoterCount = $poll['total_voter_count'];
        $voterCountSum = 0;
        foreach ($options as $option) {
            if ($totalVoterCount < $option['voter_count']) {
                $totalVoterCount = $option['voter_count'];
            }
            $voterCountSum += $option['voter_count'];
        }
        if ($voterCountSum < $totalVoterCount && $voterCountSum !== 0) {
            $totalVoterCount = $voterCountSum;
        }

        $type = $poll['is_quiz']
            ? [
                '_' => 'pollTypeQuiz',
                'correct_option_ids' => $poll['correct_option_ids'],
                'explanation' => $poll['explanation'],
                'explanation_media' => self::pollMediaObject($poll['explanation_media']),
            ]
            : ['_' => 'pollTypeRegular'];

        $openPeriod = $poll['open_period'];
        $closeDate = $poll['close_date'];
        if ($openPeriod !== 0 && $closeDate === 0) {
            $closeDate = $now + $openPeriod;
        }
        if ($openPeriod === 0 && $closeDate !== 0) {
            if ($closeDate < $now + 5) {
                $closeDate = 0;
            } else {
                $openPeriod = $closeDate - $now;
            }
        }
        if ($poll['is_closed']) {
            $openPeriod = 0;
            $closeDate = 0;
        }

        return [
            'id' => $pollId,
            'question' => $poll['question'],
            'options' => $options,
            'total_voter_count' => $totalVoterCount,
            'is_anonymous' => $poll['is_anonymous'],
            'allows_multiple_answers' => $poll['allow_multiple_answers'],
            'allows_revoting' => !$poll['has_revoting_disabled'],
            'members_only' => $poll['subscribers_only'],
            'country_codes' => $poll['country_codes'],
            'type' => $type,
            'open_period' => $openPeriod,
            'close_date' => $closeDate,
            'is_closed' => $poll['is_closed'],
        ];
    }

    /**
     * get_poll_media_object.
     *
     * @param array<string, mixed>|null $content
     * @return array<string, mixed>|null
     */
    public static function pollMediaObject(?array $content): ?array
    {
        if ($content === null) {
            return null;
        }

        return match ($content['_']) {
            'messageAnimation' => ['_' => 'pollMediaAnimation', 'animation' => $content['animation']],
            'messageAudio' => ['_' => 'pollMediaAudio', 'audio' => $content['audio']],
            'messageDocument' => ['_' => 'pollMediaDocument', 'document' => $content['document']],
            'messageLocation' => ['_' => 'pollMediaLocation', 'location' => $content['location']],
            'messagePhoto' => ['_' => 'pollMediaPhoto', 'photo' => $content['photo'], 'video' => $content['video']],
            'messageSticker' => ['_' => 'pollMediaSticker', 'sticker' => $content['sticker']],
            'messageText' => ['_' => 'pollMediaLink', 'url' => (string) ($content['web_page_url'] ?? '')],
            'messageVenue' => ['_' => 'pollMediaVenue', 'venue' => $content['venue']],
            'messageVideo' => [
                '_' => 'pollMediaVideo',
                'video' => $content['video'],
                'alternative_videos' => $content['alternative_videos'],
                'cover' => $content['cover'],
                'start_timestamp' => $content['start_timestamp'],
            ],
            default => null,
        };
    }

    public static function isLocalPollId(int $pollId): bool
    {
        return $pollId < 0 && $pollId > -2147483648;
    }

    /**
     * @return array<string, mixed>
     */
    private static function newPoll(): array
    {
        return [
            'question' => new FormattedText(),
            'options' => [],
            'is_closed' => false,
            'open_period' => 0,
            'close_date' => 0,
            'is_anonymous' => true,
            'allow_multiple_answers' => false,
            'has_open_answers' => false,
            'has_revoting_disabled' => false,
            'shuffle_answers' => false,
            'subscribers_only' => false,
            'country_codes' => [],
            'hide_results_until_close' => false,
            'is_quiz' => false,
            'is_creator' => false,
            'hash' => 0,
            'total_voter_count' => 0,
            'correct_option_ids' => [],
            'explanation' => new FormattedText(),
            'explanation_media' => null,
            'can_view_stats' => false,
        ];
    }

    /**
     * PollOption(td, telegram_api::PollAnswer).
     *
     * @param array<string, mixed> $answer TL pollAnswer
     * @return array<string, mixed>
     */
    private function pollOption(array $answer, MessageConverter $converter): array
    {
        $media = null;
        if (isset($answer['media'])) {
            $media = $converter->contentFromMedia($answer['media']);
            if (!isset(self::POLL_OPTION_CONTENT[$media['_']])) {
                $media = null;
            }
        }

        $addedBy = 0;
        $addedDate = 0;
        if (isset($answer['added_by'])) {
            $addedBy = ChatId::fromPeer($answer['added_by']);
            $addedDate = (int) ($answer['date'] ?? 0);
            if ($addedBy === 0 || $addedDate <= 0) {
                $addedBy = 0;
                $addedDate = 0;
            }
        }

        return [
            'text' => FormattedText::fromTextWithEntities($answer['text'] ?? null, true, true)->keepOnlyCustomEmoji(),
            'data' => (string) $answer['option'],
            'media' => $media,
            'voter_count' => 0,
            'is_chosen' => false,
            'added_by' => $addedBy,
            'added_date' => $addedDate,
        ];
    }

    /**
     * PollOption operator==: voter counts and choices aren't compared.
     *
     * @param list<array<string, mixed>> $lhs
     * @param list<array<string, mixed>> $rhs
     */
    private static function optionsEqual(array $lhs, array $rhs): bool
    {
        if (count($lhs) !== count($rhs)) {
            return false;
        }
        foreach ($lhs as $i => $option) {
            $other = $rhs[$i];
            if (($option['media']['_'] ?? null) !== ($other['media']['_'] ?? null)
                || $option['text']->text !== $other['text']->text || $option['text']->entities !== $other['text']->entities
                || $option['data'] !== $other['data'] || $option['added_by'] !== $other['added_by']
                || $option['added_date'] !== $other['added_date']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a change of the poll is visible (PollManager's is_changed).
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private static function isChanged(array $before, array $after): bool
    {
        if ($before['is_closed'] && $after['is_closed']) {
            // open period and close date of a closed poll are only saved
            $after['open_period'] = $before['open_period'];
            $after['close_date'] = $before['close_date'];
        }

        return serialize($before) !== serialize($after);
    }
}
