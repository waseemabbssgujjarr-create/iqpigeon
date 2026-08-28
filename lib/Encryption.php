<?php
/**
 * AES-256-CBC encryption for WhatsApp business tokens.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';

class Encryption
{
    /**
     * @deprecated Use encrypt_token() directly.
     */
    private static function key(): string
    {
        return hash('sha256', ENCRYPTION_KEY, true);
    }

    /**
     * Encrypt plaintext → base64(ciphertext:iv).
     *
     * @param string $plaintext
     * @return string
     */
    public static function encrypt(string $plaintext): string
    {
        return encrypt_token($plaintext);
    }

    /**
     * Decrypt — supports both storage formats.
     *
     * @param string $ciphertext
     * @return string|false
     */
    public static function decrypt(string $ciphertext): string|false
    {
        return decrypt_token($ciphertext);
    }
}
