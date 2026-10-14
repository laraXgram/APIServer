<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Files\FileType;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Support\TdJson;

/**
 * getBusinessConnection, readBusinessMessage, deleteBusinessMessages and
 * the business account methods with the BusinessConnectionManager and
 * UserManager parts they rely on.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait BusinessMethods
{
    /** BusinessConnectionManager::MAX_NAME_LENGTH */
    private const BUSINESS_MAX_NAME_LENGTH = 64;

    public function processGetBusinessConnectionQuery(Query $query): void
    {
        $connection = $this->checkBusinessConnection($query->arg('business_connection_id'));
        $query->answerQuery($this->businessConnectionObject($connection));
    }

    public function processReadBusinessMessageQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $chatIdStr = $query->arg('chat_id');
        $messageId = self::getMessageId($query);
        [$connection, $chatId] = $this->checkBusinessConnectionChatId($businessConnectionId, $chatIdStr);

        // BusinessConnectionManager::read_business_message
        $this->checkBusinessConnectionDialog($connection, $chatId);
        self::checkBusinessMessageId($messageId);
        $this->invokeBusiness($connection, 'messages.readHistory', ['peer' => $this->inputPeer($chatId), 'max_id' => $messageId]);
        $query->answerQuery(true);
    }

    public function processDeleteBusinessMessagesQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $messageIds = self::getMessageIds($query, 100);
        $connection = $this->checkBusinessConnection($businessConnectionId);

        // BusinessConnectionManager::delete_business_messages
        foreach ($messageIds as $messageId) {
            self::checkBusinessMessageId($messageId);
        }
        if (count($messageIds) > 100) {
            throw new QueryError(400, 'Too many messages identifiers specified');
        }
        $this->invokeBusiness($connection, 'messages.deleteMessages', ['revoke' => true, 'id' => $messageIds]);
        $query->answerQuery(true);
    }

    public function processSetBusinessAccountNameQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $firstName = self::getRequiredStringArg($query, 'first_name');
        $lastName = $query->arg('last_name');
        $connection = $this->checkBusinessConnection($businessConnectionId);

        // BusinessConnectionManager::set_business_name
        $this->invokeBusiness($connection, 'account.updateProfile', [
            'first_name' => self::cleanName($firstName, self::BUSINESS_MAX_NAME_LENGTH),
            'last_name' => self::cleanName($lastName, self::BUSINESS_MAX_NAME_LENGTH),
        ]);
        $this->forgetChatFull($connection['user_id']);
        $query->answerQuery(true);
    }

    public function processSetBusinessAccountUsernameQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $username = $query->arg('username');
        $connection = $this->checkBusinessConnection($businessConnectionId);

        // BusinessConnectionManager::set_business_username
        if ($username !== '' && !self::isAllowedUsername($username)) {
            throw new QueryError(400, 'Username is invalid');
        }
        $user = $this->invokeBusiness($connection, 'account.updateUsername', ['username' => $username]);
        if (is_array($user) && ($user['_'] ?? '') === 'user') {
            $this->addEntities([$user], []);
        }
        $query->answerQuery(true);
    }

    public function processSetBusinessAccountBioQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $bio = $query->arg('bio');
        $connection = $this->checkBusinessConnection($businessConnectionId);

        // BusinessConnectionManager::set_business_about
        $this->invokeBusiness($connection, 'account.updateProfile', ['about' => $bio]);
        $this->forgetChatFull($connection['user_id']);
        $query->answerQuery(true);
    }

    public function processSetBusinessAccountProfilePhotoQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $photo = $this->getInputChatPhotoArg($query);
        $isPublic = self::boolArg($query, 'is_public');
        $connection = $this->checkBusinessConnection($businessConnectionId);

        // UserManager::set_business_profile_photo → set_profile_photo_impl for the business user
        $mainFrameTimestamp = $photo['main_frame_timestamp'];
        if ($mainFrameTimestamp < 0.0 || $mainFrameTimestamp > 10.0) {
            throw new QueryError(400, 'Wrong main frame timestamp specified');
        }
        $isAnimation = $photo['_'] === 'animated';
        $file = $this->getInputFileId($isAnimation ? FileType::ANIMATION : FileType::PHOTO, $photo['file']);
        $inputFile = $this->uploadFile($file);

        // UploadProfilePhotoQuery: a business user isn't a bot, so the photo is set with the bot field
        $this->invokeOrFail('photos.uploadProfilePhoto', self::untl(self::tl('photos.uploadProfilePhoto', [
            'fallback' => $isPublic,
            'bot' => $this->inputUser($connection['user_id']),
            'file' => $isAnimation ? null : $inputFile,
            'video' => $isAnimation ? $inputFile : null,
            'video_start_ts' => $isAnimation && $mainFrameTimestamp !== 0.0 ? $mainFrameTimestamp : null,
        ])));
        $this->forgetChatFull($connection['user_id']);
        $query->answerQuery(true);
    }

    public function processRemoveBusinessAccountProfilePhotoQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $isPublic = self::boolArg($query, 'is_public');
        $connection = $this->checkBusinessConnection($businessConnectionId);

        // DeleteBusinessProfilePhotoQuery
        $this->invokeBusiness($connection, 'photos.updateProfilePhoto', self::tl('photos.updateProfilePhoto', [
            'fallback' => $isPublic,
            'id' => ['_' => 'inputPhotoEmpty'],
        ]));
        $this->forgetChatFull($connection['user_id']);
        $query->answerQuery(true);
    }

    public function processSetBusinessAccountGiftSettingsQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $showGiftButton = self::boolArg($query, 'show_gift_button');
        $types = $this->getAcceptedGiftTypes($query);
        $connection = $this->checkBusinessConnection($businessConnectionId);

        // UpdateBusinessGiftSettingsQuery
        $disallowed = self::tl('disallowedGiftsSettings', [
            'disallow_unlimited_stargifts' => !$types['unlimited_gifts'],
            'disallow_limited_stargifts' => !$types['limited_gifts'],
            'disallow_unique_stargifts' => !$types['unique_gifts'],
            'disallow_premium_gifts' => !$types['premium_subscription'],
            'disallow_stargifts_from_channels' => !$types['gifts_from_channels'],
        ]);
        $this->invokeBusiness($connection, 'account.setGlobalPrivacySettings', ['settings' => self::tl('globalPrivacySettings', [
            'display_gifts_button' => $showGiftButton,
            'disallowed_gifts' => count($disallowed) > 1 ? $disallowed : null,
        ])]);
        $this->forgetChatFull($connection['user_id']);
        $query->answerQuery(true);
    }

    public function processGetBusinessAccountStarBalanceQuery(Query $query): void
    {
        $connection = $this->checkBusinessConnection($query->arg('business_connection_id'));

        // GetBusinessStarsStatusQuery
        $result = $this->invokeBusiness($connection, 'payments.getStarsStatus', ['peer' => $this->inputPeer($connection['user_id'])]);
        if (($result['balance']['_'] ?? '') !== 'starsAmount') {
            throw new QueryError(500, 'Receive invalid response');
        }
        $query->answerQuery(self::starAmountObject(self::starAmount($result['balance'])));
    }

    public function processTransferBusinessAccountStarsQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $starCount = self::getIntegerArg($query, 'star_count', 0, 0, 1000000000);
        $connection = $this->checkBusinessConnection($businessConnectionId);

        // BusinessConnectionManager::transfer_business_stars
        if ($starCount <= 0 || $starCount > 1000000000) {
            throw new QueryError(400, 'Invalid amount of Telegram Stars to transfer specified');
        }
        $invoice = ['_' => 'inputInvoiceBusinessBotTransferStars', 'bot' => ['_' => 'inputUserSelf'], 'stars' => $starCount];
        $form = $this->invokeBusiness($connection, 'payments.getPaymentForm', ['invoice' => $invoice]);
        switch ($form['_'] ?? '') {
            case 'payments.paymentFormStars':
            case 'payments.paymentFormStarGift':
                $prices = $form['invoice']['prices'] ?? [];
                if (count($prices) !== 1 || (int) ($prices[0]['amount'] ?? 0) !== $starCount) {
                    throw new QueryError(400, 'Wrong transfer price specified');
                }
                break;
            default:
                throw new QueryError(500, 'Unsupported');
        }
        $result = $this->invokeBusiness($connection, 'payments.sendStarsForm', ['form_id' => (int) $form['form_id'], 'invoice' => $invoice]);
        if (($result['_'] ?? '') !== 'payments.paymentResult') {
            throw new QueryError(500, 'Receive invalid response');
        }
        $query->answerQuery(true);
    }

    /**
     * Client::get_accepted_gift_types.
     *
     * @return array{unlimited_gifts: bool, limited_gifts: bool, unique_gifts: bool, gifts_from_channels: bool, premium_subscription: bool}
     */
    protected function getAcceptedGiftTypes(Query $query): array
    {
        $types = $query->arg('accepted_gift_types');
        if ($types === '') {
            throw QueryError::badRequest('Accepted gift types aren\'t specified');
        }
        $value = TdJson::tryDecode($types);
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse accepted gift types JSON object');
        }

        return self::json(static function () use ($value): array {
            if (!$value->isObject()) {
                throw QueryError::badRequest('Object expected as accepted gift types');
            }
            $object = $value->object();
            $result = [
                'unlimited_gifts' => $object->getRequiredBoolField('unlimited_gifts'),
                'limited_gifts' => $object->getRequiredBoolField('limited_gifts'),
                'unique_gifts' => $object->getRequiredBoolField('unique_gifts'),
                'premium_subscription' => $object->getRequiredBoolField('premium_subscription'),
                'gifts_from_channels' => true,
            ];
            if ($object->hasField('gifts_from_channels')) {
                $result['gifts_from_channels'] = $object->getOptionalBoolField('gifts_from_channels');
            }

            return $result;
        });
    }

    /**
     * td::is_allowed_username.
     */
    protected static function isAllowedUsername(string $username): bool
    {
        // is_valid_username
        if ($username === '' || strlen($username) > 32 || !ctype_alpha($username[0])
            || preg_match('/^[A-Za-z0-9_]+$/', $username) !== 1 || str_ends_with($username, '_') || str_contains($username, '__')) {
            return false;
        }
        if (strlen($username) < 5) {
            return false;
        }
        $lowered = strtolower($username);
        foreach (['admin', 'telegram', 'support', 'security', 'settings', 'contacts', 'service', 'telegraph'] as $prefix) {
            if (str_starts_with($lowered, $prefix)) {
                return false;
            }
        }

        return true;
    }
}
