<?php
require_once __DIR__ . '/src/Database.php';

function verifyLinkRelation(string $url, string $username) {
    $proto = 'https';
    $domain = 'ernestoacosta.org';
    
    $profileUrl1 = "$proto://$domain/users/$username";
    $profileUrl2 = "$proto://$domain/@$username";

    echo "--------------------------------------------------\n";
    echo "Verifying URL: $url for user: $username\n";
    echo "Expecting profile URL 1: $profileUrl1\n";
    echo "Expecting profile URL 2: $profileUrl2\n";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_MAXFILESIZE => 1024000, // 1 MB
        CURLOPT_USERAGENT => 'KutSocial-LinkVerifier/1.0',
        CURLOPT_SSL_VERIFYPEER => false, // Para pruebas
        CURLOPT_FOLLOWLOCATION => true // Sigue redireccionamientos!
    ]);
    
    $html = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    echo "Effective URL: $effectiveUrl\n";
    echo "HTTP Status Code: $httpCode\n";
    if ($error) {
        echo "cURL Error: $error\n";
    }

    if ($httpCode !== 200 || empty($html)) {
        echo "Failed: empty HTML or non-200 code.\n";
        return false;
    }

    $escapedUrl1 = preg_quote($profileUrl1, '/');
    $escapedUrl2 = preg_quote($profileUrl2, '/');
    
    // Corregimos paréntesis cerrado extra en pattern2 en nuestra prueba
    $pattern = '/<(a|link)\s+[^>]*(href=["\'](' . $escapedUrl1 . '|' . $escapedUrl2 . ')["\'][^>]*rel=["\'][^"\']*me[^"\']*["\'])/i';
    $pattern2 = '/<(a|link)\s+[^>]*rel=["\'][^"\']*me[^"\']*["\']\s+[^>]*href=["\'](' . $escapedUrl1 . '|' . $escapedUrl2 . ')["\']/i';

    echo "Regex pattern 1: $pattern\n";
    echo "Regex pattern 2: $pattern2\n";

    $match1 = preg_match($pattern, $html, $matches1);
    $match2 = preg_match($pattern2, $html, $matches2);

    if ($match1) {
        echo "Matched pattern 1! Matches:\n";
        print_r($matches1);
        return true;
    }
    if ($match2) {
        echo "Matched pattern 2! Matches:\n";
        print_r($matches2);
        return true;
    }

    echo "No match. Searching for profile URL in HTML...\n";
    if (str_contains($html, 'ernestoacosta.org')) {
        echo "Found 'ernestoacosta.org' in HTML! Showing matches:\n";
        preg_match_all('/<(a|link)[^>]*ernestoacosta\.org[^>]*>/i', $html, $allMatches);
        print_r($allMatches[0]);
    } else {
        echo "Did not find 'ernestoacosta.org' in HTML at all!\n";
    }

    return false;
}

verifyLinkRelation('https://www.systeminside.net', 'iam');
verifyLinkRelation('https://www.tupodcast.com', 'iam');
verifyLinkRelation('https://ernestoacosta.me', 'iam');
