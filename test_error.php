<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/version.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    echo "Connecting to Database...\n";
    $db = \KutSocial\Database::connect();
    
    echo "Running migrations...\n";
    \KutSocial\Database::runMigrations();
    
    echo "Simulating getPublicTimeline query...\n";
    $limit = 15;
    $stmt = $db->prepare("
        SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
               s.visibility as status_visibility, s.created_at as status_created_at, 
               s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments,
               a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
               a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
               a.locked, a.discoverable
        FROM statuses s
        JOIN accounts a ON s.account_id = a.id
        WHERE s.visibility = 'public'
        ORDER BY s.id DESC
        LIMIT $limit
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    echo "Fetched " . count($rows) . " rows.\n";
    
    echo "Formatting statuses...\n";
    foreach ($rows as $row) {
        $formatted = \KutSocial\Controllers\MastodonApiController::formatStatus($row, null);
        echo "Formatted status ID: " . $formatted['id'] . "\n";
    }
    
    echo "SUCCESS!\n";
} catch (\Throwable $e) {
    echo "ERROR THROWN:\n";
    echo $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
