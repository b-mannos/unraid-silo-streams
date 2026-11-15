<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/common.php';

function silo_reply(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $saved = silo_load_config();
    if ($method === 'GET') {
        if (!$saved['valid']) {
            silo_reply(['ok' => false, 'error' => silo_error('invalid_config', 'Saved settings could not be read. Open Silo Streams settings.')], 503);
        }
        $config = $saved['config'];
        if (!silo_config_configured($config)) {
            silo_reply(['ok' => false, 'error' => silo_error('not_configured', 'Add your Silo server and API key in settings.')], 409);
        }
        $fetch = silo_fetch_sessions($config);
        if (!$fetch['ok']) {
            silo_reply(['ok' => false, 'error' => $fetch['error']], 502);
        }
        silo_reply(silo_sessions_payload($config, $fetch));
    }
    if ($method !== 'POST') {
        header('Allow: GET, POST');
        silo_reply(['ok' => false, 'error' => silo_error('method', 'Use GET or POST.')], 405);
    }
    $csrf = silo_csrf_token();
    // Unraid's PHP prepend validates and removes its own csrf_token field.
    // Keep a namespaced copy for this endpoint's independent validation.
    $supplied = $_POST['silo_csrf_token'] ?? $_POST['csrf_token'] ?? null;
    if ($csrf === null || !is_string($supplied) || !hash_equals($csrf, $supplied)) {
        silo_reply(['ok' => false, 'error' => silo_error('csrf', 'Refresh the Unraid page and try again.')], 403);
    }
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['save', 'test'], true)) {
        silo_reply(['ok' => false, 'error' => silo_error('action', 'Choose Save or Test connection.')], 400);
    }
    $validation = silo_validate_config($_POST, $saved['config']);
    if (!$validation['ok']) {
        silo_reply(['ok' => false, 'error' => silo_error('validation', 'Check the highlighted settings.'), 'fields' => $validation['errors']], 422);
    }
    $config = $validation['config'];
    if ($action === 'test') {
        // A sessions request also verifies the permission needed by the dashboard.
        $result = silo_fetch_sessions($config);
        if (!$result['ok']) {
            silo_reply(['ok' => false, 'error' => $result['error']], 502);
        }
        silo_reply(['ok' => true, 'message' => 'Connected. ' . count($result['items']) . ' active stream(s).']);
    }
    $result = silo_write_config($config);
    if (!$result['ok']) {
        silo_reply(['ok' => false, 'error' => silo_error('write', $result['error'])], 500);
    }
    silo_reply(['ok' => true, 'message' => 'Settings saved.', 'key_configured' => true]);
} catch (Throwable $error) {
    // Do not expose upstream URLs, payloads, credentials or exception text.
    silo_reply(['ok' => false, 'error' => silo_error('internal', 'Silo Streams could not complete the request. Refresh and try again.')], 500);
}
