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
     */
    public static function notifyMentionOrReply(int $statusId): void {
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
                    SELECT a.id, a.email, a.email_notifications, a.smtp_host, a.smtp_port, a.smtp_user, a.smtp_pass, a.smtp_from, a.username 
                    FROM statuses s
                    JOIN accounts a ON s.account_id = a.id
                    WHERE s.id = ? AND a.domain IS NULL LIMIT 1
                ");
                $stmtParent->execute([$status['in_reply_to_id']]);
                $parentUser = $stmtParent->fetch();
                if ($parentUser && $parentUser['id'] != $author['id']) {
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
                    $recipientId = $parentUser['id'];
                }
            }

            // 2. Buscar menciones en el contenido del post
            $stmtLocal = $db->prepare("SELECT * FROM accounts WHERE domain IS NULL AND email_notifications = 1");
            $stmtLocal->execute();
            $localUsers = $stmtLocal->fetchAll();
            foreach ($localUsers as $user) {
                if ($user['id'] == $author['id'] || $user['id'] == $recipientId) {
                    continue;
                }
                $pattern = '/@' . preg_quote($user['username'], '/') . '\b/i';
                if (preg_match($pattern, $status['content'])) {
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
            }
        } catch (Exception $e) {
            error_log("Failed to process notifyMentionOrReply: " . $e->getMessage());
        }
    }

    /**
     * Notify about a new follow.
     */
    public static function notifyFollow(int $localAccountId, int $followerAccountId): void {
        try {
            $db = Database::connect();
            
            $stmtRecipient = $db->prepare("SELECT * FROM accounts WHERE id = ? AND domain IS NULL LIMIT 1");
            $stmtRecipient->execute([$localAccountId]);
            $recipient = $stmtRecipient->fetch();
            if (!$recipient || !$recipient['email_notifications']) {
                return;
            }

            $stmtFollower = $db->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
            $stmtFollower->execute([$followerAccountId]);
            $follower = $stmtFollower->fetch();
            if (!$follower) return;

            $followerHandle = $follower['domain'] ? '@' . $follower['username'] . '@' . $follower['domain'] : '@' . $follower['username'];
            $followerName = $follower['display_name'] ?: $follower['username'];

            self::sendEmailNotification(
                $recipient,
                "[KutSocial] @{$follower['username']} te ha seguido",
                "<h3>¡Hola @{$recipient['username']}!</h3>
                 <p><strong>$followerName</strong> ($followerHandle) te ha seguido en KutSocial.</p>
                 <p>Puedes ver su perfil en tu panel de KutSocial.</p>"
            );
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
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
}
