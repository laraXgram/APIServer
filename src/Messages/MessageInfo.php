<?php

declare(strict_types=1);

namespace LaraGram\Server\Messages;

/**
 * A message as the Bot API layer sees it (Client::MessageInfo).
 *
 * `content`, `forward_origin`, `reply_to_message` and the like keep TDLib's
 * td_api shapes as arrays (`['_' => 'messageText', ...]`), so the JSON
 * builders map one to one onto the official ones.
 */
final class MessageInfo
{
    /** Server message identifier (the Bot API message_id). */
    public int $id = 0;

    public int $senderUserId = 0;

    public int $senderChatId = 0;

    public int $chatId = 0;

    /** @var array<string, mixed>|null td_api MessageSender */
    public ?array $receiverId = null;

    public int $date = 0;

    public int $editDate = 0;

    public int $initialSendDate = 0;

    public int $senderBoostCount = 0;

    public int $ephemeralMessageId = 0;

    /** The receiver of an ephemeral message. */
    public int $receiverUserId = 0;

    public int $paidMessageStarCount = 0;

    public int $guestQueryId = 0;

    /** @var array<string, mixed>|null td_api MessageOrigin */
    public ?array $forwardOrigin = null;

    public string $authorSignature = '';

    public string $senderTag = '';

    public ?MessageInfo $businessReplyToMessage = null;

    /** @var list<MessageInfo> */
    public array $referenceMessages = [];

    /** @var array<string, mixed>|null td_api messageReplyToMessage */
    public ?array $replyToMessage = null;

    /** @var array<string, mixed>|null td_api messageReplyToStory */
    public ?array $replyToStory = null;

    /** @var array<string, mixed>|null td_api MessageTopic */
    public ?array $topicId = null;

    public int $mediaAlbumId = 0;

    public int $viaBotUserId = 0;

    /** @var array<string, mixed>|null td_api MessageSender */
    public ?array $guestBotCallerId = null;

    /** @var array<string, mixed> td_api MessageContent */
    public array $content = ['_' => 'messageUnsupported'];

    /** @var array<string, mixed>|null */
    public ?array $suggestedPostInfo = null;

    /** @var array<string, mixed>|null TL ReplyMarkup */
    public ?array $replyMarkup = null;

    public string $businessConnectionId = '';

    public int $senderBusinessBotUserId = 0;

    public int $effectId = 0;

    public bool $canBeSaved = true;

    public bool $isAutomaticForward = false;

    public bool $isFromOffline = false;

    public bool $isScheduled = false;

    public bool $isPaidPost = false;

    public bool $isOutgoing = false;

    public bool $isSelfDestruct = false;

    public bool $isImported = false;

    public bool $isContentChanged = true;
}
