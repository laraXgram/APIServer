<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Support\JsonError;
use LaraGram\Server\Support\JsonObject;
use LaraGram\Server\Support\JsonValue;
use LaraGram\Server\Support\TdJson;

/**
 * setPassportDataErrors with SecureManager::set_secure_value_errors.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait PassportMethods
{
    private const PASSPORT_ELEMENT_TYPES = [
        'personal_details' => 'secureValueTypePersonalDetails',
        'passport' => 'secureValueTypePassport',
        'driver_license' => 'secureValueTypeDriverLicense',
        'identity_card' => 'secureValueTypeIdentityCard',
        'internal_passport' => 'secureValueTypeInternalPassport',
        'address' => 'secureValueTypeAddress',
        'utility_bill' => 'secureValueTypeUtilityBill',
        'bank_statement' => 'secureValueTypeBankStatement',
        'rental_agreement' => 'secureValueTypeRentalAgreement',
        'passport_registration' => 'secureValueTypePassportRegistration',
        'temporary_registration' => 'secureValueTypeTemporaryRegistration',
        'phone_number' => 'secureValueTypePhone',
        'email' => 'secureValueTypeEmail',
    ];

    public function processSetPassportDataErrorsQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $value = TdJson::tryDecode($query->arg('errors'));
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse errors JSON object');
        }
        $errors = $this->parseArray($value, 'PassportElementError', static fn (JsonValue $error): array => self::getPassportElementError($error));
        $this->checkUser($userId);

        // SecureManager::set_secure_value_errors
        $inputErrors = [];
        foreach ($errors as $error) {
            $message = TextFormatting::cleanInputString($error['message']) ?? throw new QueryError(400, 'Error message must be encoded in UTF-8');
            $type = ['_' => $error['type']];
            $source = $error['source'];
            $inputErrors[] = match ($source['_']) {
                'unspecified' => ['_' => 'secureValueError', 'type' => $type, 'hash' => $source['hash'], 'text' => $message],
                'data' => ['_' => 'secureValueErrorData', 'type' => $type, 'data_hash' => $source['hash'],
                    'field' => TextFormatting::cleanInputString($source['field_name']) ?? throw new QueryError(400, 'Field name must be encoded in UTF-8'),
                    'text' => $message],
                'front_side' => ['_' => 'secureValueErrorFrontSide', 'type' => $type, 'file_hash' => $source['hash'], 'text' => $message],
                'reverse_side' => ['_' => 'secureValueErrorReverseSide', 'type' => $type, 'file_hash' => $source['hash'], 'text' => $message],
                'selfie' => ['_' => 'secureValueErrorSelfie', 'type' => $type, 'file_hash' => $source['hash'], 'text' => $message],
                'translation_file' => ['_' => 'secureValueErrorTranslationFile', 'type' => $type, 'file_hash' => $source['hash'], 'text' => $message],
                'translation_files' => ['_' => 'secureValueErrorTranslationFiles', 'type' => $type,
                    'file_hash' => $source['hashes'] !== [] ? $source['hashes'] : throw new QueryError(400, 'File hashes must be non-empty'), 'text' => $message],
                'file' => ['_' => 'secureValueErrorFile', 'type' => $type, 'file_hash' => $source['hash'], 'text' => $message],
                'files' => ['_' => 'secureValueErrorFiles', 'type' => $type,
                    'file_hash' => $source['hashes'] !== [] ? $source['hashes'] : throw new QueryError(400, 'File hashes must be non-empty'), 'text' => $message],
            };
        }
        $this->invokeOrFail('users.setSecureValueErrors', ['id' => $this->inputUser($userId), 'errors' => $inputErrors]);
        $query->answerQuery(true);
    }

    /**
     * Client::get_passport_element_error.
     *
     * @return array{type: string, message: string, source: array<string, mixed>}
     */
    private static function getPassportElementError(JsonValue $value): array
    {
        if (!$value->isObject()) {
            throw new JsonError('expected an Object');
        }
        $object = $value->object();
        $type = self::PASSPORT_ELEMENT_TYPES[$object->getRequiredStringField('type')] ?? throw new JsonError('wrong Telegram Passport element type specified');
        $message = $object->getRequiredStringField('message');

        return ['type' => $type, 'message' => $message, 'source' => self::getPassportElementErrorSource($object)];
    }

    /**
     * Client::get_passport_element_error_source.
     *
     * @return array<string, mixed>
     */
    private static function getPassportElementErrorSource(JsonObject $object): array
    {
        $source = $object->getOptionalStringField('source');
        if ($source === '' || $source === 'unspecified') {
            return ['_' => 'unspecified', 'hash' => self::getPassportElementHash($object->getRequiredStringField('element_hash'))];
        }
        if ($source === 'data') {
            $hash = self::getPassportElementHash($object->getRequiredStringField('data_hash'));

            return ['_' => 'data', 'hash' => $hash, 'field_name' => $object->getRequiredStringField('field_name')];
        }
        if (in_array($source, ['file', 'selfie', 'translation_file', 'front_side', 'reverse_side'], true)) {
            return ['_' => $source, 'hash' => self::getPassportElementHash($object->getRequiredStringField('file_hash'))];
        }
        if ($source === 'files' || $source === 'translation_files') {
            $hashes = [];
            foreach ($object->extractRequiredField('file_hashes', TdJson::ARRAY)->array() as $hash) {
                if ($hash->type !== TdJson::STRING) {
                    throw new JsonError('hash must be a string');
                }
                $hashes[] = self::getPassportElementHash($hash->string());
            }

            return ['_' => $source, 'hashes' => $hashes];
        }

        throw new JsonError('wrong source specified');
    }

    /**
     * Client::get_passport_element_hash.
     */
    private static function getPassportElementHash(string $encoded): string
    {
        if (!self::isBase64($encoded)) {
            throw new JsonError('hash isn\'t a valid base64-encoded string');
        }

        return (string) base64_decode($encoded, true);
    }

    /**
     * td::is_base64.
     */
    private static function isBase64(string $input): bool
    {
        $trimmed = rtrim($input, '=');
        $padding = strlen($input) - strlen($trimmed);
        if ($padding >= 3 || ((strlen($trimmed) + $padding) & 3) !== 0) {
            return false;
        }
        if (preg_match('~^[A-Za-z0-9+/]*$~', $trimmed) !== 1) {
            return false;
        }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
        $last = $trimmed === '' ? 0 : strpos($alphabet, $trimmed[-1]);
        if ((strlen($trimmed) & 3) === 2 && ($last & 15) !== 0) {
            return false;
        }
        if ((strlen($trimmed) & 3) === 3 && ($last & 3) !== 0) {
            return false;
        }

        return true;
    }
}
