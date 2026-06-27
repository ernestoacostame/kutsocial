<?php
namespace KutSocial;

/**
 * Helper for encrypting/decrypting sensitive data at rest (e.g., SMTP passwords).
 * Uses AES-256-GCM with a key derived from the application's jwt_secret.
 */
class CryptoHelper {
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    /**
     * Derive an encryption key from the jwt_secret stored in the database.
     */
    private static function getKey(): string {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT value FROM options WHERE key = 'jwt_secret' LIMIT 1");
        $stmt->execute();
        $secret = $stmt->fetchColumn();
        if (!$secret) {
            $secret = bin2hex(random_bytes(32));
            $ins = $db->prepare("INSERT INTO options (key, value) VALUES ('jwt_secret', ?)");
            $ins->execute([$secret]);
        }
        // Derive a 256-bit key using HKDF
        return hash_hmac('sha256', 'smtp_encryption_key', $secret, true);
    }

    /**
     * Encrypt a plaintext string. Returns base64-encoded ciphertext with IV and tag prepended.
     */
    public static function encrypt(string $plaintext): string {
        if (empty($plaintext)) {
            return '';
        }
        $key = self::getKey();
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }
        // Format: base64(iv + tag + ciphertext), prefixed with 'enc:' marker
        return 'enc:' . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a string previously encrypted with encrypt().
     * Returns the original plaintext. If input is not encrypted (no 'enc:' prefix), returns as-is.
     */
    public static function decrypt(string $encrypted): string {
        if (empty($encrypted)) {
            return '';
        }
        // If not encrypted (legacy plaintext), return as-is
        if (!str_starts_with($encrypted, 'enc:')) {
            return $encrypted;
        }
        $data = base64_decode(substr($encrypted, 4));
        if ($data === false) {
            return '';
        }
        $key = self::getKey();
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, self::TAG_LENGTH);
        $ciphertext = substr($data, $ivLength + self::TAG_LENGTH);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            return '';
        }
        return $plaintext;
    }
}
