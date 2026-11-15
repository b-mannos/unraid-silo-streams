<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/common.php';

function silo_poster_failure(int $status): never
{
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        silo_poster_failure(405);
    }
    $sessionId = $_GET['session_id'] ?? null;
    if (!is_string($sessionId) || silo_token_text($sessionId, 128) === null) {
        silo_poster_failure(400);
    }
    $saved = silo_load_config();
    if (!$saved['valid'] || !silo_config_configured($saved['config'])) {
        silo_poster_failure(503);
    }
    $config = $saved['config'];
    $sessions = silo_fetch_sessions($config);
    if (!$sessions['ok']) {
        silo_poster_failure(502);
    }
    $url = null;
    foreach ($sessions['items'] as $session) {
        if (($session['session_id'] ?? '') === $sessionId) {
            $url = silo_artwork_request_url($session['poster_url'] ?? null, $config['server_url']);
            break;
        }
    }
    if ($url === null) {
        silo_poster_failure(404);
    }
    // Only the configured Silo's artwork path is fetched. The browser supplies
    // a live session ID, never a host or URL. Signed artwork needs no API key.
    $curl = curl_init($url);
    if ($curl === false) {
        silo_poster_failure(502);
    }
    $body = '';
    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_CONNECTTIMEOUT_MS => 3000,
        CURLOPT_TIMEOUT_MS => 8000,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/png,image/jpeg,image/gif'],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 4 * 1024 * 1024) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    curl_exec($curl);
    $error = curl_errno($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($error !== 0 || $status !== 200) {
        silo_poster_failure(502);
    }
    $image = @getimagesizefromstring($body);
    $allowed = ['image/avif', 'image/webp', 'image/png', 'image/jpeg', 'image/gif'];
    if (!is_array($image) || !in_array($image['mime'] ?? '', $allowed, true) || $image[0] < 1 || $image[1] < 1 || $image[0] > 8000 || $image[1] > 8000) {
        silo_poster_failure(502);
    }
    header('Content-Type: ' . $image['mime']);
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: private, max-age=60');
    header('X-Content-Type-Options: nosniff');
    echo $body;
} catch (Throwable $error) {
    silo_poster_failure(500);
}
