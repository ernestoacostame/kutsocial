<?php
// Evitar ejecución si no está el token correcto
if (($_GET['token'] ?? '') !== 'kutsocial_dev') {
    http_response_code(403);
    exit("No autorizado");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/WebPushHelper.php';

use KutSocial\Database;
use KutSocial\WebPushHelper;

header('Content-Type: text/plain; charset=utf-8');

try {
    Database::setDbPath(KUTSOCIAL_DB_PATH);
    $db = Database::connect();

    echo "=== DB PATH ===\n";
    echo KUTSOCIAL_DB_PATH . "\n\n";

    echo "=== VAPID KEYS IN DB ===\n";
    $vapid = WebPushHelper::getVapidKeys();
    echo "Public Key:\n" . $vapid['vapid_public_key'] . "\n";
    echo "Private Key length: " . strlen($vapid['vapid_private_key'] ?? '') . "\n\n";

    echo "=== PUSH SUBSCRIPTIONS ===\n";
    $subs = $db->query("SELECT s.id, s.account_id, a.username, s.endpoint, s.created_at FROM web_push_subscriptions s JOIN accounts a ON s.account_id = a.id")->fetchAll();
    if (empty($subs)) {
        echo "No subscriptions found in the database.\n";
    } else {
        foreach ($subs as $sub) {
            echo "ID: {$sub['id']}, Account ID: {$sub['account_id']} (username: {$sub['username']})\n";
            echo "Endpoint: {$sub['endpoint']}\n";
            echo "Created At: {$sub['created_at']}\n";
            echo "To test this subscription, add `&send_test_sub_id={$sub['id']}` to the URL.\n";
            echo "--------------------------------------------------\n";
        }
    }

    if (isset($_GET['send_test_sub_id'])) {
        $subId = (int)$_GET['send_test_sub_id'];
        echo "\n=== SENDING TEST PUSH TO SUBSCRIPTION ID $subId ===\n";
        $stmt = $db->prepare("SELECT * FROM web_push_subscriptions WHERE id = ?");
        $stmt->execute([$subId]);
        $sub = $stmt->fetch();
        if (!$sub) {
            echo "Subscription not found.\n";
        } else {
            $payload = [
                'id' => 'test_push_' . time(),
                'type' => 'mention',
                'created_at' => date('c'),
                'notification_id' => 'test_push_' . time(),
                'notification_type' => 'mention',
                'title' => 'Notificación de Prueba',
                'body' => '¡Hola! Esta es una notificación push de prueba de KutSocial.',
                'access_token' => 'test_token',
                'preferred_locale' => 'es'
            ];
            
            echo "Payload: " . json_encode($payload) . "\n";
            
            // Re-implementing sendPush here with verbose logging
            $payloadStr = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $encrypted = WebPushHelper::encryptPayload($payloadStr, $sub['key_p256dh'], $sub['key_auth']);
            
            $vapidPrivate = $vapid['vapid_private_key'];
            $vapidPublic = $vapid['vapid_public_key'];
            $vapidPublicPoint = WebPushHelper::pemToUncompressedEcPoint($vapidPublic);
            $vapidPublicBase64 = WebPushHelper::base64url_encode($vapidPublicPoint);

            $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $subject = "mailto:admin@$domain";

            $jwt = WebPushHelper::createJwt($sub['endpoint'], $vapidPrivate, $subject);

            $headers = [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: 2419200',
                'Urgency: high',
                'Authorization: vapid t=' . $jwt . ', k=' . $vapidPublicBase64
            ];

            echo "Sending cURL request to: {$sub['endpoint']}\n";
            echo "Headers:\n" . implode("\n", $headers) . "\n";

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $sub['endpoint']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encrypted);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            
            // Catch verbose output
            $verbose = fopen('php://temp', 'w+');
            curl_setopt($ch, CURLOPT_STDERR, $verbose);
            
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, Database::verifySsl());
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, Database::verifySsl() ? 2 : 0);

            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            fclose($verbose);

            echo "HTTP Code: $code\n";
            echo "Response: $res\n";
            if ($err) {
                echo "cURL Error: $err\n";
            }
            echo "\ncURL Verbose Log:\n$verboseLog\n";
        }
    }

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
