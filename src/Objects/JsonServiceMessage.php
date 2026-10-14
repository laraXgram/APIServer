<?php

declare(strict_types=1);

namespace LaraGram\Server\Objects;

use LaraGram\Server\Bot\Client;
use LaraGram\Server\Entities\FormattedText;
use LaraGram\Server\Messages\MessageInfo;

/**
 * The service-message part of Client::JsonMessage::store: the fields of
 * service message contents, in the official order and shape.
 */
final class JsonServiceMessage
{
    /**
     * @param array<string, mixed> $object
     */
    public static function store(array &$object, MessageInfo $message, bool $needReply, Client $client): void
    {
        $content = $message->content;
        $chatId = $message->chatId;

        switch ($content['_']) {
            case 'messageChatAddMembers':
                $userId = self::chooseAddedMemberId($content['member_user_ids'], $client);
                if ($userId > 0) {
                    $object['new_chat_participant'] = JsonUser::make($userId, $client);
                    $object['new_chat_member'] = JsonUser::make($userId, $client);
                    $object['new_chat_members'] = self::users($content['member_user_ids'], $client);
                }
                break;
            case 'messageChatJoinByLink':
            case 'messageChatJoinByRequest':
                if ($message->senderUserId > 0) {
                    $object['new_chat_participant'] = JsonUser::make($message->senderUserId, $client);
                    $object['new_chat_member'] = JsonUser::make($message->senderUserId, $client);
                    $object['new_chat_members'] = self::users([$message->senderUserId], $client);
                }
                break;
            case 'messageChatDeleteMember':
                $object['left_chat_participant'] = JsonUser::make($content['user_id'], $client);
                $object['left_chat_member'] = JsonUser::make($content['user_id'], $client);
                break;
            case 'messageChatChangeTitle':
                $object['new_chat_title'] = $content['title'];
                break;
            case 'messageChatChangePhoto':
                $sizes = [];
                foreach ($content['photo']['sizes'] ?? [] as $size) {
                    if (!in_array($size['type'] ?? '', ['i', 't'], true) && ($size['file']['file_id'] ?? '') !== '') {
                        $sizes[] = JsonMedia::photoSize($size);
                    }
                }
                $object['new_chat_photo'] = $sizes;
                break;
            case 'messageChatDeletePhoto':
                $object['delete_chat_photo'] = true;
                break;
            case 'messageBasicGroupChatCreate':
                $object['group_chat_created'] = true;
                break;
            case 'messageSupergroupChatCreate':
                if (ChatId::type($chatId) === ChatId::TYPE_SUPERGROUP) {
                    if ($client->isBroadcastChannel($chatId)) {
                        $object['channel_chat_created'] = true;
                    } else {
                        $object['supergroup_chat_created'] = true;
                    }
                }
                break;
            case 'messageChatUpgradeTo':
                $object['migrate_to_chat_id'] = ChatId::fromChannel($content['supergroup_id']);
                break;
            case 'messageChatUpgradeFrom':
                $object['migrate_from_chat_id'] = ChatId::fromBasicGroup($content['basic_group_id']);
                break;
            case 'messageForumTopicCreated':
                $created = ['name' => $content['name'], 'icon_color' => $content['icon']['color']];
                if ($content['icon']['custom_emoji_id'] !== 0) {
                    $created['icon_custom_emoji_id'] = (string) $content['icon']['custom_emoji_id'];
                }
                if ($content['is_name_implicit']) {
                    $created['is_name_implicit'] = true;
                }
                $object['forum_topic_created'] = $created;
                break;
            case 'messageForumTopicEdited':
                $edited = [];
                if ($content['name'] !== '') {
                    $edited['name'] = $content['name'];
                }
                if ($content['edit_icon_custom_emoji_id']) {
                    $edited['icon_custom_emoji_id'] = $content['icon_custom_emoji_id'] === 0 ? '' : (string) $content['icon_custom_emoji_id'];
                }
                $object['forum_topic_edited'] = (object) $edited;
                break;
            case 'messageForumTopicIsClosedToggled':
                $object[$content['is_closed'] ? 'forum_topic_closed' : 'forum_topic_reopened'] = new \stdClass();
                break;
            case 'messageForumTopicIsHiddenToggled':
                $object[$content['is_hidden'] ? 'general_forum_topic_hidden' : 'general_forum_topic_unhidden'] = new \stdClass();
                break;
            case 'messagePinMessage':
                $messageId = $content['message_id'];
                if ($messageId > 0) {
                    $pinned = $client->getReplyToMessageInfo($message, $messageId);
                    if ($pinned !== null) {
                        $object['pinned_message'] = JsonMessage::make($pinned, false, $client);
                    } elseif ($needReply) {
                        $object['pinned_message'] = self::inaccessibleMessage($chatId, $messageId, $client);
                    }
                }
                break;
            case 'messagePaymentSuccessfulBot':
                $payment = [
                    'currency' => $content['currency'],
                    'total_amount' => $content['total_amount'],
                    'invoice_payload' => $content['invoice_payload'],
                ];
                if ($content['shipping_option_id'] !== '') {
                    $payment['shipping_option_id'] = $content['shipping_option_id'];
                }
                if ($content['order_info'] !== null) {
                    $payment['order_info'] = self::orderInfo($content['order_info']);
                }
                $payment['telegram_payment_charge_id'] = $content['telegram_payment_charge_id'];
                $payment['provider_payment_charge_id'] = $content['provider_payment_charge_id'];
                if ($content['is_recurring']) {
                    $payment['is_recurring'] = true;
                }
                if ($content['is_first_recurring']) {
                    $payment['is_first_recurring'] = true;
                }
                if ($content['subscription_until_date'] > 0) {
                    $payment['subscription_expiration_date'] = $content['subscription_until_date'];
                }
                $object['successful_payment'] = $payment;
                break;
            case 'messageChatSetMessageAutoDeleteTime':
                $object['message_auto_delete_timer_changed'] = ['message_auto_delete_time' => $content['message_auto_delete_time']];
                break;
            case 'messagePassportDataReceived':
                $object['passport_data'] = JsonPassportData::make($content, $client);
                break;
            case 'messageProximityAlertTriggered':
                $object['proximity_alert_triggered'] = [
                    'traveler' => self::sender($content['traveler_id'], $client),
                    'watcher' => self::sender($content['watcher_id'], $client),
                    'distance' => $content['distance'],
                ];
                break;
            case 'messageVideoChatScheduled':
                $object['video_chat_scheduled'] = ['start_date' => $content['start_date']];
                $object['voice_chat_scheduled'] = ['start_date' => $content['start_date']];
                break;
            case 'messageVideoChatStarted':
                $object['video_chat_started'] = new \stdClass();
                $object['voice_chat_started'] = new \stdClass();
                break;
            case 'messageVideoChatEnded':
                $object['video_chat_ended'] = ['duration' => $content['duration']];
                $object['voice_chat_ended'] = ['duration' => $content['duration']];
                break;
            case 'messageInviteVideoChatParticipants':
                $object['video_chat_participants_invited'] = ['users' => self::users($content['user_ids'], $client)];
                $object['voice_chat_participants_invited'] = ['users' => self::users($content['user_ids'], $client)];
                break;
            case 'messageWebAppDataReceived':
                $object['web_app_data'] = ['button_text' => $content['button_text'], 'data' => $content['data']];
                break;
            case 'messageBotWriteAccessAllowed':
                if (ChatId::type($chatId) !== ChatId::TYPE_PRIVATE) {
                    break;
                }
                $reason = $content['reason'];
                if ($reason['_'] === 'botWriteAccessAllowReasonConnectedWebsite') {
                    if ($reason['domain_name'] !== '') {
                        $object['connected_website'] = $reason['domain_name'];
                    }
                    break;
                }
                $object['write_access_allowed'] = match ($reason['_']) {
                    'botWriteAccessAllowReasonLaunchedWebApp' => ['web_app_name' => $reason['short_name']],
                    'botWriteAccessAllowReasonAcceptedRequest' => ['from_request' => true],
                    default => ['from_attachment_menu' => true],
                };
                break;
            case 'messageUsersShared':
                if (count($content['users']) === 1) {
                    $object['user_shared'] = ['user_id' => $content['users'][0]['user_id'], 'request_id' => $content['button_id']];
                }
                $object['users_shared'] = [
                    'user_ids' => array_column($content['users'], 'user_id'),
                    'users' => array_map(static fn (array $user): array => self::sharedUser($user), $content['users']),
                    'request_id' => $content['button_id'],
                ];
                break;
            case 'messageChatShared':
                $chat = $content['chat'];
                $shared = ['chat_id' => $chat['chat_id']];
                if ($chat['title'] !== '') {
                    $shared['title'] = $chat['title'];
                }
                if ($chat['username'] !== '') {
                    $shared['username'] = $chat['username'];
                }
                if ($chat['photo'] !== null) {
                    $shared['photo'] = JsonMedia::photo($chat['photo']);
                }
                $shared['request_id'] = $content['button_id'];
                $object['chat_shared'] = $shared;
                break;
            case 'messageGiveawayCreated':
                $object['giveaway_created'] = $content['star_count'] > 0 ? ['prize_star_count' => $content['star_count']] : new \stdClass();
                break;
            case 'messageGiveawayCompleted':
                $completed = ['winner_count' => $content['winner_count']];
                if ($content['unclaimed_prize_count'] > 0) {
                    $completed['unclaimed_prize_count'] = $content['unclaimed_prize_count'];
                }
                if ($content['is_star_giveaway']) {
                    $completed['is_star_giveaway'] = true;
                }
                $giveaway = $client->getMessage($chatId, $content['giveaway_message_id'], true);
                if ($giveaway !== null) {
                    $completed['giveaway_message'] = JsonMessage::make($giveaway, true, $client);
                }
                $object['giveaway_completed'] = $completed;
                break;
            case 'messageChatBoost':
                $object['boost_added'] = ['boost_count' => $content['boost_count']];
                break;
            case 'messagePaymentRefunded':
                $refunded = [
                    'currency' => $content['currency'],
                    'total_amount' => $content['total_amount'],
                    'invoice_payload' => $content['invoice_payload'],
                    'telegram_payment_charge_id' => $content['telegram_payment_charge_id'],
                ];
                if ($content['provider_payment_charge_id'] !== '') {
                    $refunded['provider_payment_charge_id'] = $content['provider_payment_charge_id'];
                }
                $object['refunded_payment'] = $refunded;
                break;
            case 'messagePaidMessagePriceChanged':
                $object['paid_message_price_changed'] = ['paid_message_star_count' => $content['paid_message_star_count']];
                break;
            case 'messageDirectMessagePriceChanged':
                $changed = ['are_direct_messages_enabled' => $content['is_enabled']];
                if ($content['paid_message_star_count'] > 0) {
                    $changed['direct_message_star_count'] = $content['paid_message_star_count'];
                }
                $object['direct_message_price_changed'] = $changed;
                break;
            case 'messageChecklistTasksDone':
                $done = [];
                $checklist = $client->getMessage($chatId, $content['checklist_message_id'], true);
                if ($checklist !== null) {
                    $done['checklist_message'] = JsonMessage::make($checklist, false, $client);
                }
                if ($content['marked_as_done_task_ids'] !== []) {
                    $done['marked_as_done_task_ids'] = $content['marked_as_done_task_ids'];
                }
                if ($content['marked_as_not_done_task_ids'] !== []) {
                    $done['marked_as_not_done_task_ids'] = $content['marked_as_not_done_task_ids'];
                }
                $object['checklist_tasks_done'] = (object) $done;
                break;
            case 'messageChecklistTasksAdded':
                $added = [];
                $checklist = $client->getMessage($chatId, $content['checklist_message_id'], true);
                if ($checklist !== null) {
                    $added['checklist_message'] = JsonMessage::make($checklist, false, $client);
                }
                $added['tasks'] = array_map(static fn (array $task): array => JsonChecklist::task(JsonChecklist::taskFromServer($task, $message->date), $client), $content['tasks']);
                $object['checklist_tasks_added'] = $added;
                break;
            case 'messageSuggestedPostApprovalFailed':
            case 'messageSuggestedPostApproved':
            case 'messageSuggestedPostDeclined':
            case 'messageSuggestedPostPaid':
            case 'messageSuggestedPostRefunded':
                $object[self::SUGGESTED_POST_FIELDS[$content['_']]] = self::suggestedPost($content, $chatId, $client);
                break;
            case 'messageChatOwnerLeft':
                $object['chat_owner_left'] = $content['new_owner_user_id'] > 0
                    ? ['new_owner' => JsonUser::make($content['new_owner_user_id'], $client)] : new \stdClass();
                break;
            case 'messageChatOwnerChanged':
                $object['chat_owner_changed'] = ['new_owner' => JsonUser::make($content['new_owner_user_id'], $client)];
                break;
            case 'messagePollOptionAdded':
            case 'messagePollOptionDeleted':
                $option = [];
                $poll = $client->getMessage($chatId, $content['poll_message_id'], true);
                if ($poll !== null) {
                    $option['poll_message'] = JsonMessage::make($poll, false, $client);
                } elseif ($needReply) {
                    $option['poll_message'] = self::inaccessibleMessage($chatId, $content['poll_message_id'], $client);
                }
                $answer = $content['answer'];
                $text = FormattedText::fromServer((string) ($answer['text']['text'] ?? ''), $answer['text']['entities'] ?? []);
                $option['option_persistent_id'] = base64_encode((string) ($answer['option'] ?? ''));
                $option['option_text'] = $text->text;
                if ($text->entities !== []) {
                    $option['option_entities'] = JsonEntity::vector($text->entities, $client);
                }
                $object[$content['_'] === 'messagePollOptionAdded' ? 'poll_option_added' : 'poll_option_deleted'] = $option;
                break;
            case 'messageManagedBotCreated':
                $object['managed_bot_created'] = ['bot' => JsonUser::make($content['bot_user_id'], $client)];
                break;
            case 'messageChatRemovedFromCommunity':
                $object['community_chat_removed'] = new \stdClass();
                break;
            case 'messageChatSetBackground':
                $object['chat_background_set'] = ['type' => self::backgroundType($content['background'])];
                break;
            case 'messageGift':
                $object[$content['is_prepaid_upgrade'] ? 'gift_upgrade_sent' : 'gift'] = self::giftMessage($message, $client);
                break;
            case 'messageUpgradedGift':
                $object['unique_gift'] = self::uniqueGiftMessage($message, $client);
                break;
            case 'messageRefundedUpgradedGift':
                $object['gift'] = ['gift' => JsonGift::gift($content['gift'], $client)];
                break;
            case 'messageChatAddedToCommunity':
                $object['community_chat_added'] = ['community' => self::community($content['community_id'], $client)];
                break;
            case 'messageChatJoinFromCommunity':
                $object['community_chat_joined'] = ['community' => self::community($content['community_id'], $client)];
                break;
        }
    }

    /**
     * Client::JsonCommunity.
     *
     * @return array{id: int, name: string}
     */
    private static function community(int $communityId, Client $client): array
    {
        return ['id' => $communityId, 'name' => $client->getCommunityName($communityId)];
    }

    /**
     * Client::JsonBackgroundType of a chat background (BackgroundType::get_background_type_object).
     *
     * @param array{type: array<string, mixed>, document: array<string, mixed>|null, dark_theme_dimming: int} $background
     * @return array<string, mixed>
     */
    private static function backgroundType(array $background): array
    {
        $type = $background['type'];
        switch ($type['type']) {
            case 'wallpaper':
                $object = ['type' => 'wallpaper', 'document' => JsonMedia::document($background['document']),
                    'dark_theme_dimming' => $background['dark_theme_dimming']];
                if ($type['is_blurred']) {
                    $object['is_blurred'] = true;
                }
                if ($type['is_moving']) {
                    $object['is_moving'] = true;
                }

                return $object;
            case 'pattern':
                $object = ['type' => 'pattern', 'document' => JsonMedia::document($background['document']), 'fill' => $type['fill'],
                    'intensity' => $type['intensity']];
                if ($type['is_inverted']) {
                    $object['is_inverted'] = true;
                }
                if ($type['is_moving']) {
                    $object['is_moving'] = true;
                }

                return $object;
            case 'fill':
                return ['type' => 'fill', 'fill' => $type['fill'], 'dark_theme_dimming' => $background['dark_theme_dimming']];
        }

        return ['type' => 'chat_theme', 'theme_name' => $type['theme_name']];
    }

    /**
     * get_message_content_object (MessageStarGift) + Client::JsonGiftMessage.
     *
     * @return array<string, mixed>
     */
    private static function giftMessage(MessageInfo $message, Client $client): array
    {
        $content = $message->content;
        $action = $content['action'];
        $starCount = static fn (string $field): int => max(0, min((int) ($action[$field] ?? 0), 1 << 51));

        // the StarGiftId of the received gift
        $receivedGiftId = '';
        $ownerId = isset($action['peer']) ? ChatId::fromPeer($action['peer']) : 0;
        if ($ownerId !== 0 && !$content['is_prepaid_upgrade']) {
            $receivedGiftId = self::dialogGiftId($ownerId, (int) ($action['saved_id'] ?? 0));
        } else {
            $giftMessageId = (int) ($action['gift_msg_id'] ?? 0) > 0 ? (int) $action['gift_msg_id'] : $message->id;
            if (!$message->isOutgoing && $giftMessageId > 0 && ChatId::type($message->chatId) === ChatId::TYPE_PRIVATE
                && $message->chatId !== $client->getServiceNotificationsUserId()) {
                $receivedGiftId = (string) $giftMessageId;
            }
        }
        if (!empty($action['auction_acquired'])) {
            $receivedGiftId = '';
        }

        $object = ['gift' => JsonGift::gift($content['gift'], $client)];
        if ($receivedGiftId !== '') {
            $object['owned_gift_id'] = $receivedGiftId;
        }
        if ($starCount('convert_stars') > 0) {
            $object['convert_star_count'] = $starCount('convert_stars');
        }
        if ($starCount('upgrade_stars') > 0) {
            $object['prepaid_upgrade_star_count'] = $starCount('upgrade_stars');
        }
        if (!empty($action['can_upgrade'])) {
            $object['can_be_upgraded'] = true;
        }
        self::addGiftText($object, $action, $client);
        if (!empty($action['name_hidden'])) {
            $object['is_private'] = true;
        }
        if (!empty($action['upgrade_separate'])) {
            $object['is_upgrade_separate'] = true;
        }
        if ((int) ($action['gift_num'] ?? 0) > 0) {
            $object['unique_gift_number'] = (int) $action['gift_num'];
        }

        return $object;
    }

    /**
     * get_message_content_object (MessageStarGiftUnique) + Client::JsonUniqueGiftMessage.
     *
     * @return array<string, mixed>
     */
    private static function uniqueGiftMessage(MessageInfo $message, Client $client): array
    {
        $content = $message->content;
        $action = $content['action'];
        $isUpgrade = !empty($action['upgrade']);
        $isPrepaidUpgrade = !empty($action['prepaid_upgrade']);

        $receivedGiftId = '';
        $ownerId = isset($action['peer']) ? ChatId::fromPeer($action['peer']) : 0;
        if ($ownerId !== 0 && !$isPrepaidUpgrade) {
            $receivedGiftId = self::dialogGiftId($ownerId, (int) ($action['saved_id'] ?? 0));
        } elseif (ChatId::type($message->chatId) === ChatId::TYPE_PRIVATE && $message->id > 0
            && $isUpgrade === ($message->isOutgoing || $message->chatId === $client->getMyId())
            && ($message->chatId !== $client->getServiceNotificationsUserId() || $isUpgrade)) {
            $receivedGiftId = (string) $message->id;
        }

        $object = ['gift' => JsonGift::uniqueGift($content['gift'], $client)];
        if ($receivedGiftId !== '') {
            $object['owned_gift_id'] = $receivedGiftId;
            if (array_key_exists('transfer_stars', $action)) {
                $object['transfer_star_count'] = max(0, min((int) $action['transfer_stars'], 1 << 51));
                $nextTransferDate = max(0, (int) ($action['can_transfer_at'] ?? 0));
                if ($nextTransferDate > 0) {
                    $object['next_transfer_date'] = $nextTransferDate;
                }
            }
        }

        $resale = self::resalePrice($action['resale_amount'] ?? null);
        if (!empty($action['craft'])) {
            $object['origin'] = 'craft';
        } elseif (!empty($action['from_offer'])) {
            $object['origin'] = 'offer';
        } elseif (!empty($action['assigned'])) {
            $object['origin'] = 'blockchain';
        } elseif ($isPrepaidUpgrade) {
            $object['origin'] = 'gifted_upgrade';
        } elseif ($resale !== null) {
            $object['origin'] = 'resale';
            if ($resale[0] === 'XTR') {
                $object['last_resale_star_count'] = $resale[1];
                $object['last_resale_currency'] = 'XTR';
                $object['last_resale_amount'] = $resale[1];
            } else {
                $object['last_resale_currency'] = 'TON';
                $object['last_resale_amount'] = $resale[1] * 10000000;
            }
        } elseif ($isUpgrade) {
            $object['origin'] = 'upgrade';
        } else {
            $object['origin'] = 'transfer';
        }
        self::addGiftText($object, $action, $client);
        if (!empty($action['name_hidden'])) {
            $object['is_private'] = true;
        }

        return $object;
    }

    /**
     * StarGiftResalePrice(StarsAmount): the currency and amount (Stars or gram cents), null when empty.
     *
     * @param array<string, mixed>|null $amount
     * @return array{0: string, 1: int}|null
     */
    private static function resalePrice(?array $amount): ?array
    {
        switch ($amount['_'] ?? '') {
            case 'starsAmount':
                $stars = max(0, min((int) ($amount['amount'] ?? 0), 1 << 51));

                return $stars === 0 ? null : ['XTR', $stars];
            case 'starsTonAmount':
                $grams = intdiv(max(0, (int) ($amount['amount'] ?? 0)), 10000000);

                return $grams === 0 ? null : ['TON', $grams];
        }

        return null;
    }

    /**
     * StarGiftId(dialog_id, saved_id): gifts of channel chats.
     */
    private static function dialogGiftId(int $chatId, int $savedId): string
    {
        if (ChatId::type($chatId) !== ChatId::TYPE_SUPERGROUP || $savedId === 0) {
            return '';
        }

        return $chatId.'_'.$savedId;
    }

    /**
     * @param array<string, mixed> $object
     * @param array<string, mixed> $action
     */
    private static function addGiftText(array &$object, array $action, Client $client): void
    {
        $text = FormattedText::fromTextWithEntities($action['message'] ?? null, false, false);
        if ($text->text !== '') {
            $object['text'] = $text->text;
            if ($text->entities !== []) {
                $object['entities'] = JsonEntity::vector($text->entities, $client);
            }
        }
    }

    private const SUGGESTED_POST_FIELDS = [
        'messageSuggestedPostApprovalFailed' => 'suggested_post_approval_failed',
        'messageSuggestedPostApproved' => 'suggested_post_approved',
        'messageSuggestedPostDeclined' => 'suggested_post_declined',
        'messageSuggestedPostPaid' => 'suggested_post_paid',
        'messageSuggestedPostRefunded' => 'suggested_post_refunded',
    ];

    /**
     * Client::choose_added_member_id.
     *
     * @param list<int> $userIds
     */
    private static function chooseAddedMemberId(array $userIds, Client $client): int
    {
        foreach ($userIds as $userId) {
            if ($userId === $client->getMyId()) {
                return $userId;
            }
        }

        return $userIds[0] ?? 0;
    }

    /**
     * @param list<int> $userIds
     * @return list<array<string, mixed>>
     */
    public static function users(array $userIds, Client $client): array
    {
        return array_map(static fn (int $userId): array => JsonUser::make($userId, $client), $userIds);
    }

    /**
     * Client::JsonMessageSender.
     *
     * @param array<string, mixed> $sender td_api MessageSender
     * @return array<string, mixed>
     */
    public static function sender(array $sender, Client $client): array
    {
        return $sender['_'] === 'messageSenderUser'
            ? JsonUser::make((int) $sender['user_id'], $client)
            : JsonChat::make((int) $sender['chat_id'], $client);
    }

    /**
     * Client::JsonInaccessibleMessage.
     *
     * @return array<string, mixed>
     */
    public static function inaccessibleMessage(int $chatId, int $messageId, Client $client): array
    {
        return ['message_id' => $messageId, 'chat' => JsonChat::make($chatId, $client), 'date' => 0];
    }

    /**
     * Client::JsonOrderInfo.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public static function orderInfo(array $order): array
    {
        $object = [];
        if ($order['name'] !== '') {
            $object['name'] = $order['name'];
        }
        if ($order['phone_number'] !== '') {
            $object['phone_number'] = $order['phone_number'];
        }
        if ($order['email_address'] !== '') {
            $object['email'] = $order['email_address'];
        }
        if ($order['shipping_address'] !== null) {
            $address = $order['shipping_address'];
            $object['shipping_address'] = [
                'country_code' => $address['country_code'],
                'state' => $address['state'],
                'city' => $address['city'],
                'street_line1' => $address['street_line1'],
                'street_line2' => $address['street_line2'],
                'post_code' => $address['postal_code'],
            ];
        }

        return $object === [] ? (object) [] : $object;
    }

    /**
     * Client::JsonSharedUser.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private static function sharedUser(array $user): array
    {
        $object = ['user_id' => $user['user_id']];
        if ($user['first_name'] !== '') {
            $object['first_name'] = $user['first_name'];
        }
        if ($user['last_name'] !== '') {
            $object['last_name'] = $user['last_name'];
        }
        if ($user['username'] !== '') {
            $object['username'] = $user['username'];
        }
        if ($user['photo'] !== null) {
            $object['photo'] = JsonMedia::photo($user['photo']);
        }

        return $object;
    }

    /**
     * Client::JsonSuggestedPost* service objects.
     *
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private static function suggestedPost(array $content, int $chatId, Client $client): array
    {
        $object = [];
        $post = $client->getMessage($chatId, $content['suggested_post_message_id'], true);
        if ($post !== null) {
            $object['suggested_post_message'] = JsonMessage::make($post, false, $client);
        }
        switch ($content['_']) {
            case 'messageSuggestedPostApprovalFailed':
                $object['price'] = self::suggestedPostPrice($content['price']);
                break;
            case 'messageSuggestedPostApproved':
                if ($content['price'] !== null) {
                    $object['price'] = self::suggestedPostPrice($content['price']);
                }
                $object['send_date'] = $content['send_date'];
                break;
            case 'messageSuggestedPostDeclined':
                if ($content['comment'] !== '') {
                    $object['comment'] = $content['comment'];
                }
                break;
            case 'messageSuggestedPostPaid':
                $price = $content['price'] ?? [];
                if (($price['_'] ?? '') === 'starsTonAmount') {
                    $object['currency'] = 'TON';
                    $object['amount'] = (int) $price['amount'];
                } else {
                    $object['currency'] = 'XTR';
                    $amount = ['amount' => (int) ($price['amount'] ?? 0)];
                    if (!empty($price['nanos'])) {
                        $amount['nanostar_amount'] = (int) $price['nanos'];
                    }
                    $object['star_amount'] = $amount;
                }
                break;
            case 'messageSuggestedPostRefunded':
                $object['reason'] = $content['reason'];
                break;
        }

        return $object;
    }

    /**
     * Client::JsonSuggestedPostPrice of a TL StarsAmount/StarsTonAmount.
     *
     * @param array<string, mixed>|null $price
     * @return array<string, mixed>
     */
    public static function suggestedPostPrice(?array $price): array
    {
        if (($price['_'] ?? '') === 'starsTonAmount') {
            return ['currency' => 'TON', 'amount' => (int) $price['amount']];
        }

        return ['currency' => 'XTR', 'amount' => (int) ($price['amount'] ?? 0)];
    }
}
