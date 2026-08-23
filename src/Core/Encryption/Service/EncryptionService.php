<?php

declare(strict_types=1);

namespace Aurora\Core\Encryption\Service;

use RuntimeException;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Generic Aurora encryption service for at-rest data (notes, sensitive fields).
 *
 * Uses libsodium XSalsa20-Poly1305 authenticated encryption. The key must be
 * 32 bytes, base64-encoded in AURORA_ENCRYPTION_KEY. Generate one with:
 *   php -r "echo base64_encode(random_bytes(32));"
 *
 * Distinct from MountPointEncryptionService so the keys can be rotated
 * independently (mount points vs application data).
 */
#[AsAlias(EncryptionServiceInterface::class)]
final readonly class EncryptionService implements EncryptionServiceInterface
{
    private string $key;

    public function __construct(
        #[Autowire(env: 'AURORA_ENCRYPTION_KEY')]
        string $encodedKey,
    ) {
        $key = base64_decode($encodedKey, strict: true);

        // '8bit' encoding counts bytes, not multibyte characters - required for binary data.
        if (false === $key || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== mb_strlen($key, '8bit')) {
            throw new RuntimeException('AURORA_ENCRYPTION_KEY must be a base64-encoded 32-byte key.');
        }

        $this->key = $key;
    }

    public function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce.$ciphertext);
    }

    public function decrypt(#[SensitiveParameter] string $encoded): ?string
    {
        $decoded = base64_decode($encoded, strict: true);

        if (false === $decoded || mb_strlen($decoded, '8bit') < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
        $ciphertext = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        return false === $plaintext ? null : $plaintext;
    }
}
