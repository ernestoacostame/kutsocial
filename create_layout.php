<?php
$file = __DIR__ . '/src/views/frontend.html';
$content = file_get_contents($file);

$startToken = '<div id="tab-feed">';
$endToken = '</main>';

$startPos = strpos($content, $startToken);
$endPos = strpos($content, $endToken);

if ($startPos !== false && $endPos !== false) {
    $before = substr($content, 0, $startPos);
    $after = substr($content, $endPos);
    
    $includes = "
            <?php include __DIR__ . '/frontend/feed.php'; ?>

            <?php include __DIR__ . '/frontend/notifications.php'; ?>

            <?php include __DIR__ . '/frontend/profile.php'; ?>

            <?php include __DIR__ . '/frontend/profile-view.php'; ?>

            <?php include __DIR__ . '/frontend/lists.php'; ?>

            <?php include __DIR__ . '/frontend/collections.php'; ?>

            <?php include __DIR__ . '/frontend/followed-hashtags.php'; ?>

            <?php include __DIR__ . '/frontend/users-list.php'; ?>

            <?php include __DIR__ . '/frontend/search-results.php'; ?>

            <?php include __DIR__ . '/frontend/thread-view.php'; ?>
    ";
    
    $newContent = $before . $includes . "\n        " . $after;
    
    if (!is_dir(__DIR__ . '/src/views/frontend')) {
        mkdir(__DIR__ . '/src/views/frontend', 0755, true);
    }
    file_put_contents(__DIR__ . '/src/views/frontend/layout.php', $newContent);
    echo "Success";
} else {
    echo "Error: Tokens not found";
}
