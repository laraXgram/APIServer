<?php

declare(strict_types=1);

namespace LaraGram\Server\Td;

use LaraGram\Server\Files\TlBinary;

/**
 * Inline message identifiers: the base64url of a bare TL
 * inputBotInlineMessageID (20 bytes) or inputBotInlineMessageID64 (24 bytes)
 * (InlineQueriesManager::get_inline_message_id, parse_input_bot_inline_message_id).
 */
final class InlineMessageId
{
    /**
     * @param array<string, mixed> $id TL InputBotInlineMessageID
     */
    public static function encode(array $id): string
    {
        $binary = ($id['_'] ?? '') === 'inputBotInlineMessageID64'
            ? pack('V', (int) $id['dc_id']).pack('P', (int) $id['owner_id']).pack('V', (int) $id['id'] & 0xFFFFFFFF).pack('P', (int) $id['access_hash'])
            : pack('V', (int) $id['dc_id']).pack('P', (int) $id['id']).pack('P', (int) $id['access_hash']);

        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /**
     * The TL InputBotInlineMessageID, or null for an invalid identifier.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $inlineMessageId): ?array
    {
        $binary = TlBinary::base64urlDecode($inlineMessageId);
        if ($binary === null) {
            return null;
        }
        $length = strlen($binary);
        if ($length === 20) {
            $id = [
                '_' => 'inputBotInlineMessageID',
                'dc_id' => self::int32(unpack('V', $binary, 0)[1]),
                'id' => unpack('q', $binary, 4)[1],
                'access_hash' => unpack('q', $binary, 12)[1],
            ];
        } elseif ($length === 24) {
            $id = [
                '_' => 'inputBotInlineMessageID64',
                'dc_id' => self::int32(unpack('V', $binary, 0)[1]),
                'owner_id' => unpack('q', $binary, 4)[1],
                'id' => self::int32(unpack('V', $binary, 12)[1]),
                'access_hash' => unpack('q', $binary, 16)[1],
            ];
        } else {
            return null;
        }

        // DcId::is_valid
        if ($id['dc_id'] < 1 || $id['dc_id'] > 1000) {
            return null;
        }

        return $id;
    }

    private static function int32(int $value): int
    {
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }
}
