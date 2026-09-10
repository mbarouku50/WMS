<?php
/**
 * WMS - Reversible encryption for stored credentials.
 *
 * Router API passwords have to be sent back to the device, so they cannot be
 * hashed.  They are encrypted with AES-256-CBC using a key derived from
 * APP_KEY (written by install.php) and authenticated with an HMAC.
 *
 * User and customer passwords are NEVER handled here - those use
 * password_hash() and are never reversible.
 */
class Crypto
{
    private const CIPHER = 'aes-256-cbc';

    private static function key(): string
    {
        return hash('sha256', APP_KEY . '|wms-credential-key', true);
    }

    /** Encrypts a plaintext value; returns a base64 blob (iv|hmac|payload). */
    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        $iv        = random_bytes(openssl_cipher_iv_length(self::CIPHER) ?: 16);
        $cipher    = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            Logger::error('Credential encryption failed');
            return '';
        }
        $mac = hash_hmac('sha256', $iv . $cipher, self::key(), true);
        return base64_encode($iv . $mac . $cipher);
    }

    /** Decrypts a blob produced by encrypt(); returns '' when tampered with. */
    public static function decrypt(?string $blob): string
    {
        if ($blob === null || $blob === '') {
            return '';
        }
        $raw = base64_decode($blob, true);
        if ($raw === false) {
            return '';
        }
        $ivLength = openssl_cipher_iv_length(self::CIPHER) ?: 16;
        if (strlen($raw) < $ivLength + 32) {
            return '';
        }
        $iv     = substr($raw, 0, $ivLength);
        $mac    = substr($raw, $ivLength, 32);
        $cipher = substr($raw, $ivLength + 32);

        if (!hash_equals(hash_hmac('sha256', $iv . $cipher, self::key(), true), $mac)) {
            Logger::warning('Stored credential failed its integrity check');
            return '';
        }

        $plain = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }
}
