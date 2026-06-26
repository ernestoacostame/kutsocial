<?php
namespace KutSocial;

use Exception;

class TotpHelper {
    /**
     * Generates a 16-character random Base32 secret.
     */
    public static function generateSecret(int $length = 16): string {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Helper to get a otpauth URL for QR Code generator.
     */
    public static function getQRCodeUrl(string $username, string $domain, string $secret): string {
        $label = rawurlencode($username . '@' . $domain);
        $issuer = rawurlencode('KutSocial');
        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * Verifies the 6-digit code against the Base32 secret.
     * Incorporates a ±1 time step tolerance (total 90 seconds window).
     */
    public static function verifyCode(string $secret, string $code, int $discrepancy = 1): bool {
        $secret = strtoupper($secret);
        try {
            $secretKey = self::base32Decode($secret);
        } catch (Exception $e) {
            return false;
        }

        $code = str_replace(' ', '', $code);
        if (strlen($code) !== 6 || !is_numeric($code)) {
            return false;
        }

        $currentTimeSlice = floor(time() / 30);

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $timeSlice = $currentTimeSlice + $i;
            $calculatedCode = self::calculateCode($secretKey, $timeSlice);
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Base32 decode implementation.
     */
    private static function base32Decode(string $base32): string {
        $base32 = str_replace('=', '', $base32);
        if ($base32 === '') {
            return '';
        }

        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $lut = array_flip(str_split($chars));
        
        $binaryString = '';
        foreach (str_split($base32) as $char) {
            if (!isset($lut[$char])) {
                throw new Exception("Invalid Base32 character: " . $char);
            }
            $binaryString .= sprintf('%05b', $lut[$char]);
        }

        $bytes = '';
        foreach (str_split($binaryString, 8) as $bin) {
            if (strlen($bin) < 8) {
                break;
            }
            $bytes .= chr(bindec($bin));
        }

        return $bytes;
    }

    /**
     * Calculates the TOTP code for a specific time slice.
     */
    private static function calculateCode(string $secretKey, int $timeSlice): string {
        // Pack time slice into a 64-bit binary string
        $timeBytes = pack('N*', 0, $timeSlice);

        // Generate HMAC-SHA1 signature
        $hash = hash_hmac('sha1', $timeBytes, $secretKey, true);

        // Dynamic truncation
        $offset = ord($hash[19]) & 0xf;
        $truncatedHash = (
            (ord($hash[$offset]) & 0x7f) << 24 |
            (ord($hash[$offset + 1]) & 0xff) << 16 |
            (ord($hash[$offset + 2]) & 0xff) << 8 |
            (ord($hash[$offset + 3]) & 0xff)
        );

        $otp = $truncatedHash % 1000000;
        return sprintf('%06d', $otp);
    }
}
