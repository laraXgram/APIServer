<?php

declare(strict_types=1);

namespace LaraGram\Server\Messages;

use LaraGram\Server\Bot\Client;
use LaraGram\Server\Entities\FormattedText;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Objects\JsonGift;

/**
 * td::get_action_message_content + get_message_content_object for service
 * messages: a TL MessageAction as the td_api MessageContent the Bot API layer
 * reads. Contents the Bot API never shows keep only their name, so
 * Client::need_skip_update_message can still recognize them.
 */
final class ServiceConverter
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @param array<string, mixed> $message TL messageService
     * @param int $replyToMessageId the same-chat message the reply header points to
     * @return array<string, mixed>
     */
    public function convert(array $message, int $chatId, int $replyToMessageId): array
    {
        $action = $message['action'] ?? [];

        switch ($action['_'] ?? '') {
            case 'messageActionChatCreate':
                return ['_' => 'messageBasicGroupChatCreate', 'title' => (string) $action['title'],
                    'member_user_ids' => array_map('intval', $action['users'] ?? [])];

            case 'messageActionChatEditTitle':
                return ['_' => 'messageChatChangeTitle', 'title' => (string) $action['title']];

            case 'messageActionChatEditPhoto':
                $photo = MediaConverter::photo($action['photo'] ?? [], \LaraGram\Server\Files\FileType::PHOTO);
                if ($photo === null) {
                    break;
                }

                return ['_' => 'messageChatChangePhoto', 'photo' => $photo];

            case 'messageActionChatDeletePhoto':
                return ['_' => 'messageChatDeletePhoto'];

            case 'messageActionHistoryClear':
                return ['_' => 'messageChatDeleteHistory'];

            case 'messageActionChatAddUser':
                return ['_' => 'messageChatAddMembers', 'member_user_ids' => array_map('intval', $action['users'] ?? [])];

            case 'messageActionChatJoinedByLink':
                return ['_' => 'messageChatJoinByLink'];

            case 'messageActionChatJoinedByRequest':
                return ['_' => 'messageChatJoinByRequest'];

            case 'messageActionChatDeleteUser':
                return ['_' => 'messageChatDeleteMember', 'user_id' => (int) $action['user_id']];

            case 'messageActionChatMigrateTo':
                return ['_' => 'messageChatUpgradeTo', 'supergroup_id' => (int) $action['channel_id']];

            case 'messageActionChannelCreate':
                return ['_' => 'messageSupergroupChatCreate', 'title' => (string) $action['title']];

            case 'messageActionChannelMigrateFrom':
                return ['_' => 'messageChatUpgradeFrom', 'title' => (string) $action['title'], 'basic_group_id' => (int) $action['chat_id']];

            case 'messageActionPinMessage':
                return ['_' => 'messagePinMessage', 'message_id' => $replyToMessageId];

            case 'messageActionGameScore':
                return ['_' => 'messageGameScore', 'game_message_id' => $replyToMessageId, 'game_id' => (int) $action['game_id'], 'score' => (int) $action['score']];

            case 'messageActionPaymentSentMe':
                $info = $action['info'] ?? null;
                $order = null;
                if ($info !== null) {
                    $address = $info['shipping_address'] ?? null;
                    $order = [
                        'name' => (string) ($info['name'] ?? ''),
                        'phone_number' => (string) ($info['phone'] ?? ''),
                        'email_address' => (string) ($info['email'] ?? ''),
                        'shipping_address' => $address === null ? null : [
                            'country_code' => (string) $address['country_iso2'],
                            'state' => (string) $address['state'],
                            'city' => (string) $address['city'],
                            'street_line1' => (string) $address['street_line1'],
                            'street_line2' => (string) $address['street_line2'],
                            'postal_code' => (string) $address['post_code'],
                        ],
                    ];
                }

                return [
                    '_' => 'messagePaymentSuccessfulBot',
                    'currency' => (string) $action['currency'],
                    'total_amount' => max(0, (int) $action['total_amount']),
                    'subscription_until_date' => (int) ($action['subscription_until_date'] ?? 0),
                    'is_recurring' => !empty($action['recurring_used']),
                    'is_first_recurring' => !empty($action['recurring_init']),
                    'invoice_payload' => (string) $action['payload'],
                    'shipping_option_id' => (string) ($action['shipping_option_id'] ?? ''),
                    'order_info' => $order,
                    'telegram_payment_charge_id' => (string) ($action['charge']['id'] ?? ''),
                    'provider_payment_charge_id' => (string) ($action['charge']['provider_charge_id'] ?? ''),
                ];

            case 'messageActionPaymentRefunded':
                return [
                    '_' => 'messagePaymentRefunded',
                    'owner_id' => ChatId::fromPeer($action['peer'] ?? null),
                    'currency' => (string) $action['currency'],
                    'total_amount' => max(0, (int) $action['total_amount']),
                    'invoice_payload' => (string) ($action['payload'] ?? ''),
                    'telegram_payment_charge_id' => (string) ($action['charge']['id'] ?? ''),
                    'provider_payment_charge_id' => (string) ($action['charge']['provider_charge_id'] ?? ''),
                ];

            case 'messageActionBotAllowed':
                if (!empty($action['attach_menu'])) {
                    return ['_' => 'messageBotWriteAccessAllowed', 'reason' => ['_' => 'botWriteAccessAllowReasonAddedToAttachmentMenu']];
                }
                if (($action['domain'] ?? '') !== '') {
                    return ['_' => 'messageBotWriteAccessAllowed', 'reason' => ['_' => 'botWriteAccessAllowReasonConnectedWebsite', 'domain_name' => (string) $action['domain']]];
                }
                if (($action['app']['_'] ?? '') === 'botApp') {
                    return ['_' => 'messageBotWriteAccessAllowed', 'reason' => ['_' => 'botWriteAccessAllowReasonLaunchedWebApp', 'short_name' => (string) $action['app']['short_name']]];
                }
                if (!empty($action['from_request'])) {
                    return ['_' => 'messageBotWriteAccessAllowed', 'reason' => ['_' => 'botWriteAccessAllowReasonAcceptedRequest']];
                }

                return ['_' => 'messageUnsupported'];

            case 'messageActionSecureValuesSentMe':
                return ['_' => 'messagePassportDataReceived', 'values' => $action['values'] ?? [], 'credentials' => $action['credentials'] ?? []];

            case 'messageActionSecureValuesSent':
                return ['_' => 'messagePassportDataSent'];

            case 'messageActionContactSignUp':
                return ['_' => 'messageContactRegistered'];

            case 'messageActionGeoProximityReached':
                $traveler = ChatId::fromPeer($action['from_id'] ?? null);
                $watcher = ChatId::fromPeer($action['to_id'] ?? null);
                if ($traveler === 0 || $watcher === 0 || (int) $action['distance'] < 0) {
                    break;
                }

                return ['_' => 'messageProximityAlertTriggered', 'traveler_id' => self::sender($traveler), 'watcher_id' => self::sender($watcher), 'distance' => (int) $action['distance']];

            case 'messageActionGroupCall':
                if (isset($action['duration'])) {
                    return ['_' => 'messageVideoChatEnded', 'duration' => (int) $action['duration']];
                }

                return ['_' => 'messageVideoChatStarted'];

            case 'messageActionGroupCallScheduled':
                if ((int) $action['schedule_date'] <= 0) {
                    break;
                }

                return ['_' => 'messageVideoChatScheduled', 'start_date' => (int) $action['schedule_date']];

            case 'messageActionInviteToGroupCall':
                return ['_' => 'messageInviteVideoChatParticipants', 'user_ids' => array_map('intval', $action['users'] ?? [])];

            case 'messageActionSetMessagesTTL':
                return ['_' => 'messageChatSetMessageAutoDeleteTime', 'message_auto_delete_time' => (int) $action['period'],
                    'from_user_id' => (int) ($action['auto_setting_from'] ?? 0)];

            case 'messageActionSetChatTheme':
                return ['_' => 'messageChatSetTheme'];

            case 'messageActionWebViewDataSentMe':
                return ['_' => 'messageWebAppDataReceived', 'button_text' => (string) $action['text'], 'data' => (string) $action['data']];

            case 'messageActionWebViewDataSent':
                return ['_' => 'messageWebAppDataSent'];

            case 'messageActionTopicCreate':
                return [
                    '_' => 'messageForumTopicCreated',
                    'name' => (string) $action['title'],
                    'is_name_implicit' => !empty($action['title_missing']),
                    'icon' => ['color' => (int) $action['icon_color'], 'custom_emoji_id' => (int) ($action['icon_emoji_id'] ?? 0)],
                ];

            case 'messageActionTopicEdit':
                $editIsClosed = array_key_exists('closed', $action);
                $editIsHidden = array_key_exists('hidden', $action);
                $isClosed = !empty($action['closed']);
                $isHidden = !empty($action['hidden']);
                if ($editIsHidden && !(!$isHidden && $editIsClosed && !$isClosed)) {
                    return ['_' => 'messageForumTopicIsHiddenToggled', 'is_hidden' => $isHidden];
                }
                if ($editIsClosed) {
                    return ['_' => 'messageForumTopicIsClosedToggled', 'is_closed' => $isClosed];
                }

                return [
                    '_' => 'messageForumTopicEdited',
                    'name' => (string) ($action['title'] ?? ''),
                    'edit_icon_custom_emoji_id' => array_key_exists('icon_emoji_id', $action),
                    'icon_custom_emoji_id' => (int) ($action['icon_emoji_id'] ?? 0),
                ];

            case 'messageActionSuggestProfilePhoto':
                return ['_' => 'messageSuggestProfilePhoto'];

            case 'messageActionRequestedPeer':
                return ['_' => 'messageUnsupported'];

            case 'messageActionRequestedPeerSentMe':
                return $this->requestedPeers($action);

            case 'messageActionSetChatWallPaper':
                $background = self::chatBackground($action['wallpaper'] ?? null);
                if ($background === null) {
                    break;
                }

                return ['_' => 'messageChatSetBackground', 'background' => $background,
                    'old_background_message_id' => !empty($action['same']) ? $replyToMessageId : 0,
                    'only_for_self' => empty($action['for_both'])];

            case 'messageActionGiveawayLaunch':
                return ['_' => 'messageGiveawayCreated', 'star_count' => (int) ($action['stars'] ?? 0)];

            case 'messageActionGiveawayResults':
                return ['_' => 'messageGiveawayCompleted', 'giveaway_message_id' => $replyToMessageId,
                    'winner_count' => (int) $action['winners_count'], 'is_star_giveaway' => !empty($action['stars']),
                    'unclaimed_prize_count' => (int) $action['unclaimed_count']];

            case 'messageActionBoostApply':
                return ['_' => 'messageChatBoost', 'boost_count' => max(0, (int) $action['boosts'])];

            case 'messageActionPaidMessagesRefunded':
                return ['_' => 'messagePaidMessagesRefunded'];

            case 'messageActionPaidMessagesPrice':
                $stars = (int) $action['stars'];
                if ($this->client->isBroadcastChannel($chatId)) {
                    $allowed = !empty($action['broadcast_messages_allowed']);

                    return ['_' => 'messageDirectMessagePriceChanged', 'is_enabled' => $allowed, 'paid_message_star_count' => $allowed ? $stars : 0];
                }

                return ['_' => 'messagePaidMessagePriceChanged', 'paid_message_star_count' => $stars];

            case 'messageActionConferenceCall':
                return ['_' => 'messageGroupCall'];

            case 'messageActionPhoneCall':
                return ['_' => 'messageCall'];

            case 'messageActionScreenshotTaken':
                return ['_' => 'messageScreenshotTaken'];

            case 'messageActionCustomAction':
                return ['_' => 'messageCustomServiceAction', 'text' => (string) $action['message']];

            case 'messageActionGiftPremium':
                return ['_' => 'messageGiftedPremium'];

            case 'messageActionGiftCode':
                return ['_' => 'messagePremiumGiftCode'];

            case 'messageActionGiftStars':
                return ['_' => 'messageGiftedStars'];

            case 'messageActionPrizeStars':
                return ['_' => 'messageGiveawayPrizeStars'];

            case 'messageActionGiftTon':
                return ['_' => 'messageGiftedGrams'];

            case 'messageActionSuggestBirthday':
                return ['_' => 'messageSuggestBirthdate'];

            case 'messageActionStarGift':
                $gift = JsonGift::fromServer($action['gift'] ?? [], false);
                if ($gift === null || $gift['is_unique']) {
                    break;
                }

                return ['_' => 'messageGift', 'gift' => $gift, 'action' => $action, 'is_prepaid_upgrade' => !empty($action['prepaid_upgrade'])];

            case 'messageActionStarGiftUnique':
                $gift = JsonGift::fromServer($action['gift'] ?? [], true);
                if ($gift === null || $gift['is_unique'] === !empty($action['refunded'])) {
                    break;
                }

                return !empty($action['refunded'])
                    ? ['_' => 'messageRefundedUpgradedGift', 'gift' => $gift, 'action' => $action]
                    : ['_' => 'messageUpgradedGift', 'gift' => $gift, 'action' => $action];

            case 'messageActionStarGiftPurchaseOffer':
                return ['_' => 'messageUpgradedGiftPurchaseOffer'];

            case 'messageActionStarGiftPurchaseOfferDeclined':
                return ['_' => 'messageUpgradedGiftPurchaseOfferRejected'];

            case 'messageActionTodoCompletions':
                return ['_' => 'messageChecklistTasksDone', 'checklist_message_id' => $replyToMessageId,
                    'marked_as_done_task_ids' => array_map('intval', $action['completed'] ?? []),
                    'marked_as_not_done_task_ids' => array_map('intval', $action['incompleted'] ?? [])];

            case 'messageActionTodoAppendTasks':
                return ['_' => 'messageChecklistTasksAdded', 'checklist_message_id' => $replyToMessageId, 'tasks' => $action['list'] ?? []];

            case 'messageActionSuggestedPostApproval':
                if (!empty($action['rejected'])) {
                    return ['_' => 'messageSuggestedPostDeclined', 'suggested_post_message_id' => $replyToMessageId,
                        'comment' => (string) ($action['reject_comment'] ?? '')];
                }
                if (!empty($action['balance_too_low'])) {
                    return ['_' => 'messageSuggestedPostApprovalFailed', 'suggested_post_message_id' => $replyToMessageId,
                        'price' => $action['price'] ?? null];
                }

                return ['_' => 'messageSuggestedPostApproved', 'suggested_post_message_id' => $replyToMessageId,
                    'price' => $action['price'] ?? null, 'send_date' => (int) ($action['schedule_date'] ?? 0)];

            case 'messageActionSuggestedPostSuccess':
                return ['_' => 'messageSuggestedPostPaid', 'suggested_post_message_id' => $replyToMessageId, 'price' => $action['price'] ?? null];

            case 'messageActionSuggestedPostRefund':
                return ['_' => 'messageSuggestedPostRefunded', 'suggested_post_message_id' => $replyToMessageId,
                    'reason' => !empty($action['payer_initiated']) ? 'payment_refunded' : 'post_deleted'];

            case 'messageActionNewCreatorPending':
                return ['_' => 'messageChatOwnerLeft', 'new_owner_user_id' => (int) $action['new_creator_id']];

            case 'messageActionChangeCreator':
                return ['_' => 'messageChatOwnerChanged', 'new_owner_user_id' => (int) $action['new_creator_id']];

            case 'messageActionNoForwardsToggle':
                return ['_' => 'messageChatHasProtectedContentToggled'];

            case 'messageActionNoForwardsRequest':
                return ['_' => 'messageChatHasProtectedContentDisableRequested'];

            case 'messageActionPollAppendAnswer':
                return $replyToMessageId === 0 ? ['_' => 'messageUnsupported']
                    : ['_' => 'messagePollOptionAdded', 'poll_message_id' => $replyToMessageId, 'answer' => $action['answer'] ?? []];

            case 'messageActionPollDeleteAnswer':
                return $replyToMessageId === 0 ? ['_' => 'messageUnsupported']
                    : ['_' => 'messagePollOptionDeleted', 'poll_message_id' => $replyToMessageId, 'answer' => $action['answer'] ?? []];

            case 'messageActionManagedBotCreated':
                return ['_' => 'messageManagedBotCreated', 'bot_user_id' => (int) $action['bot_id']];

            case 'messageActionChangeCommunity':
                return !empty($action['community_id'])
                    ? ['_' => 'messageChatAddedToCommunity', 'community_id' => (int) $action['community_id']]
                    : ['_' => 'messageChatRemovedFromCommunity'];

            case 'messageActionChatJoinedViaCommunity':
                return ['_' => 'messageChatJoinFromCommunity', 'community_id' => (int) $action['community_id']];
        }

        // explicit empty or wrong action
        return ['_' => 'messageText', 'text' => new FormattedText(), 'link_preview_options' => null];
    }

    /**
     * BackgroundInfo(td, wallpaper, allow_empty = true): the background and its type; null when invalid.
     *
     * @param array<string, mixed>|null $wallpaper TL WallPaper
     * @return array{type: array<string, mixed>, document: array<string, mixed>|null, dark_theme_dimming: int}|null
     */
    private static function chatBackground(?array $wallpaper): ?array
    {
        if ($wallpaper === null) {
            return null;
        }
        $settings = $wallpaper['settings'] ?? null;
        $isNoFile = ($wallpaper['_'] ?? '') === 'wallPaperNoFile';
        if ($isNoFile) {
            if ($settings === null) {
                return null;
            }
            $isPattern = false;
            $document = null;
        } else {
            if (($wallpaper['_'] ?? '') !== 'wallPaper' || (int) ($wallpaper['id'] ?? 0) <= 0) {
                return null;
            }
            $isPattern = !empty($wallpaper['pattern']);
            $document = MediaConverter::document($wallpaper['document'] ?? [], false, false, false, $isPattern ? 'pattern' : 'background');
            if ($document === null) {
                return null;
            }
        }

        // BackgroundType(has_no_file, is_pattern, settings)
        $intensity = 0;
        if ($settings !== null && array_key_exists('intensity', $settings)) {
            $intensity = (int) $settings['intensity'];
            if ($intensity < ($isPattern ? -100 : 0) || $intensity > 100) {
                $intensity = $isPattern ? 50 : 0;
            }
        }
        if ($isNoFile) {
            $type = (string) ($settings['emoticon'] ?? '') !== ''
                ? ['type' => 'chat_theme', 'theme_name' => (string) $settings['emoticon']]
                : ['type' => 'fill', 'fill' => self::backgroundFill($settings)];
        } elseif ($isPattern) {
            $type = ['type' => 'pattern', 'fill' => self::backgroundFill($settings), 'intensity' => abs($intensity),
                'is_inverted' => $intensity < 0, 'is_moving' => !empty($settings['motion'])];
        } else {
            $type = ['type' => 'wallpaper', 'is_blurred' => !empty($settings['blur']), 'is_moving' => !empty($settings['motion'])];
        }

        return ['type' => $type, 'document' => $document, 'dark_theme_dimming' => $isPattern ? 0 : $intensity];
    }

    /**
     * BackgroundFill(wallPaperSettings) + get_background_fill_object.
     *
     * @param array<string, mixed>|null $settings
     * @return array<string, mixed>
     */
    private static function backgroundFill(?array $settings): array
    {
        $color = static function (string $field) use ($settings): int {
            $value = (int) ($settings[$field] ?? 0);

            return $value >= -0x1000000 && $value <= 0xFFFFFF ? $value & 0xFFFFFF : 0;
        };
        $top = $bottom = 0;
        $rotation = 0;
        $third = $fourth = -1;
        if (isset($settings['background_color'])) {
            $top = $color('background_color');
        }
        if (isset($settings['fourth_background_color']) || isset($settings['third_background_color'])) {
            $bottom = $color('second_background_color');
            $third = $color('third_background_color');
            if (isset($settings['fourth_background_color'])) {
                $fourth = $color('fourth_background_color');
            }
        } elseif (isset($settings['second_background_color'])) {
            $bottom = $color('second_background_color');
            $rotation = (int) ($settings['rotation'] ?? 0);
            if ($rotation < 0 || $rotation >= 360 || $rotation % 45 !== 0) {
                $rotation = 0;
            }
        } else {
            $bottom = $top;
        }

        if ($third !== -1) {
            $colors = [$top, $bottom, $third, $fourth];
            if ($fourth === -1) {
                array_pop($colors);
            }

            return ['type' => 'freeform_gradient', 'colors' => $colors];
        }
        if ($top === $bottom) {
            return ['type' => 'solid', 'color' => $top];
        }

        return ['type' => 'gradient', 'top_color' => $top, 'bottom_color' => $bottom, 'rotation_angle' => $rotation];
    }

    /**
     * @return array<string, mixed>
     */
    public static function sender(int $chatId): array
    {
        return $chatId > 0
            ? ['_' => 'messageSenderUser', 'user_id' => $chatId]
            : ['_' => 'messageSenderChat', 'chat_id' => $chatId];
    }

    /**
     * messageActionRequestedPeerSentMe → messageUsersShared / messageChatShared.
     *
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private function requestedPeers(array $action): array
    {
        $peers = $action['peers'] ?? [];
        $users = [];
        $chats = [];
        foreach ($peers as $peer) {
            $photo = isset($peer['photo']) ? MediaConverter::photo($peer['photo']) : null;
            switch ($peer['_'] ?? '') {
                case 'requestedPeerUser':
                    $users[] = [
                        'user_id' => (int) $peer['user_id'],
                        'first_name' => (string) ($peer['first_name'] ?? ''),
                        'last_name' => (string) ($peer['last_name'] ?? ''),
                        'username' => (string) ($peer['username'] ?? ''),
                        'photo' => $photo,
                    ];
                    break;
                case 'requestedPeerChat':
                    $chats[] = ['chat_id' => ChatId::fromBasicGroup((int) $peer['chat_id']), 'title' => (string) ($peer['title'] ?? ''),
                        'username' => '', 'photo' => $photo];
                    break;
                case 'requestedPeerChannel':
                    $chats[] = ['chat_id' => ChatId::fromChannel((int) $peer['channel_id']), 'title' => (string) ($peer['title'] ?? ''),
                        'username' => (string) ($peer['username'] ?? ''), 'photo' => $photo];
                    break;
            }
        }

        $buttonId = (int) $action['button_id'];
        if ($chats !== [] && count($peers) === 1) {
            return ['_' => 'messageChatShared', 'chat' => $chats[0], 'button_id' => $buttonId];
        }
        if ($users === [] || count($users) !== count($peers)) {
            return ['_' => 'messageText', 'text' => new FormattedText(), 'link_preview_options' => null];
        }

        return ['_' => 'messageUsersShared', 'users' => $users, 'button_id' => $buttonId];
    }
}
