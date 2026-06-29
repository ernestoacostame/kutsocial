<?php
namespace KutSocial;

class WebPushHelper {
    /**
     * Get or generate VAPID keys from the options table.
     */
    public static function getVapidKeys(): array {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT key, value FROM options WHERE key IN ('vapid_public_key', 'vapid_private_key')");
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $keys = [];
        foreach ($rows as $row) {
            $keys[$row['key']] = $row['value'];
        }

        if (empty($keys['vapid_private_key']) || empty($keys['vapid_public_key'])) {
            // Generate prime256v1 EC keypair
            $config = [
                "private_key_type" => OPENSSL_KEYTYPE_EC,
                "curve_name" => "prime256v1",
            ];
            $pkey = openssl_pkey_new($config);
            if (!$pkey) {
                throw new \Exception("Failed to generate VAPID keys: " . openssl_error_string());
            }

            openssl_pkey_export($pkey, $privatePem);
            $details = openssl_pkey_get_details($pkey);
            $publicPem = $details['key'];

            $stmt = $db->prepare("INSERT OR REPLACE INTO options (key, value) VALUES ('vapid_private_key', ?)");
            $stmt->execute([$privatePem]);
            $stmt = $db->prepare("INSERT OR REPLACE INTO options (key, value) VALUES ('vapid_public_key', ?)");
            $stmt->execute([$publicPem]);

            $keys['vapid_private_key'] = $privatePem;
            $keys['vapid_public_key'] = $publicPem;
        }

        return $keys;
    }

    public static function base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64url_decode(string $data): string {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }

    /**
     * Extracts the 65-byte uncompressed EC point from a PEM public key.
     */
    public static function pemToUncompressedEcPoint(string $pem): string {
        $clean = str_replace(["-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----", "\r", "\n", " "], "", $pem);
        $der = base64_decode($clean);
        if ($der === false || strlen($der) < 65) {
            throw new \Exception("Invalid DER public key");
        }
        // Last 65 bytes of prime256v1 SubjectPublicKeyInfo is the uncompressed public key point
        return substr($der, -65);
    }

    /**
     * Wraps a 65-byte uncompressed EC public key point in a prime256v1 PEM format.
     */
    public static function uncompressedEcPointToPem(string $point): string {
        $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
        $der = $prefix . $point;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Converts a DER signature (from openssl_sign) to IEEE P1363 format (64 bytes).
     */
    public static function derToP1363(string $der): string {
        if (ord($der[0]) !== 0x30) {
            throw new \InvalidArgumentException("Invalid DER signature structure");
        }
        $offset = 2; // skip Sequence tag and length

        // Read R
        if (ord($der[$offset]) !== 0x02) {
            throw new \InvalidArgumentException("Invalid DER signature structure (R tag)");
        }
        $offset++;
        $rLen = ord($der[$offset]);
        $offset++;
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;

        // Read S
        if (ord($der[$offset]) !== 0x02) {
            throw new \InvalidArgumentException("Invalid DER signature structure (S tag)");
        }
        $offset++;
        $sLen = ord($der[$offset]);
        $offset++;
        $s = substr($der, $offset, $sLen);

        // Strip leading zero bytes and pad to 32 bytes each
        $r = ltrim($r, "\0");
        $s = ltrim($s, "\0");

        $r = str_pad($r, 32, "\0", STR_PAD_LEFT);
        $s = str_pad($s, 32, "\0", STR_PAD_LEFT);

        return $r . $s;
    }

    /**
     * Helper for HKDF-Expand step.
     */
    public static function hkdf_expand(string $prk, string $info, int $length): string {
        $hashLen = 32; // SHA-256
        $blocks = ceil($length / $hashLen);
        $t = '';
        $result = '';
        for ($i = 1; $i <= $blocks; $i++) {
            $t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
            $result .= $t;
        }
        return substr($result, 0, $length);
    }

    /**
     * Generates a signed VAPID JWT.
     */
    public static function createJwt(string $endpoint, string $privateKeyPem, string $subject): string {
        $parsed = parse_url($endpoint);
        $aud = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

        $header = json_encode(['alg' => 'ES256', 'typ' => 'JWT']);
        $payload = json_encode([
            'aud' => $aud,
            'exp' => time() + 43200, // 12 hours
            'sub' => $subject
        ]);

        $jwtInput = self::base64url_encode($header) . '.' . self::base64url_encode($payload);

        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if (!$privateKey) {
            throw new \Exception("Invalid private key for signing");
        }

        $signature = '';
        if (!openssl_sign($jwtInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \Exception("Signing failed: " . openssl_error_string());
        }

        $rawSignature = self::derToP1363($signature);
        return $jwtInput . '.' . self::base64url_encode($rawSignature);
    }

    /**
     * Encrypts the payload according to RFC 8291 (aes128gcm) and RFC 8188.
     */
    public static function encryptPayload(string $payload, string $uaPublicKeyBase64, string $uaAuthBase64): string {
        $ua_public = self::base64url_decode($uaPublicKeyBase64);
        $ua_auth = self::base64url_decode($uaAuthBase64);

        // 1. Generate ephemeral EC keypair
        $config = [
            "private_key_type" => OPENSSL_KEYTYPE_EC,
            "curve_name" => "prime256v1",
        ];
        $as_pkey = openssl_pkey_new($config);
        if (!$as_pkey) {
            throw new \Exception("Failed to generate ephemeral EC key");
        }
        $as_details = openssl_pkey_get_details($as_pkey);
        $as_public_pem = $as_details['key'];
        $as_public = self::pemToUncompressedEcPoint($as_public_pem);

        // 2. Perform ECDH to get shared secret
        $ua_public_pem = self::uncompressedEcPointToPem($ua_public);
        $ua_pkey = openssl_pkey_get_public($ua_public_pem);
        if (!$ua_pkey) {
            throw new \Exception("Failed to load user agent public key");
        }

        $ecdh_secret = openssl_pkey_derive($ua_pkey, $as_pkey);
        if ($ecdh_secret === false) {
            throw new \Exception("ECDH derivation failed");
        }

        // 3. Derive IKM: prk_key = HKDF-Extract(salt = auth, IKM = ecdh_secret)
        $prk_key = hash_hmac('sha256', $ecdh_secret, $ua_auth, true);

        // Context info = "WebPush: info\0" || ua_public || as_public
        $key_info = "WebPush: info\0" . $ua_public . $as_public;

        // IKM = HKDF-Expand(prk_key, key_info, 32)
        $ikm = self::hkdf_expand($prk_key, $key_info, 32);

        // 4. Generate random salt (16 bytes)
        $salt = random_bytes(16);

        // PRK = HKDF-Extract(salt, IKM)
        $prk = hash_hmac('sha256', $ikm, $salt, true);

        // CEK = HKDF-Expand(PRK, "Content-Encoding: aes128gcm\0", 16)
        $cek = self::hkdf_expand($prk, "Content-Encoding: aes128gcm\0", 16);

        // Nonce = HKDF-Expand(PRK, "Content-Encoding: nonce\0", 12)
        $nonce = self::hkdf_expand($prk, "Content-Encoding: nonce\0", 12);

        // 5. Append padding delimiter "\x02" (only/last record)
        $paddedPayload = $payload . "\x02";

        // 6. Encrypt padded payload
        $tag = '';
        $ciphertext = openssl_encrypt(
            $paddedPayload,
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );
        if ($ciphertext === false) {
            throw new \Exception("AES-128-GCM encryption failed");
        }

        // 7. Construct final binary payload:
        // salt (16 bytes) | record size (4 bytes: 4096) | key id len (1 byte: 65) | key id (65 bytes) | ciphertext | tag (16 bytes)
        $recordSize = pack('N', 4096);
        $keyIdLen = pack('C', strlen($as_public));

        return $salt . $recordSize . $keyIdLen . $as_public . $ciphertext . $tag;
    }

    /**
     * Encrypts and sends a Web Push notification.
     */
    public static function sendPush(string $endpoint, string $uaPublicKeyBase64, string $uaAuthBase64, array $notificationData): bool {
        try {
            $payload = json_encode($notificationData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $encrypted = self::encryptPayload($payload, $uaPublicKeyBase64, $uaAuthBase64);

            $vapid = self::getVapidKeys();
            $vapidPrivate = $vapid['vapid_private_key'];
            $vapidPublic = $vapid['vapid_public_key'];
            $vapidPublicPoint = self::pemToUncompressedEcPoint($vapidPublic);
            $vapidPublicBase64 = self::base64url_encode($vapidPublicPoint);

            $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $subject = "mailto:admin@$domain";

            $jwt = self::createJwt($endpoint, $vapidPrivate, $subject);

            $headers = [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: 2419200',
                'Urgency: high',
                'Authorization: vapid t=' . $jwt . ', k=' . $vapidPublicBase64
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encrypted);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, \KutSocial\Database::verifySsl());
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, \KutSocial\Database::verifySsl() ? 2 : 0);

            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Web Push status codes: 201 Created is typical, 200/202 are also success
            if ($code >= 200 && $code < 300) {
                return true;
            }

            error_log("Web Push dispatch failed for endpoint $endpoint. HTTP Code: $code. Response: $res");
            return false;
        } catch (\Throwable $e) {
            error_log("Error sending Web Push to $endpoint: " . $e->getMessage());
            return false;
        }
    }
}
