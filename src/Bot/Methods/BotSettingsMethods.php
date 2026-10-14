<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Files\FileType;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Support\JsonError;
use LaraGram\Server\Support\JsonValue;
use LaraGram\Server\Support\LinkChecker;
use LaraGram\Server\Support\TdJson;
use LaraGram\Server\Support\Utf8;
use LaraGram\Server\Td\ParticipantStatus;

/**
 * Commands, name, descriptions, menu button, default administrator rights
 * and the profile photo of the bot, with the TDLib parts they rely on
 * (BotCommand, BotCommandScope, BotInfoManager, BotMenuButton, UserManager).
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait BotSettingsMethods
{
    // ═════════════════════════════════════════════════════════════════
    //  Commands
    // ═════════════════════════════════════════════════════════════════

    public function processSetMyCommandsQuery(Query $query): void
    {
        $commands = $this->getBotCommands($query);
        $scope = $this->checkBotCommandScope($this->getBotCommandScope($query));
        $languageCode = $query->arg('language_code');

        // set_commands
        $inputScope = $this->getInputBotCommandScope($scope);
        self::validateBotLanguageCode($languageCode);
        $inputCommands = [];
        foreach ($commands as $command) {
            $text = TextFormatting::cleanInputString($command['command']);
            if ($text === null) {
                throw new QueryError(400, 'Command must be encoded in UTF-8');
            }
            $description = TextFormatting::cleanInputString($command['description']);
            if ($description === null) {
                throw new QueryError(400, 'Command description must be encoded in UTF-8');
            }

            $text = self::tdTrim($text);
            if (($text[0] ?? '') === '/') {
                $text = substr($text, 1);
            }
            if ($text === '') {
                throw new QueryError(400, 'Command must be non-empty');
            }
            if (Utf8::length($text) > 32) {
                throw new QueryError(400, 'Command length must not exceed 32');
            }

            $description = self::tdTrim($description);
            if ($description === '') {
                throw new QueryError(400, 'Command description must be non-empty');
            }
            if (Utf8::length($description) > 256) {
                throw new QueryError(400, 'Command description length must not exceed 256');
            }

            $inputCommands[] = self::tl('botCommand', ['ephemeral' => $command['is_ephemeral'], 'command' => $text, 'description' => $description]);
        }

        $this->invokeOrFail('bots.setBotCommands', ['scope' => $inputScope, 'lang_code' => $languageCode, 'commands' => $inputCommands]);
        $query->answerQuery(true);
    }

    public function processDeleteMyCommandsQuery(Query $query): void
    {
        $scope = $this->checkBotCommandScope($this->getBotCommandScope($query));
        $languageCode = $query->arg('language_code');

        $inputScope = $this->getInputBotCommandScope($scope);
        self::validateBotLanguageCode($languageCode);
        $this->invokeOrFail('bots.resetBotCommands', ['scope' => $inputScope, 'lang_code' => $languageCode]);
        $query->answerQuery(true);
    }

    public function processGetMyCommandsQuery(Query $query): void
    {
        $scope = $this->checkBotCommandScope($this->getBotCommandScope($query));
        $languageCode = $query->arg('language_code');

        $inputScope = $this->getInputBotCommandScope($scope);
        self::validateBotLanguageCode($languageCode);
        $result = $this->invokeOrFail('bots.getBotCommands', ['scope' => $inputScope, 'lang_code' => $languageCode]);

        $commands = [];
        foreach (is_array($result) ? $result : [] as $command) {
            $object = ['command' => (string) $command['command'], 'description' => (string) $command['description']];
            if (!empty($command['ephemeral'])) {
                $object['is_ephemeral'] = true;
            }
            $commands[] = $object;
        }
        $query->answerQuery($commands);
    }

    /**
     * Client::get_bot_commands.
     *
     * @return list<array{command: string, description: string, is_ephemeral: bool}>
     */
    private function getBotCommands(Query $query): array
    {
        $commands = $query->arg('commands');
        if ($commands === '') {
            return [];
        }
        $value = self::jsonArg($commands, 'Can\'t parse commands JSON object');

        return $this->parseArray($value, 'BotCommand', static function (JsonValue $value): array {
            if (!$value->isObject()) {
                throw new JsonError('expected an Object');
            }
            $object = $value->object();

            return [
                'command' => $object->getRequiredStringField('command'),
                'description' => $object->getRequiredStringField('description'),
                'is_ephemeral' => $object->getOptionalBoolField('is_ephemeral'),
            ];
        });
    }

    /**
     * Client::get_bot_command_scope.
     *
     * @return array{type: string, chat_id: string, user_id: int}
     */
    private function getBotCommandScope(Query $query): array
    {
        $scope = $query->arg('scope');
        if ($scope === '') {
            return ['type' => 'default', 'chat_id' => '', 'user_id' => 0];
        }
        $value = TdJson::tryDecode($scope);
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse BotCommandScope JSON object');
        }

        try {
            if (!$value->isObject()) {
                throw QueryError::badRequest('BotCommandScope must be an Object');
            }
            $object = $value->object();
            $type = $object->getRequiredStringField('type');
            if (in_array($type, ['default', 'all_private_chats', 'all_group_chats', 'all_chat_administrators'], true)) {
                return ['type' => $type, 'chat_id' => '', 'user_id' => 0];
            }
            if ($type !== 'chat' && $type !== 'chat_administrators' && $type !== 'chat_member') {
                throw QueryError::badRequest('Unsupported type specified');
            }

            $chatId = $object->getRequiredStringField('chat_id');
            if ($chatId === '') {
                throw QueryError::badRequest('Empty chat_id specified');
            }
            if ($type !== 'chat_member') {
                return ['type' => $type, 'chat_id' => $chatId, 'user_id' => 0];
            }
            $userId = $object->getRequiredLongField('user_id');
            if ($userId <= 0) {
                throw QueryError::badRequest('Invalid user_id specified');
            }

            return ['type' => $type, 'chat_id' => $chatId, 'user_id' => $userId];
        } catch (JsonError|QueryError $e) {
            throw QueryError::badRequest('Can\'t parse BotCommandScope: '.$e->getMessage());
        }
    }

    /**
     * Client::check_bot_command_scope: the chat of the scope, resolved.
     *
     * @param array{type: string, chat_id: string, user_id: int} $scope
     * @return array{type: string, chat_id: int, user_id: int}
     */
    private function checkBotCommandScope(array $scope): array
    {
        if ($scope['chat_id'] === '') {
            return ['type' => $scope['type'], 'chat_id' => 0, 'user_id' => 0];
        }
        $chatId = $this->checkChat($scope['chat_id'], self::ACCESS_READ_MEMBERS);
        if ($scope['type'] === 'chat_member') {
            $this->checkUserNoFail($scope['user_id']);
        }

        return ['type' => $scope['type'], 'chat_id' => $chatId, 'user_id' => $scope['user_id']];
    }

    /**
     * BotCommandScope::get_bot_command_scope + get_input_bot_command_scope.
     *
     * @param array{type: string, chat_id: int, user_id: int} $scope
     * @return array<string, mixed>
     */
    private function getInputBotCommandScope(array $scope): array
    {
        switch ($scope['type']) {
            case 'default':
                return ['_' => 'botCommandScopeDefault'];
            case 'all_private_chats':
                return ['_' => 'botCommandScopeUsers'];
            case 'all_group_chats':
                return ['_' => 'botCommandScopeChats'];
            case 'all_chat_administrators':
                return ['_' => 'botCommandScopeChatAdmins'];
        }

        $chatId = $scope['chat_id'];
        // UserManager::get_input_user: bots may use unknown users
        $this->checkChatAccess($chatId, self::ACCESS_READ);

        switch (ChatId::type($chatId)) {
            case ChatId::TYPE_PRIVATE:
                if ($scope['type'] !== 'chat') {
                    throw new QueryError(400, 'Can\'t use specified scope in private chats');
                }
                break;
            case ChatId::TYPE_SUPERGROUP:
                if ($this->isBroadcastChannel($chatId)) {
                    throw new QueryError(400, 'Can\'t change commands in channel chats');
                }
                break;
        }

        $peer = $this->inputPeer($chatId);

        return match ($scope['type']) {
            'chat' => ['_' => 'botCommandScopePeer', 'peer' => $peer],
            'chat_administrators' => ['_' => 'botCommandScopePeerAdmins', 'peer' => $peer],
            default => ['_' => 'botCommandScopePeerUser', 'peer' => $peer, 'user_id' => $this->inputUser($scope['user_id'])],
        };
    }

    /**
     * td::validate_bot_language_code.
     */
    private static function validateBotLanguageCode(string $languageCode): void
    {
        if ($languageCode !== '' && preg_match('/^[a-z]{2}$/', $languageCode) !== 1) {
            throw new QueryError(400, 'Invalid language code specified');
        }
    }

    /**
     * td::trim: spaces, tabs, line breaks and NUL bytes on both ends.
     */
    private static function tdTrim(string $value): string
    {
        return trim($value, " \t\r\n\0\x0B");
    }

    // ═════════════════════════════════════════════════════════════════
    //  Name and descriptions (BotInfoManager)
    // ═════════════════════════════════════════════════════════════════

    public function processSetMyNameQuery(Query $query): void
    {
        $this->setBotInfo($query->arg('language_code'), 'name', $query->arg('name'));
        $query->answerQuery(true);
    }

    public function processGetMyNameQuery(Query $query): void
    {
        $query->answerQuery(['name' => $this->getBotInfo($query->arg('language_code'), 'name')]);
    }

    public function processSetMyDescriptionQuery(Query $query): void
    {
        $this->setBotInfo($query->arg('language_code'), 'description', $query->arg('description'));
        $query->answerQuery(true);
    }

    public function processGetMyDescriptionQuery(Query $query): void
    {
        $query->answerQuery(['description' => $this->getBotInfo($query->arg('language_code'), 'description')]);
    }

    public function processSetMyShortDescriptionQuery(Query $query): void
    {
        $this->setBotInfo($query->arg('language_code'), 'about', $query->arg('short_description'));
        $query->answerQuery(true);
    }

    public function processGetMyShortDescriptionQuery(Query $query): void
    {
        $query->answerQuery(['short_description' => $this->getBotInfo($query->arg('language_code'), 'about')]);
    }

    /**
     * BotInfoManager::set_bot_* (SetBotInfoQuery).
     */
    private function setBotInfo(string $languageCode, string $field, string $value): void
    {
        $value = TextFormatting::cleanInputString($value);
        if ($value === null) {
            throw new QueryError(400, 'Strings must be encoded in UTF-8');
        }
        self::validateBotLanguageCode($languageCode);

        $this->invokeOrFail('bots.setBotInfo', ['lang_code' => $languageCode, $field => $value]);
        if ($field === 'name') {
            // UserManager::reload_user
            $this->loadChat($this->getMyId());
        } else {
            $this->forgetChatFull($this->getMyId());
        }
    }

    /**
     * BotInfoManager::get_bot_* (GetBotInfoQuery).
     */
    private function getBotInfo(string $languageCode, string $field): string
    {
        self::validateBotLanguageCode($languageCode);
        $result = $this->invokeOrFail('bots.getBotInfo', ['lang_code' => $languageCode]);

        return (string) ($result[$field] ?? '');
    }

    // ═════════════════════════════════════════════════════════════════
    //  Menu button
    // ═════════════════════════════════════════════════════════════════

    public function processSetChatMenuButtonQuery(Query $query): void
    {
        $menuButton = $this->getBotMenuButtonArg($query);
        $userId = 0;
        if ($query->hasArg('chat_id')) {
            $userId = self::getUserIdArg($query, 'chat_id');
            $this->checkUser($userId);
        }

        // set_menu_button
        if ($menuButton === null) {
            $inputButton = ['_' => 'botMenuButtonCommands'];
        } elseif ($menuButton['text'] === '') {
            if ($menuButton['url'] !== 'default') {
                throw new QueryError(400, 'Menu button text must be non-empty');
            }
            $inputButton = ['_' => 'botMenuButtonDefault'];
        } else {
            $text = TextFormatting::cleanInputString($menuButton['text']);
            if ($text === null) {
                throw new QueryError(400, 'Menu button text must be encoded in UTF-8');
            }
            $url = TextFormatting::cleanInputString($menuButton['url']);
            if ($url === null) {
                throw new QueryError(400, 'Menu button URL must be encoded in UTF-8');
            }
            try {
                $url = LinkChecker::check($url, true, !$this->isTestDc());
            } catch (\InvalidArgumentException $e) {
                throw new QueryError(400, 'Menu button Web App '.$e->getMessage());
            }
            $inputButton = ['_' => 'botMenuButton', 'text' => $text, 'url' => $url];
        }

        $this->invokeOrFail('bots.setBotMenuButton', [
            'user_id' => $userId > 0 ? $this->inputUser($userId) : ['_' => 'inputUserEmpty'],
            'button' => $inputButton,
        ]);
        $query->answerQuery(true);
    }

    public function processGetChatMenuButtonQuery(Query $query): void
    {
        $userId = 0;
        if ($query->hasArg('chat_id')) {
            $userId = self::getUserIdArg($query, 'chat_id');
            $this->checkUser($userId);
        }

        $result = $this->invokeOrFail('bots.getBotMenuButton', [
            'user_id' => $userId > 0 ? $this->inputUser($userId) : ['_' => 'inputUserEmpty'],
        ]);

        // get_bot_menu_button + Client::JsonBotMenuButton
        [$text, $url] = match ($result['_'] ?? '') {
            'botMenuButtonDefault' => ['', 'default'],
            'botMenuButton' => (string) $result['text'] === '' ? ['', ''] : [(string) $result['text'], (string) $result['url']],
            default => ['', ''],
        };
        if ($text === '') {
            $query->answerQuery(['type' => $url === '' ? 'commands' : 'default']);

            return;
        }
        $query->answerQuery(['type' => 'web_app', 'text' => $text, 'web_app' => ['url' => $url]]);
    }

    /**
     * Client::get_bot_menu_button.
     *
     * @return array{text: string, url: string}|null
     */
    private function getBotMenuButtonArg(Query $query): ?array
    {
        $menuButton = $query->arg('menu_button');
        if ($menuButton === '') {
            return ['text' => '', 'url' => 'default'];
        }
        $value = TdJson::tryDecode($menuButton);
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse menu button JSON object');
        }

        try {
            if (!$value->isObject()) {
                throw QueryError::badRequest('MenuButton must be an Object');
            }
            $object = $value->object();
            $type = $object->getRequiredStringField('type');
            if ($type === 'default') {
                return ['text' => '', 'url' => 'default'];
            }
            if ($type === 'commands') {
                return null;
            }
            if ($type === 'web_app') {
                $text = $object->getRequiredStringField('text');
                $webApp = $object->extractRequiredField('web_app', TdJson::OBJECT)->object();

                return ['text' => $text, 'url' => $webApp->getRequiredStringField('url')];
            }

            throw QueryError::badRequest('MenuButton has unsupported type');
        } catch (JsonError|QueryError $e) {
            throw QueryError::badRequest('Can\'t parse menu button: '.$e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  Default administrator rights
    // ═════════════════════════════════════════════════════════════════

    public function processSetMyDefaultAdministratorRightsQuery(Query $query): void
    {
        $forChannels = self::boolArg($query, 'for_channels');
        $rights = $this->getChatAdministratorRightsArg($query);

        $flags = $rights === null ? 0 : ParticipantStatus::administratorRights($rights, $forChannels ? 'broadcast' : 'megagroup');
        try {
            $this->invoke($forChannels ? 'bots.setBotBroadcastDefaultAdminRights' : 'bots.setBotGroupDefaultAdminRights', [
                'admin_rights' => ParticipantStatus::chatAdminRights($flags),
            ]);
        } catch (\Throwable $e) {
            $this->forgetChatFull($this->getMyId());
            if (!str_contains($e->getMessage(), 'RIGHTS_NOT_MODIFIED')) {
                throw QueryError::fromThrowable($e);
            }
        }
        $this->forgetChatFull($this->getMyId());
        $query->answerQuery(true);
    }

    public function processGetMyDefaultAdministratorRightsQuery(Query $query): void
    {
        $forChannels = self::boolArg($query, 'for_channels');
        $full = $this->getChatFull($this->getMyId());
        $userFull = $full['full_user'] ?? $full;
        $rights = $forChannels ? ($userFull['bot_broadcast_admin_rights'] ?? null) : ($userFull['bot_group_admin_rights'] ?? null);
        $flags = ParticipantStatus::administratorRightsFromServer($rights, $forChannels ? 'broadcast' : 'megagroup');
        $r = ParticipantStatus::administratorRightsObject($flags);

        // Client::json_store_administrator_rights
        $object = ['can_manage_chat' => $r['can_manage_chat'], 'can_change_info' => $r['can_change_info']];
        if ($forChannels) {
            $object['can_post_messages'] = $r['can_post_messages'];
            $object['can_edit_messages'] = $r['can_edit_messages'];
        }
        $object['can_delete_messages'] = $r['can_delete_messages'];
        $object['can_invite_users'] = $r['can_invite_users'];
        $object['can_restrict_members'] = $r['can_restrict_members'];
        if (!$forChannels) {
            $object['can_pin_messages'] = $r['can_pin_messages'];
            $object['can_manage_topics'] = $r['can_manage_topics'];
        }
        $object['can_promote_members'] = $r['can_promote_members'];
        $object['can_manage_video_chats'] = $r['can_manage_video_chats'];
        $object['can_post_stories'] = $r['can_post_stories'];
        $object['can_edit_stories'] = $r['can_edit_stories'];
        $object['can_delete_stories'] = $r['can_delete_stories'];
        if ($forChannels) {
            $object['can_manage_direct_messages'] = $r['can_manage_direct_messages'];
        } else {
            $object['can_manage_tags'] = $r['can_manage_tags'];
        }
        $object['can_send_welcome_messages'] = $r['can_send_welcome_messages'];
        $object['is_anonymous'] = $r['is_anonymous'];

        $query->answerQuery($object);
    }

    /**
     * Client::get_chat_administrator_rights (the `rights` argument).
     *
     * @return array<string, bool>|null
     */
    private function getChatAdministratorRightsArg(Query $query): ?array
    {
        $rights = $query->arg('rights');
        if ($rights === '') {
            return null;
        }
        $value = TdJson::tryDecode($rights);
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse ChatAdministratorRights JSON object');
        }

        try {
            return $this->chatAdministratorRightsFromJson($value);
        } catch (QueryError $e) {
            throw QueryError::badRequest('Can\'t parse ChatAdministratorRights: '.$e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  Profile photo
    // ═════════════════════════════════════════════════════════════════

    public function processSetMyProfilePhotoQuery(Query $query): void
    {
        $photo = $this->getInputChatPhotoArg($query);

        // UserManager::set_profile_photo_impl
        $mainFrameTimestamp = $photo['main_frame_timestamp'];
        if ($mainFrameTimestamp < 0.0 || $mainFrameTimestamp > 10.0) {
            throw new QueryError(400, 'Wrong main frame timestamp specified');
        }
        $isAnimation = $photo['_'] === 'animated';
        $file = $this->getInputFileId($isAnimation ? FileType::ANIMATION : FileType::PHOTO, $photo['file']);
        $inputFile = $this->uploadFile($file);

        $this->invokeOrFail('photos.uploadProfilePhoto', self::untl(self::tl('photos.uploadProfilePhoto', [
            'file' => $isAnimation ? null : $inputFile,
            'video' => $isAnimation ? $inputFile : null,
            'video_start_ts' => $isAnimation && $mainFrameTimestamp !== 0.0 ? $mainFrameTimestamp : null,
        ])));
        $this->forgetChatFull($this->getMyId());
        $query->answerQuery(true);
    }

    public function processRemoveMyProfilePhotoQuery(Query $query): void
    {
        // UserManager::delete_profile_photo for bots
        $full = $this->getChatFull($this->getMyId(), true);
        $userFull = $full['full_user'] ?? $full;
        $photo = $userFull['profile_photo'] ?? null;
        if (($photo['_'] ?? 'photoEmpty') !== 'photoEmpty') {
            $this->invokeOrFail('photos.updateProfilePhoto', ['id' => ['_' => 'inputPhotoEmpty']]);
            $this->forgetChatFull($this->getMyId());
        }
        $query->answerQuery(true);
    }

    /**
     * Client::get_input_chat_photo.
     *
     * @return array{_: string, file: array<string, mixed>, main_frame_timestamp: float}
     */
    private function getInputChatPhotoArg(Query $query): array
    {
        $photo = $query->arg('photo');
        if ($photo === '') {
            throw QueryError::badRequest('Photo isn\'t specified');
        }
        $value = self::jsonArg($photo, 'Can\'t parse photo JSON object');

        return self::json(function () use ($query, $value): array {
            if (!$value->isObject()) {
                throw QueryError::badRequest('Object expected as profile photo');
            }
            $object = $value->object();
            $type = $object->getRequiredStringField('type');
            if ($type === 'static') {
                $file = $this->getInputFile($query, '', true, $object->getRequiredStringField('photo'));
                if ($file === null) {
                    throw QueryError::badRequest('Photo must be uploaded as a file');
                }

                return ['_' => 'static', 'file' => $file, 'main_frame_timestamp' => 0.0];
            }
            if ($type === 'animated') {
                $animation = $object->getRequiredStringField('animation');
                $mainFrameTimestamp = $object->getOptionalDoubleField('main_frame_timestamp');
                $file = $this->getInputFile($query, '', true, $animation);
                if ($file === null) {
                    throw QueryError::badRequest('Photo must be uploaded as a file');
                }

                return ['_' => 'animated', 'file' => $file, 'main_frame_timestamp' => $mainFrameTimestamp];
            }

            throw QueryError::badRequest('Invalid profile photo type specified');
        });
    }
}
