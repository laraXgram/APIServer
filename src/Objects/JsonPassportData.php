<?php

declare(strict_types=1);

namespace LaraGram\Server\Objects;

use LaraGram\Server\Bot\Client;
use LaraGram\Server\Files\FileType;
use LaraGram\Server\Files\RemoteFileLocation;

/**
 * The Bot API `PassportData` (Client::JsonPassportData) of a TL
 * messageActionSecureValuesSentMe, through TDLib's encrypted secure values.
 */
final class JsonPassportData
{
    /** secureValueType* → Bot API element type */
    private const TYPES = [
        'secureValueTypePersonalDetails' => 'personal_details',
        'secureValueTypePassport' => 'passport',
        'secureValueTypeDriverLicense' => 'driver_license',
        'secureValueTypeIdentityCard' => 'identity_card',
        'secureValueTypeInternalPassport' => 'internal_passport',
        'secureValueTypeAddress' => 'address',
        'secureValueTypeUtilityBill' => 'utility_bill',
        'secureValueTypeBankStatement' => 'bank_statement',
        'secureValueTypeRentalAgreement' => 'rental_agreement',
        'secureValueTypePassportRegistration' => 'passport_registration',
        'secureValueTypeTemporaryRegistration' => 'temporary_registration',
        'secureValueTypePhone' => 'phone_number',
        'secureValueTypeEmail' => 'email',
    ];

    /**
     * @param array<string, mixed> $content messagePassportDataReceived
     * @return array<string, mixed>
     */
    public static function make(array $content, Client $client): array
    {
        $data = [];
        foreach ($content['values'] as $value) {
            $element = self::element($value);
            if ($element !== null) {
                $data[] = $element;
            }
        }
        $credentials = $content['credentials'];

        return [
            'data' => $data,
            'credentials' => [
                'data' => base64_encode((string) ($credentials['data'] ?? '')),
                'hash' => base64_encode((string) ($credentials['hash'] ?? '')),
                'secret' => base64_encode((string) ($credentials['secret'] ?? '')),
            ],
        ];
    }

    /**
     * get_encrypted_secure_value + Client::JsonEncryptedPassportElement.
     *
     * @param array<string, mixed> $value TL secureValue
     * @return array<string, mixed>|null
     */
    private static function element(array $value): ?array
    {
        $type = self::TYPES[$value['type']['_'] ?? ''] ?? null;
        if ($type === null) {
            return null;
        }

        $object = ['type' => $type];
        $plain = $value['plain_data'] ?? null;
        switch ($type) {
            case 'phone_number':
                $object['phone_number'] = (string) ($plain['phone'] ?? '');
                break;
            case 'email':
                $object['email'] = (string) ($plain['email'] ?? '');
                break;
            case 'personal_details': case 'passport': case 'driver_license': case 'identity_card':
            case 'internal_passport': case 'address':
                $object['data'] = base64_encode((string) ($value['data']['data'] ?? ''));
                break;
        }
        switch ($type) {
            case 'utility_bill': case 'bank_statement': case 'rental_agreement': case 'passport_registration':
            case 'temporary_registration':
                $object['files'] = self::files($value['files'] ?? []);
                if (!empty($value['translation'])) {
                    $object['translation'] = self::files($value['translation']);
                }
                break;
        }
        switch ($type) {
            case 'passport': case 'driver_license': case 'identity_card': case 'internal_passport':
                if (($front = self::file($value['front_side'] ?? null)) !== null) {
                    $object['front_side'] = $front;
                }
                if (($reverse = self::file($value['reverse_side'] ?? null)) !== null) {
                    $object['reverse_side'] = $reverse;
                }
                if (($selfie = self::file($value['selfie'] ?? null)) !== null) {
                    $object['selfie'] = $selfie;
                }
                if (!empty($value['translation'])) {
                    $object['translation'] = self::files($value['translation']);
                }
                break;
        }
        $object['hash'] = base64_encode((string) ($value['hash'] ?? ''));

        return $object;
    }

    /**
     * @param list<array<string, mixed>> $files
     * @return list<array<string, mixed>>
     */
    private static function files(array $files): array
    {
        return array_values(array_filter(array_map(static fn (array $file): ?array => self::file($file), $files)));
    }

    /**
     * Client::JsonDatedFile of a TL secureFile.
     *
     * @param array<string, mixed>|null $file
     * @return array<string, mixed>|null
     */
    private static function file(?array $file): ?array
    {
        if (($file['_'] ?? '') !== 'secureFile') {
            return null;
        }
        $location = RemoteFileLocation::common(FileType::SECURE_ENCRYPTED, (int) $file['dc_id'], (int) $file['id'], (int) $file['access_hash'], '');
        $object = [];
        JsonMedia::storeFile($object, ['file_id' => $location->toFileId(), 'file_unique_id' => $location->toUniqueId(), 'size' => (int) $file['size']]);
        $object['file_date'] = max(0, (int) $file['date']);

        return $object;
    }
}
