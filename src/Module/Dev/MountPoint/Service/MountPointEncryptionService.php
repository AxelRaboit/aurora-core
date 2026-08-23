<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\MountPoint\Service;

use RuntimeException;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts and decrypts mount point secrets (password field) using
 * libsodium's XSalsa20-Poly1305 authenticated encryption.
 *
 * The key must be exactly 32 bytes, base64-encoded in AURORA_MOUNT_POINT_KEY.
 * Generate one with: php -r "echo base64_encode(random_bytes(32));"
 */
final readonly class MountPointEncryptionService
{
    private string $key;

    public function __construct(
        #[Autowire(env: 'AURORA_MOUNT_POINT_KEY')]
        string $encodedKey,
    ) {
        $key = base64_decode($encodedKey, strict: true);

        // '8bit' encoding counts bytes, not multibyte characters - required for binary data.
        if (false === $key || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== mb_strlen($key, '8bit')) {
            throw new RuntimeException('AURORA_MOUNT_POINT_KEY must be a base64-encoded 32-byte key.');
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

        // '8bit' encoding counts bytes, not multibyte characters - required for binary data.
        if (false === $decoded || mb_strlen($decoded, '8bit') < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
        $ciphertext = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        return false === $plaintext ? null : $plaintext;
    }
}
