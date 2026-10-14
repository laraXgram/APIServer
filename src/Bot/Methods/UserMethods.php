<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Messages\MediaConverter;
use LaraGram\Server\Messages\MessageConverter;
use LaraGram\Server\Objects\JsonMedia;
use LaraGram\Server\Objects\JsonMessage;
use LaraGram\Server\Objects\JsonUser;
use LaraGram\Server\Support\TdInteger;

/**
 * getUserProfilePhotos, getUserProfileAudios, setUserEmojiStatus,
 * getUserChatBoosts and getUserPersonalChatMessages with the TDLib parts
 * they rely on (UserManager, BoostManager, MessageQueryManager).
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait UserMethods
{
    public function processGetUserProfilePhotosQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $offset = self::getIntegerArg($query, 'offset', 0, 0);
        $limit = self::getIntegerArg($query, 'limit', 100, 1, 100);
        $this->checkUser($userId);

        // UserManager::get_user_profile_photos
        $result = $this->invokeOrFail('photos.getUserPhotos', ['user_id' => $this->inputUser($userId), 'offset' => $offset, 'max_id' => 0, 'limit' => $limit]);
        $photos = $result['photos'] ?? [];
        $count = ($result['_'] ?? '') === 'photos.photosSlice' ? (int) $result['count'] : count($photos);
        $count = max($count, (count($photos) > 0 ? $offset : 0) + count($photos));

        $objects = [];
        foreach ($photos as $photo) {
            $converted = MediaConverter::photo($photo);
            if ($converted === null) {
                $count--;
                continue;
            }
            $objects[] = JsonMedia::photo($converted);
        }
        if ($offset >= $count) {
            $objects = [];
        } else {
            $objects = array_slice($objects, 0, min($limit, $count - $offset));
        }

        $query->answerQuery(['total_count' => $count, 'photos' => $objects]);
    }

    public function processGetUserProfileAudiosQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $offset = self::getIntegerArg($query, 'offset', 0, 0);
        $limit = self::getIntegerArg($query, 'limit', 100, 1, 100);
        $this->checkUser($userId);

        // UserManager::get_user_saved_music
        $result = $this->invokeOrFail('users.getSavedMusic', ['id' => $this->inputUser($userId), 'offset' => $offset, 'limit' => $limit, 'hash' => 0]);
        $documents = $result['documents'] ?? [];
        $count = max((int) ($result['count'] ?? 0), (count($documents) > 0 ? $offset : 0) + count($documents));

        $audios = [];
        foreach ($documents as $document) {
            $audio = MediaConverter::document($document);
            if ($audio === null || $audio['kind'] !== MediaConverter::DOCUMENT_AUDIO) {
                $count--;
                continue;
            }
            $audios[] = JsonMedia::audio($audio);
        }
        if ($offset >= $count) {
            $audios = [];
        } else {
            $audios = array_slice($audios, 0, min($limit, $count - $offset));
        }

        $query->answerQuery(['total_count' => $count, 'audios' => $audios]);
    }

    public function processSetUserEmojiStatusQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $customEmojiId = TdInteger::toInt64($query->arg('emoji_status_custom_emoji_id'));
        $expirationDate = TdInteger::toInt32($query->arg('emoji_status_expiration_date'));
        $this->checkUser($userId);

        // EmojiStatus::get_input_emoji_status
        $status = $customEmojiId === 0
            ? ['_' => 'emojiStatusEmpty']
            : self::tl('emojiStatus', ['document_id' => $customEmojiId, 'until' => $expirationDate !== 0 ? $expirationDate : null]);
        try {
            $this->invoke('bots.updateUserEmojiStatus', ['user_id' => $this->inputUser($userId), 'emoji_status' => $status]);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'USER_PERMISSION_DENIED')) {
                throw new QueryError(403, 'Not enough rights to change the user\'s emoji status');
            }
            throw QueryError::fromThrowable($e);
        }
        $query->answerQuery(true);
    }

    public function processGetUserChatBoostsQuery(Query $query): void
    {
        $chatIdStr = $query->arg('chat_id');
        $userId = self::getUserIdArg($query);
        $chatId = $this->checkChat($chatIdStr, self::ACCESS_WRITE);
        $this->checkUserNoFail($userId);

        // BoostManager::get_user_dialog_boosts
        $this->checkChatAccess($chatId, self::ACCESS_READ);
        if ($userId <= 0) {
            throw new QueryError(400, 'User not found');
        }
        $result = $this->invokeOrFail('premium.getUserBoosts', ['peer' => $this->inputPeer($chatId), 'user_id' => $this->inputUser($userId)]);

        $boosts = [];
        $now = $this->getUnixTime();
        foreach ($result['boosts'] ?? [] as $boost) {
            $object = $this->chatBoostObject($boost);
            if ($object === null || $object['expiration_date'] <= $now) {
                continue;
            }
            $boosts[] = $object;
        }
        $query->answerQuery(['boosts' => $boosts]);
    }

    /**
     * get_chat_boost_object + Client::JsonChatBoost.
     *
     * @param array<string, mixed> $boost TL boost
     * @return array<string, mixed>|null
     */
    public function chatBoostObject(array $boost): ?array
    {
        $boostUserId = (int) ($boost['user_id'] ?? 0);
        if (!empty($boost['giveaway'])) {
            $source = ['source' => 'giveaway'];
            $giveawayMessageId = (int) ($boost['giveaway_msg_id'] ?? 0);
            $source['giveaway_message_id'] = $giveawayMessageId > 0 ? $giveawayMessageId : 1;
            $stars = max(0, min((int) ($boost['stars'] ?? 0), 1 << 51));
            if ($stars > 0) {
                $source['prize_star_count'] = $stars;
            }
            $userId = $boostUserId > 0 && empty($boost['unclaimed']) ? $boostUserId : 0;
            if ($userId !== 0) {
                $source['user'] = JsonUser::make($userId, $this);
            } elseif (!empty($boost['unclaimed'])) {
                $source['is_unclaimed'] = true;
            }
        } elseif ($boostUserId <= 0) {
            return null;
        } else {
            $source = ['source' => !empty($boost['gift']) ? 'gift_code' : 'premium', 'user' => JsonUser::make($boostUserId, $this)];
        }

        return [
            'boost_id' => (string) $boost['id'],
            'add_date' => (int) $boost['date'],
            'expiration_date' => max((int) ($boost['expires'] ?? 0), 0),
            'source' => $source,
        ];
    }

    public function processGetUserPersonalChatMessagesQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $limit = self::getIntegerArg($query, 'limit', 0, 0, 20);
        $this->checkUser($userId);

        // MessageQueryManager::get_personal_chat_history
        if ($limit <= 0) {
            throw new QueryError(400, 'Limit must be positive');
        }
        $result = $this->invokeOrFail('messages.getPersonalChannelHistory', [
            'user_id' => $this->inputUser($userId), 'limit' => $limit, 'max_id' => 2147483647, 'min_id' => 0, 'hash' => 0,
        ]);

        $converter = new MessageConverter($this);
        $messages = [];
        foreach ($result['messages'] ?? [] as $message) {
            $info = $converter->convert($message);
            if ($info !== null) {
                $messages[] = JsonMessage::make($info, false, $this);
            }
        }
        $query->answerQuery($messages);
    }
}
