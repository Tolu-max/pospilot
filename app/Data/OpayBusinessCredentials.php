<?php

namespace App\Data;

final readonly class OpayBusinessCredentials
{
    public function __construct(public string $headMerchantId, public string $merchantId, public string $posSerialNumber, public string $clientAuthKey, public string $rsaPrivateKey, public ?string $rsaPublicKey = null) {}

    /** @param array<string, mixed> $credentials */
    public static function fromArray(array $credentials): self
    {
        foreach (['head_merchant_id', 'merchant_id', 'pos_serial_number', 'client_auth_key', 'rsa_private_key'] as $field) {
            if (empty($credentials[$field])) {
                throw new \InvalidArgumentException("Missing OPay credential: {$field}");
            }
        }

        return new self((string) $credentials['head_merchant_id'], (string) $credentials['merchant_id'], (string) $credentials['pos_serial_number'], (string) $credentials['client_auth_key'], (string) $credentials['rsa_private_key'], isset($credentials['rsa_public_key']) ? (string) $credentials['rsa_public_key'] : null);
    }
}
