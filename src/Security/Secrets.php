<?php

declare(strict_types=1);

namespace JetWP\Control\Security;

use RuntimeException;

final class Secrets
{
    public function __construct(private readonly string $encryptionKey)
    {
        if ($this->encryptionKey === '') {
            throw new RuntimeException('Encryption key must not be empty.');
        }
    }

    public function encrypt(string $plaintext): string
    {
        $key = hash('sha256', $this->encryptionKey, true);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($plaintext, 'aes-256-cbc', $key, 0, $iv);

        if ($encrypted === false) {
            throw new RuntimeException('Secret encryption failed.');
        }

        return base64_encode($iv) . '::' . $encrypted;
    }

    public function decrypt(string $payload): string
    {
        [$ivEncoded, $encrypted] = array_pad(explode('::', $payload, 2), 2, null);
        if (!is_string($ivEncoded) || !is_string($encrypted)) {
            throw new RuntimeException('Encrypted payload format is invalid.');
        }

        $iv = base64_decode($ivEncoded, true);
        if ($iv === false) {
            throw new RuntimeException('Encrypted payload IV is invalid.');
        }

        $key = hash('sha256', $this->encryptionKey, true);
        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
        if ($decrypted === false) {
            throw new RuntimeException('Secret decryption failed.');
        }

        return $decrypted;
    }
}
