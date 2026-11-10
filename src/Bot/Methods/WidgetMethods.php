<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\FormattedText;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Support\TdInteger;
use LaraGram\Server\Support\Utf8;

/**
 * Messages with HTML widgets: sending them, editing their data and the
 * widget data updates (Client::process_*_widget_* and TDLib's WidgetManager).
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait WidgetMethods
{
    public function processSendWidgetQuery(Query $query): void
    {
        $html = self::getRequiredStringArg($query, 'html');
        $width = self::getIntegerArg($query, 'width', 0, 0, self::MAX_LENGTH);
        $height = self::getIntegerArg($query, 'height', 0, 0, self::MAX_LENGTH);
        $publicData = $query->arg('public_data');
        $chatData = $query->arg('chat_data');
        $caption = $this->getCaption($query);

        $this->doSendMessage([
            '_' => 'inputMessageWidget',
            'width' => $width,
            'height' => $height,
            'html' => $html,
            'public_data' => $publicData,
            'chat_data' => $chatData,
            'caption' => $caption,
            'show_caption_above_media' => self::boolArg($query, 'show_caption_above_media'),
            'allow_queries' => self::boolArg($query, 'allow_queries'),
            'is_shared' => self::boolArg($query, 'is_shared'),
        ], $query);
    }

    public function processSendSharedWidgetQuery(Query $query): void
    {
        $widgetId = TdInteger::toInt64($query->arg('widget_id'));
        $chatData = $query->arg('chat_data');
        $caption = $this->getCaption($query);

        $this->doSendMessage([
            '_' => 'inputMessageSharedWidget',
            'widget_id' => $widgetId,
            'chat_data' => $chatData,
            'caption' => $caption,
            'show_caption_above_media' => self::boolArg($query, 'show_caption_above_media'),
        ], $query);
    }

    public function processEditMessageWidgetDataQuery(Query $query): void
    {
        $chatIdStr = $query->arg('chat_id');
        $messageId = self::getMessageId($query);
        $editPublicData = $query->hasArg('public_data');
        $publicData = $query->arg('public_data');
        $editChatData = $query->hasArg('chat_data');
        $chatData = $query->arg('chat_data');

        if ($chatIdStr === '' && $messageId === 0) {
            // EditInlineMessageWidgetDataQuery
            $id = self::parseInlineMessageId(self::getInlineMessageId($query));
            $this->invokeOnDc((int) $id['dc_id'], 'messages.editInlineBotMessageWidgetData', self::untl(self::tl('messages.editInlineBotMessageWidgetData', [
                'id' => $id,
                'public_data' => $editPublicData ? self::cleanInputStringOrFail($publicData) : null,
                'chat_data' => $editChatData ? self::cleanInputStringOrFail($chatData) : null,
            ])));
            $query->answerQuery(true);

            return;
        }

        [$chatId, $messageId] = $this->checkMessage($chatIdStr, $messageId, false, self::ACCESS_READ, 'message with widget to edit');

        // WidgetManager::edit_message_widget_data
        if ($editPublicData || $editChatData) {
            $result = $this->invokeOrFail('messages.editMessageWidgetData', self::untl(self::tl('messages.editMessageWidgetData', [
                'peer' => $this->inputPeer($chatId),
                'id' => $messageId,
                'public_data' => $editPublicData ? self::cleanInputStringOrFail($publicData) : null,
                'chat_data' => $editChatData ? self::cleanInputStringOrFail($chatData) : null,
            ])));
            foreach ($this->updatesOf(is_array($result) ? $result : []) as $update) {
                if (($update['_'] ?? '') === 'updateMessageWidgetData') {
                    $this->onUpdateMessageWidgetData($update);
                }
            }
        }
        $query->answerQuery(true);
    }

    public function processEditSharedWidgetDataQuery(Query $query): void
    {
        $widgetId = TdInteger::toInt64($query->arg('widget_id'));
        $publicData = $query->arg('public_data');
        $cacheTime = self::getIntegerArg($query, 'cache_time', 0, -1, 3601);

        // WidgetManager::edit_widget_data
        if ($widgetId === 0) {
            throw new QueryError(400, 'Invalid widget identifier specified');
        }
        if ($cacheTime !== 0 && ($cacheTime < 5 || $cacheTime > 3600)) {
            throw new QueryError(400, 'Invalid cache time specified');
        }

        $this->invokeOrFail('messages.editWidgetData', self::untl(self::tl('messages.editWidgetData', [
            'widget_id' => $widgetId,
            'public_data' => self::cleanInputStringOrFail($publicData),
            'cache_time' => $cacheTime > 0 ? $cacheTime : null,
        ])));
        $query->answerQuery(true);
    }

    /**
     * MessagesManager::on_update_message_widget_data: the stored widget gets the new data.
     *
     * @param array<string, mixed> $update TL updateMessageWidgetData
     */
    protected function onUpdateMessageWidgetData(array $update): void
    {
        $chatId = ChatId::fromPeer($update['peer'] ?? null);
        $message = $chatId === 0 ? null : $this->getMessage($chatId, (int) ($update['msg_id'] ?? 0), true);
        if ($message === null || ($message->content['_'] ?? '') !== 'messageWidget') {
            return;
        }

        $message->content['public_data'] = (string) ($update['public_data'] ?? '');
        $message->content['chat_data'] = (string) ($update['chat_data'] ?? '');
    }

    /**
     * create_input_message_content of inputMessageWidget and inputMessageSharedWidget; for an
     * inline message the checks of InlineQueriesManager::get_inline_message.
     *
     * @param array<string, mixed> $content
     * @return array{type: string, text: FormattedText, media: array<string, mixed>|null, no_webpage: bool, invert_media: bool}
     */
    protected function getInputWidgetContent(array $content, bool $isInline = false): array
    {
        $caption = $this->getOutgoingFormattedText($content['caption'] ?? new FormattedText(), true);
        if (!$isInline && Utf8::length($caption->text) > self::MESSAGE_CAPTION_LENGTH_MAX) {
            throw new QueryError(400, 'Message caption is too long');
        }
        $chatData = TextFormatting::cleanInputString($content['chat_data']);

        if ($content['_'] === 'inputMessageSharedWidget') {
            if ($content['widget_id'] === 0) {
                throw new QueryError(400, 'Invalid widget identifier specified');
            }
            if ($chatData === null) {
                throw new QueryError(400, 'Widget chat data must be encoded in UTF-8');
            }
            $media = self::tl('inputMediaWidgetId', [
                'widget_id' => $content['widget_id'],
                'chat_data' => $chatData !== '' ? $chatData : null,
            ]);
        } else {
            if ($content['width'] < 0 || $content['height'] < 0) {
                throw new QueryError(400, 'Invalid widget size specified');
            }
            $html = TextFormatting::cleanInputString($content['html']);
            if ($html === null) {
                throw new QueryError(400, 'Widget HTML code must be encoded in UTF-8');
            }
            if ($html === '') {
                throw new QueryError(400, 'Widget HTML code must be non-empty');
            }
            $publicData = TextFormatting::cleanInputString($content['public_data']);
            if ($publicData === null) {
                throw new QueryError(400, 'Widget public data must be encoded in UTF-8');
            }
            if ($chatData === null) {
                throw new QueryError(400, 'Widget chat data must be encoded in UTF-8');
            }
            $media = self::tl('inputMediaWidget', [
                'allow_queries' => $content['allow_queries'],
                'shared_widget' => $content['is_shared'],
                'width' => $content['width'],
                'height' => $content['height'],
                'html' => $html,
                'public_data' => $publicData !== '' ? $publicData : null,
                'chat_data' => $chatData !== '' ? $chatData : null,
            ]);
        }

        return [
            'type' => 'Widget',
            'text' => $caption,
            'media' => $media,
            'no_webpage' => false,
            'invert_media' => $content['show_caption_above_media'],
        ];
    }
}
