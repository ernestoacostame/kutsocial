<?php
namespace KutSocial;

use Exception;

class NotificationHelper {
    /**
     * Native SMTP mailer client to avoid external dependencies.
     */
    public static function sendSMTP(
        string $host,
        int $port,
        ?string $user,
        ?string $pass,
        string $from,
        string $to,
        string $subject,
        string $body
    ): bool {
        $timeout = 10;
        $socketHost = $host;
        if ($port === 465) {
            $socketHost = "ssl://" . $host;
        }
        
        $socket = @fsockopen($socketHost, $port, $errno, $errstr, $timeout);
        if (!$socket) {
            error_log("SMTP connection failed: $errno - $errstr");
            return false;
        }

        $getResponse = function($socket) {
            $response = "";
            while (($line = fgets($socket, 515)) !== false) {
                $response .= $line;
                if (substr($line, 3, 1) == " ") {
                    break;
                }
            }
            return $response;
        };

        $sendCmd = function($socket, $cmd) use ($getResponse) {
            fputs($socket, $cmd . "\r\n");
            return $getResponse($socket);
        };

        $getResponse($socket);
        $sendCmd($socket, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

        if ($port === 587) {
            $res = $sendCmd($socket, "STARTTLS");
            if (strpos($res, '220') !== false) {
                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    $sendCmd($socket, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
                }
            }
        }

        if (!empty($user) && !empty($pass)) {
            $sendCmd($socket, "AUTH LOGIN");
            $sendCmd($socket, base64_encode($user));
            $sendCmd($socket, base64_encode($pass));
        }

        $sendCmd($socket, "MAIL FROM:<" . $from . ">");
        $sendCmd($socket, "RCPT TO:<" . $to . ">");
        $sendCmd($socket, "DATA");

        $headers = [
            "MIME-Version: 1.0",
            "Content-Type: text/html; charset=UTF-8",
            "From: <" . $from . ">",
            "To: <" . $to . ">",
            "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
            "Date: " . date('r'),
            "Message-ID: <" . bin2hex(random_bytes(16)) . "@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">"
        ];
        
        $msg = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
        $res = $sendCmd($socket, $msg);

        $sendCmd($socket, "QUIT");
        fclose($socket);

        return strpos($res, '250') !== false;
    }

    /**
     * Send email notification helper.
     */
    private static function sendEmailNotification(array $recipient, string $subject, string $body): void {
        if (empty($recipient['email']) || empty($recipient['smtp_host']) || empty($recipient['smtp_port'])) {
            return;
        }

        $host = $recipient['smtp_host'];
        $port = (int)$recipient['smtp_port'];
        $user = $recipient['smtp_user'];
        $pass = $recipient['smtp_pass'];
        $from = $recipient['smtp_from'] ?: ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

        // Wrap body in a basic HTML template
        $htmlBody = "
        <html>
        <body style=\"font-family: Arial, sans-serif; background-color: #0f172a; color: #e2e8f0; padding: 20px;\">
            <div style=\"max-width: 600px; margin: 0 auto; background-color: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.5);\">
                <div style=\"border-bottom: 1px solid #334155; padding-bottom: 15px; margin-bottom: 15px;\">
                    <h2 style=\"color: #6366f1; margin: 0;\">KutSocial</h2>
                </div>
                <div style=\"line-height: 1.6; font-size: 15px;\">
                    $body
                </div>
                <div style=\"border-top: 1px solid #334155; padding-top: 15px; margin-top: 20px; font-size: 12px; color: #94a3b8; text-align: center;\">
                    Este es un correo automático enviado por tu instancia de KutSocial.
                </div>
            </div>
        </body>
        </html>";

        try {
            self::sendSMTP($host, $port, $user, $pass, $from, $recipient['email'], $subject, $htmlBody);
        } catch (Exception $e) {
            error_log("Failed to send email notification to {$recipient['email']}: " . $e->getMessage());
        }
    }

    /**
     * Notify about a mention or reply.
     */    public static function notifyMentionOrReply(int $statusId): void {
        try {
            $db = Database::connect();
            $stmt = $db->prepare("SELECT * FROM statuses WHERE id = ? LIMIT 1");
            $stmt->execute([$statusId]);
            $status = $stmt->fetch();
            if (!$status) return;

            $stmtAuthor = $db->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
            $stmtAuthor->execute([$status['account_id']]);
            $author = $stmtAuthor->fetch();
            if (!$author) return;

            $authorHandle = $author['domain'] ? '@' . $author['username'] . '@' . $author['domain'] : '@' . $author['username'];
            $authorName = $author['display_name'] ?: $author['username'];

            $recipientId = null;

            // 1. Si es respuesta, notificar al autor del post padre
            if ($status['in_reply_to_id']) {
                $stmtParent = $db->prepare("
                    SELECT a.*
                    FROM statuses s
                    JOIN accounts a ON s.account_id = a.id
                    WHERE s.id = ? AND a.domain IS NULL LIMIT 1
                ");
                $stmtParent->execute([$status['in_reply_to_id']]);
                $parentUser = $stmtParent->fetch();
                if ($parentUser && $parentUser['id'] != $author['id']) {
                    if ($parentUser['email_notifications']) {
                        self::sendEmailNotification(
                            $parentUser,
                            "[KutSocial] Nueva respuesta de $authorName",
                            "<h3>¡Hola @{$parentUser['username']}!</h3>
                             <p><strong>$authorName</strong> ($authorHandle) ha respondido a tu publicación:</p>
                             <div style='border-left: 4px solid #6366f1; padding-left: 12px; margin: 15px 0; color: #cbd5e1; font-style: italic; background: rgba(255,255,255,0.02); padding: 10px; border-radius: 6px;'>
                                 {$status['content']}
                             </div>
                             <p>Puedes verla en tu panel de KutSocial.</p>"
                        );
                    }

                    // Dispatch Push Notification for reply
                    $statusRow = \KutSocial\Controllers\MastodonApiController::fetchStatusRow($db, $statusId);
                    $displayName = $author['display_name'] ?: $author['username'];
                    $bodyText = html_entity_decode(strip_tags($status['content']), ENT_QUOTES, 'UTF-8');
                    $token = \KutSocial\Controllers\MastodonApiController::generateTokenForUser((int)$parentUser['id']);
                    $formattedAuthor = \KutSocial\Controllers\MastodonApiController::formatAccount($author);
                    $iconUrl = $formattedAuthor['avatar'] ?? '';

                    $notificationPayload = [
                        'id' => 'mention_' . $statusId,
                        'type' => 'mention',
                        'created_at' => date('c'),
                        'account' => $formattedAuthor,
                        'status' => \KutSocial\Controllers\MastodonApiController::formatStatus($statusRow, $parentUser['id']),

                        // New fields for mobile push clients
                        'notification_id' => 'mention_' . $statusId,
                        'notification_type' => 'mention',
                        'access_token' => $token,
                        'preferred_locale' => 'es',
                        'icon' => $iconUrl,
                        'title' => 'Nueva respuesta',
                        'body' => $displayName . ': ' . $bodyText,
                    ];
                    self::dispatchPushNotification($parentUser['id'], 'mention', $notificationPayload);

                    $recipientId = $parentUser['id'];
                }
            }

            // 2. Buscar menciones en el contenido del post
            $stmtLocal = $db->prepare("SELECT * FROM accounts WHERE domain IS NULL");
            $stmtLocal->execute();
            $localUsers = $stmtLocal->fetchAll();
            foreach ($localUsers as $user) {
                if ($user['id'] == $author['id'] || $user['id'] == $recipientId) {
                    continue;
                }
                $pattern = '/@' . preg_quote($user['username'], '/') . '(?![a-zA-Z0-9_\-])/i';
                $urlPattern = '/\/users\/' . preg_quote($user['username'], '/') . '(?![a-zA-Z0-9_\-])/i';
                if (preg_match($pattern, $status['content']) || preg_match($urlPattern, $status['content'])) {
                    if ($user['email_notifications']) {
                        self::sendEmailNotification(
                            $user,
                            "[KutSocial] Te han mencionado en una publicación",
                            "<h3>¡Hola @{$user['username']}!</h3>
                             <p><strong>$authorName</strong> ($authorHandle) te ha mencionado en una publicación:</p>
                             <div style='border-left: 4px solid #6366f1; padding-left: 12px; margin: 15px 0; color: #cbd5e1; font-style: italic; background: rgba(255,255,255,0.02); padding: 10px; border-radius: 6px;'>
                                 {$status['content']}
                             </div>
                             <p>Puedes verla en tu panel de KutSocial.</p>"
                        );
                    }

                    // Dispatch Push Notification for mention
                    $statusRow = \KutSocial\Controllers\MastodonApiController::fetchStatusRow($db, $statusId);
                    $displayName = $author['display_name'] ?: $author['username'];
                    $bodyText = html_entity_decode(strip_tags($status['content']), ENT_QUOTES, 'UTF-8');
                    $token = \KutSocial\Controllers\MastodonApiController::generateTokenForUser((int)$user['id']);
                    $formattedAuthor = \KutSocial\Controllers\MastodonApiController::formatAccount($author);
                    $iconUrl = $formattedAuthor['avatar'] ?? '';

                    $notificationPayload = [
                        'id' => 'mention_' . $statusId . '_' . $user['id'],
                        'type' => 'mention',
                        'created_at' => date('c'),
                        'account' => $formattedAuthor,
                        'status' => \KutSocial\Controllers\MastodonApiController::formatStatus($statusRow, $user['id']),

                        // New fields for mobile push clients
                        'notification_id' => 'mention_' . $statusId . '_' . $user['id'],
                        'notification_type' => 'mention',
                        'access_token' => $token,
                        'preferred_locale' => 'es',
                        'icon' => $iconUrl,
                        'title' => 'Nueva mención',
                        'body' => $displayName . ': ' . $bodyText,
                    ];
                    self::dispatchPushNotification($user['id'], 'mention', $notificationPayload);
                }
            }
        } catch (Exception $e) {
            error_log("Failed to process notifyMentionOrReply: " . $e->getMessage());
        }
    }

    public static function notifyFollow(int $localAccountId, int $followerAccountId): void {
        try {
            $db = Database::connect();
            
            $stmtRecipient = $db->prepare("SELECT * FROM accounts WHERE id = ? AND domain IS NULL LIMIT 1");
            $stmtRecipient->execute([$localAccountId]);
            $recipient = $stmtRecipient->fetch();
            if (!$recipient) {
                return;
            }

            $stmtFollower = $db->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
            $stmtFollower->execute([$followerAccountId]);
            $follower = $stmtFollower->fetch();
            if (!$follower) return;

            // Enviar email si está activo
            if ($recipient['email_notifications']) {
                $followerHandle = $follower['domain'] ? '@' . $follower['username'] . '@' . $follower['domain'] : '@' . $follower['username'];
                $followerName = $follower['display_name'] ?: $follower['username'];

                self::sendEmailNotification(
                    $recipient,
                    "[KutSocial] @{$follower['username']} te ha seguido",
                    "<h3>¡Hola @{$recipient['username']}!</h3>
                     <p><strong>$followerName</strong> ($followerHandle) te ha seguido en KutSocial.</p>
                     <p>Puedes ver su perfil en tu panel de KutSocial.</p>"
                );
            }

            // Dispatch Push Notification
            $followId = null;
            try {
                $stmtFollowId = $db->prepare("SELECT id FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
                $stmtFollowId->execute([$followerAccountId, $localAccountId]);
                $followId = $stmtFollowId->fetchColumn();
            } catch (\Exception $e) {
                // Ignorar
            }
            $notifId = $followId ? 'follow_' . $followId : 'follow_' . $followerAccountId . '_' . time();

            $displayName = $follower['display_name'] ?: $follower['username'];
            $token = \KutSocial\Controllers\MastodonApiController::generateTokenForUser((int)$localAccountId);
            $formattedFollower = \KutSocial\Controllers\MastodonApiController::formatAccount($follower);
            $iconUrl = $formattedFollower['avatar'] ?? '';

            $notificationPayload = [
                'id' => $notifId,
                'type' => 'follow',
                'created_at' => date('c'),
                'account' => $formattedFollower,

                // New fields for mobile push clients
                'notification_id' => $notifId,
                'notification_type' => 'follow',
                'access_token' => $token,
                'preferred_locale' => 'es',
                'icon' => $iconUrl,
                'title' => 'Nuevo seguidor',
                'body' => $displayName . ' te ha seguido',
            ];
            self::dispatchPushNotification($localAccountId, 'follow', $notificationPayload);
        } catch (Exception $e) {
            error_log("Failed to process notifyFollow: " . $e->getMessage());
        }
    }

    /**
     * Fetch page open graph details for a link preview card.
     */
    public static function fetchLinkCard(string $url): ?array {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || $host === ($_SERVER['HTTP_HOST'] ?? 'localhost') || $host === '127.0.0.1') {
            return null;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, \KutSocial\Database::verifySsl());
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, \KutSocial\Database::verifySsl() ? 2 : 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'KutSocial-CardParser/1.0');
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$html) {
            return null;
        }

        $title = '';
        $description = '';
        $image = '';
        $creator = '';

        if (preg_match('/<title>(.*?)<\/title>/is', $html, $matches)) {
            $title = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        }
        if (preg_match('/<meta[^>]+(?:property|name)="og:title"[^>]+content="([^"]+)"/i', $html, $matches)) {
            $title = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        }

        if (preg_match('/<meta[^>]+name="description"[^>]+content="([^"]+)"/i', $html, $matches)) {
            $description = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        }
        if (preg_match('/<meta[^>]+(?:property|name)="og:description"[^>]+content="([^"]+)"/i', $html, $matches)) {
            $description = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        }

        if (preg_match('/<meta[^>]+(?:property|name)="og:image"[^>]+content="([^"]+)"/i', $html, $matches)) {
            $image = $matches[1];
        }

        if (preg_match('/<meta[^>]+(?:name|property)="fediverse:creator"[^>]+content="([^"]+)"/i', $html, $matches)) {
            $creator = trim($matches[1]);
        }

        $card = [
            'url' => $url,
            'title' => $title ?: $host,
            'description' => $description ?: '',
            'image' => $image ?: null,
            'type' => 'link',
            'author_name' => null,
            'author_url' => null,
            'provider_name' => $host,
            'provider_url' => 'https://' . $host
        ];

        if (!empty($creator)) {
            $creator = ltrim($creator, '@');
            $parts = explode('@', $creator);
            if (count($parts) === 2) {
                $username = $parts[0];
                $domain = $parts[1];

                $db = Database::connect();
                $stmt = $db->prepare("SELECT * FROM accounts WHERE username = ? AND (domain = ? OR (domain IS NULL AND ? = ?)) LIMIT 1");
                $stmt->execute([$username, $domain, $domain, $_SERVER['HTTP_HOST'] ?? 'localhost']);
                $acc = $stmt->fetch();

                if ($acc) {
                    $allowed = false;
                    $domainsList = array_map('trim', explode("\n", $acc['attribution_domains'] ?? ''));
                    foreach ($domainsList as $d) {
                        if (empty($d)) continue;
                        if (strcasecmp($host, $d) === 0 || str_ends_with(strtolower($host), '.' . strtolower($d))) {
                            $allowed = true;
                            break;
                        }
                    }

                    if ($allowed) {
                        $card['author_name'] = $acc['display_name'] ?: $acc['username'];
                        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                        $localDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $card['author_url'] = $acc['domain'] ? $acc['url'] : "$proto://$localDomain/users/{$acc['username']}";
                    }
                }
            }
        }

        return $card;
    }

    /**
     * Extract the first link in content, parse it, and save the card.
     */
    public static function fetchAndSaveLinkCard(int $statusId, string $content): void {
        if (preg_match_all('/\bhttps?:\/\/[^\s<"\']+/i', $content, $matches)) {
            $urls = $matches[0];
            foreach ($urls as $url) {
                if (strpos($url, '/tags/') !== false || strpos($url, '/users/') !== false || strpos($url, '/@') !== false) {
                    continue;
                }
                $card = self::fetchLinkCard($url);
                if ($card) {
                    $db = Database::connect();
                    $stmt = $db->prepare("UPDATE statuses SET card = ? WHERE id = ?");
                    $stmt->execute([json_encode($card, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $statusId]);
                    break;
                }
            }
        }
    }

    /**
     * Dispatch push notifications to all active subscriptions of an account.
     */
    public static function dispatchPushNotification(int $recipientId, string $type, array $notificationPayload): void {
        try {
            $db = Database::connect();
            $stmt = $db->prepare("SELECT * FROM web_push_subscriptions WHERE account_id = ?");
            $stmt->execute([$recipientId]);
            $subs = $stmt->fetchAll();
            if (empty($subs)) {
                return;
            }

            foreach ($subs as $sub) {
                $alerts = json_decode($sub['alerts'], true) ?: [];
                if (!isset($alerts[$type]) || !$alerts[$type]) {
                    continue;
                }

                WebPushHelper::sendPush($sub['endpoint'], $sub['key_p256dh'], $sub['key_auth'], $notificationPayload);
            }
        } catch (\Throwable $e) {
            error_log("Failed to dispatch push notification: " . $e->getMessage());
        }
    }

    /**
     * Notify about a favourite event via push notification.
     */
    public static function notifyFavourite(int $statusId, int $favoritedByAccountId): void {
        try {
            $db = Database::connect();
            $stmt = $db->prepare("
                SELECT s.account_id, a.domain
                FROM statuses s
                JOIN accounts a ON s.account_id = a.id
                WHERE s.id = ?
                LIMIT 1
            ");
            $stmt->execute([$statusId]);
            $author = $stmt->fetch();
            if (!$author || $author['domain'] !== null) {
                return; // Only notify local authors
            }

            $statusRow = \KutSocial\Controllers\MastodonApiController::fetchStatusRow($db, $statusId);
            $stmtTrigger = $db->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
            $stmtTrigger->execute([$favoritedByAccountId]);
            $trigger = $stmtTrigger->fetch();
            if (!$trigger) return;

            $favId = null;
            try {
                $stmtFavId = $db->prepare("SELECT id FROM favourites WHERE status_id = ? AND account_id = ? LIMIT 1");
                $stmtFavId->execute([$statusId, $favoritedByAccountId]);
                $favId = $stmtFavId->fetchColumn();
            } catch (\Exception $e) {
                // Ignorar
            }
            $notifId = $favId ? 'favourite_' . $favId : 'favourite_' . $statusId . '_' . $favoritedByAccountId;

            $displayName = $trigger['display_name'] ?: $trigger['username'];
            $bodyText = html_entity_decode(strip_tags($statusRow['status_content']), ENT_QUOTES, 'UTF-8');
            if (mb_strlen($bodyText) > 60) {
                $bodyText = mb_substr($bodyText, 0, 57) . '...';
            }
            $token = \KutSocial\Controllers\MastodonApiController::generateTokenForUser((int)$author['account_id']);
            $formattedTrigger = \KutSocial\Controllers\MastodonApiController::formatAccount($trigger);
            $iconUrl = $formattedTrigger['avatar'] ?? '';

            $notificationPayload = [
                'id' => $notifId,
                'type' => 'favourite',
                'created_at' => date('c'),
                'account' => $formattedTrigger,
                'status' => \KutSocial\Controllers\MastodonApiController::formatStatus($statusRow, $author['account_id']),

                // New fields for mobile push clients
                'notification_id' => $notifId,
                'notification_type' => 'favourite',
                'access_token' => $token,
                'preferred_locale' => 'es',
                'icon' => $iconUrl,
                'title' => 'Nuevo me gusta',
                'body' => $displayName . ' le dio me gusta a tu publicación: "' . $bodyText . '"',
            ];
            self::dispatchPushNotification($author['account_id'], 'favourite', $notificationPayload);
        } catch (\Throwable $e) {
            error_log("Failed to process notifyFavourite: " . $e->getMessage());
        }
    }

    /**
     * Notify about a reblog event via push notification.
     */
    public static function notifyReblog(int $statusId, int $rebloggedByAccountId): void {
        try {
            $db = Database::connect();
            $stmt = $db->prepare("
                SELECT s.account_id, a.domain
                FROM statuses s
                JOIN accounts a ON s.account_id = a.id
                WHERE s.id = ?
                LIMIT 1
            ");
            $stmt->execute([$statusId]);
            $author = $stmt->fetch();
            if (!$author || $author['domain'] !== null) {
                return; // Only notify local authors
            }

            $statusRow = \KutSocial\Controllers\MastodonApiController::fetchStatusRow($db, $statusId);
            $stmtTrigger = $db->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
            $stmtTrigger->execute([$rebloggedByAccountId]);
            $trigger = $stmtTrigger->fetch();
            if (!$trigger) return;

            $reblogStatusId = null;
            try {
                $stmtReblogId = $db->prepare("SELECT id FROM statuses WHERE reblog_of_id = ? AND account_id = ? LIMIT 1");
                $stmtReblogId->execute([$statusId, $rebloggedByAccountId]);
                $reblogStatusId = $stmtReblogId->fetchColumn();
            } catch (\Exception $e) {
                // Ignorar
            }
            $notifId = $reblogStatusId ? 'reblog_' . $reblogStatusId : 'reblog_' . $statusId . '_' . $rebloggedByAccountId;

            $displayName = $trigger['display_name'] ?: $trigger['username'];
            $bodyText = html_entity_decode(strip_tags($statusRow['status_content']), ENT_QUOTES, 'UTF-8');
            if (mb_strlen($bodyText) > 60) {
                $bodyText = mb_substr($bodyText, 0, 57) . '...';
            }
            $token = \KutSocial\Controllers\MastodonApiController::generateTokenForUser((int)$author['account_id']);
            $formattedTrigger = \KutSocial\Controllers\MastodonApiController::formatAccount($trigger);
            $iconUrl = $formattedTrigger['avatar'] ?? '';

            $notificationPayload = [
                'id' => $notifId,
                'type' => 'reblog',
                'created_at' => date('c'),
                'account' => $formattedTrigger,
                'status' => \KutSocial\Controllers\MastodonApiController::formatStatus($statusRow, $author['account_id']),

                // New fields for mobile push clients
                'notification_id' => $notifId,
                'notification_type' => 'reblog',
                'access_token' => $token,
                'preferred_locale' => 'es',
                'icon' => $iconUrl,
                'title' => 'Nueva publicación compartida',
                'body' => $displayName . ' compartió tu publicación: "' . $bodyText . '"',
            ];
            self::dispatchPushNotification($author['account_id'], 'reblog', $notificationPayload);
        } catch (\Throwable $e) {
            error_log("Failed to process notifyReblog: " . $e->getMessage());
        }
    }
}

