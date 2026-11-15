<?php

declare(strict_types=1);

const SILO_STREAMS_VERSION = '2026.11.15c';
const SILO_STREAMS_DEFAULT_CONFIG_PATH = '/boot/config/plugins/silo-streams/config.json';
const SILO_STREAMS_DEFAULT_VAR_INI = '/var/local/emhttp/var.ini';
const SILO_STREAMS_SESSIONS_PATH = '/api/v2/admin/sessions';
const SILO_STREAMS_CAPABILITIES_PATH = '/api/v2/admin/sessions/capabilities';
const SILO_STREAMS_PAGE_LIMIT = 200;
const SILO_STREAMS_MAX_PAGES = 10;
const SILO_STREAMS_MAX_RESPONSE_BYTES = 8388608;
const SILO_STREAMS_MAX_CONFIG_BYTES = 65536;
const SILO_STREAMS_CONNECT_TIMEOUT = 4;
const SILO_STREAMS_REQUEST_TIMEOUT = 8;
const SILO_STREAMS_AGGREGATE_DEADLINE = 15.0;

function silo_config_path(): string
{
    $override = getenv('SILO_STREAMS_CONFIG_PATH');
    if (is_string($override) && $override !== '') {
        return $override;
    }
    return SILO_STREAMS_DEFAULT_CONFIG_PATH;
}

function silo_var_ini_path(): string
{
    $override = getenv('SILO_STREAMS_VAR_INI');
    if (is_string($override) && $override !== '') {
        return $override;
    }
    return SILO_STREAMS_DEFAULT_VAR_INI;
}

function silo_default_config(): array
{
    return [
        'server_name' => '',
        'server_url' => '',
        'api_key' => '',
        'poll_interval' => 5,
    ];
}

function silo_error(string $code, string $message): array
{
    return ['code' => $code, 'message' => $message];
}

function silo_display_text($value, int $maxBytes = 512): ?string
{
    if (is_int($value) || is_float($value)) {
        $value = (string) $value;
    }
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
    if (!is_string($value)) {
        return null;
    }
    $value = preg_replace('/\s+/u', ' ', $value);
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (strlen($value) > $maxBytes) {
        $value = function_exists('mb_substr')
            ? mb_substr($value, 0, $maxBytes, 'UTF-8')
            : substr($value, 0, $maxBytes);
        $value = rtrim((string) $value);
    }
    return $value === '' ? null : $value;
}

function silo_token_text($value, int $maxBytes = 128): ?string
{
    if (is_int($value)) {
        $value = (string) $value;
    }
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if ($value === '' || strlen($value) > $maxBytes) {
        return null;
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
        return null;
    }
    return $value;
}

function silo_valid_api_key($key): bool
{
    return is_string($key)
        && $key !== ''
        && strlen($key) <= 4096
        && preg_match('/\A[\x21-\x7E]+\z/', $key) === 1;
}

function silo_validate_server_url($value): array
{
    if (!is_string($value)) {
        return ['ok' => false, 'message' => 'Server URL must be text.'];
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
        return ['ok' => false, 'message' => 'Server URL cannot contain control characters.'];
    }
    $url = trim($value);
    if ($url === '') {
        return ['ok' => false, 'message' => 'Server URL is required.'];
    }
    if (strlen($url) > 2048) {
        return ['ok' => false, 'message' => 'Server URL is too long.'];
    }
    if (preg_match('/[\x00-\x20\x7F]/', $url)) {
        return ['ok' => false, 'message' => 'Server URL cannot contain spaces or control characters.'];
    }
    if (strpos($url, '\\') !== false) {
        return ['ok' => false, 'message' => 'Server URL contains an unsupported character.'];
    }
    if (strpos($url, '?') !== false || strpos($url, '#') !== false) {
        return ['ok' => false, 'message' => 'Remove the query string and fragment from the server URL.'];
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return ['ok' => false, 'message' => 'Server URL is not valid.'];
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return ['ok' => false, 'message' => 'Use an http:// or https:// server URL.'];
    }
    $host = $parts['host'] ?? '';
    if (!is_string($host) || $host === '') {
        return ['ok' => false, 'message' => 'Server URL needs a host.'];
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return ['ok' => false, 'message' => 'Remove credentials from the server URL; the API key is stored separately.'];
    }
    if (preg_match('/[\x00-\x20\x7F\/@?#]/', $host)) {
        return ['ok' => false, 'message' => 'Server URL has an invalid host.'];
    }
    if (isset($parts['port'])) {
        $port = $parts['port'];
        if (!is_int($port) || $port < 1 || $port > 65535) {
            return ['ok' => false, 'message' => 'Server URL has an invalid port.'];
        }
    }
    $path = (string) ($parts['path'] ?? '');
    if ($path !== '') {
        if (preg_match('#(^|/)\.\.?(/|$)#', $path)) {
            return ['ok' => false, 'message' => 'Server URL cannot contain dot segments.'];
        }
        if (preg_match('#(^|/)(%2e%2e|%2e)(/|$)#i', $path)) {
            return ['ok' => false, 'message' => 'Server URL cannot contain encoded dot segments.'];
        }
    }

    $normalized = $scheme . '://' . strtolower($host);
    if (isset($parts['port'])) {
        $normalized .= ':' . (int) $parts['port'];
    }
    $normalized .= rtrim($path, '/');

    return ['ok' => true, 'url' => $normalized];
}

function silo_validate_config(array $raw, array $existing = [], bool $requireCredentials = true): array
{
    $errors = [];
    $config = silo_default_config();

    $nameRaw = $raw['server_name'] ?? '';
    if ($nameRaw === null) {
        $nameRaw = '';
    }
    if (!is_string($nameRaw)) {
        $errors['server_name'] = 'Server name must be text.';
    } else {
        $name = trim($nameRaw);
        if ($name !== '' && preg_match('/[\x00-\x1F\x7F]/', $name)) {
            $errors['server_name'] = 'Server name cannot contain control characters.';
        } elseif (strlen($name) > 160) {
            $errors['server_name'] = 'Server name is too long.';
        } else {
            $config['server_name'] = $name;
        }
    }

    $urlRaw = $raw['server_url'] ?? '';
    if ($urlRaw === null) {
        $urlRaw = '';
    }
    if ($urlRaw === '') {
        if ($requireCredentials) {
            $errors['server_url'] = 'Server URL is required.';
        }
    } else {
        $urlResult = silo_validate_server_url($urlRaw);
        if ($urlResult['ok'] !== true) {
            $errors['server_url'] = (string) ($urlResult['message'] ?? 'Server URL is not valid.');
        } else {
            $config['server_url'] = (string) $urlResult['url'];
        }
    }

    $keyRaw = $raw['api_key'] ?? '';
    $existingKey = is_string($existing['api_key'] ?? null) ? $existing['api_key'] : '';
    $key = '';
    if ($keyRaw === null) {
        $keyRaw = '';
    }
    if (!is_string($keyRaw)) {
        $errors['api_key'] = 'API key must be text.';
    } else {
        $key = trim($keyRaw);
    }
    if ($key === '') {
        $key = $existingKey;
    }
    if ($key !== '' && !silo_valid_api_key($key)) {
        $errors['api_key'] = 'API key contains unsupported characters.';
    }
    if ($requireCredentials && $key === '') {
        $errors['api_key'] = 'API key is required.';
    }
    $config['api_key'] = $key;

    $intervalRaw = array_key_exists('poll_interval', $raw) ? $raw['poll_interval'] : null;
    if ($intervalRaw === null || $intervalRaw === '') {
        $intervalRaw = $existing['poll_interval'] ?? 5;
    }
    if (is_int($intervalRaw) || is_float($intervalRaw)) {
        $interval = is_float($intervalRaw) && floor($intervalRaw) !== $intervalRaw ? null : (int) $intervalRaw;
    } elseif (is_string($intervalRaw) && preg_match('/\A\d+\z/', trim($intervalRaw))) {
        $interval = (int) trim($intervalRaw);
    } else {
        $interval = null;
    }
    if ($interval === null) {
        $errors['poll_interval'] = 'Poll interval must be between 5 and 60 seconds.';
    } elseif ($interval < 5 || $interval > 60) {
        $errors['poll_interval'] = 'Poll interval must be between 5 and 60 seconds.';
    } else {
        $config['poll_interval'] = $interval;
    }

    return [
        'ok' => $errors === [],
        'config' => $config,
        'errors' => $errors,
    ];
}

function silo_config_configured(array $config): bool
{
    return is_string($config['server_url'] ?? null)
        && $config['server_url'] !== ''
        && silo_valid_api_key($config['api_key'] ?? '');
}

function silo_config_public(array $config): array
{
    return [
        'server_name' => (string) ($config['server_name'] ?? ''),
        'server_url' => (string) ($config['server_url'] ?? ''),
        'poll_interval' => (int) ($config['poll_interval'] ?? 5),
        'key_configured' => silo_valid_api_key($config['api_key'] ?? ''),
    ];
}

function silo_load_config(?string $path = null): array
{
    $path ??= silo_config_path();
    $default = silo_default_config();

    if (!is_file($path)) {
        return [
            'config' => $default,
            'raw' => ['server_name' => '', 'server_url' => '', 'poll_interval' => 5],
            'exists' => false,
            'valid' => true,
            'error' => null,
            'errors' => [],
        ];
    }
    if (!is_readable($path)) {
        return [
            'config' => $default,
            'raw' => ['server_name' => '', 'server_url' => '', 'poll_interval' => 5],
            'exists' => true,
            'valid' => false,
            'error' => 'Saved configuration cannot be read.',
            'errors' => [],
        ];
    }
    $contents = @file_get_contents($path);
    if (!is_string($contents) || strlen($contents) > SILO_STREAMS_MAX_CONFIG_BYTES) {
        return [
            'config' => $default,
            'raw' => ['server_name' => '', 'server_url' => '', 'poll_interval' => 5],
            'exists' => true,
            'valid' => false,
            'error' => 'Saved configuration could not be read.',
            'errors' => [],
        ];
    }
    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return [
            'config' => $default,
            'raw' => ['server_name' => '', 'server_url' => '', 'poll_interval' => 5],
            'exists' => true,
            'valid' => false,
            'error' => 'Saved configuration could not be read.',
            'errors' => [],
        ];
    }

    $validation = silo_validate_config($decoded, [], false);
    $rawUrl = is_string($decoded['server_url'] ?? null)
        ? silo_display_text($decoded['server_url'], 2048)
        : null;
    $rawName = is_string($decoded['server_name'] ?? null)
        ? silo_display_text($decoded['server_name'], 160)
        : null;

    return [
        'config' => $validation['config'],
        'raw' => [
            'server_name' => $rawName ?? '',
            'server_url' => $rawUrl ?? '',
            'poll_interval' => $validation['config']['poll_interval'],
        ],
        'exists' => true,
        'valid' => $validation['ok'],
        'error' => $validation['ok'] ? null : 'Saved configuration is invalid.',
        'errors' => $validation['errors'],
    ];
}

function silo_write_config(array $config, ?string $path = null): array
{
    $path ??= silo_config_path();
    $dir = dirname($path);

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Configuration directory could not be created.'];
        }
    }
    // The Unraid boot flash uses FAT; mount permissions govern access there.
    @chmod($dir, 0700);

    $payload = [
        'server_name' => (string) ($config['server_name'] ?? ''),
        'server_url' => (string) ($config['server_url'] ?? ''),
        'api_key' => (string) ($config['api_key'] ?? ''),
        'poll_interval' => (int) ($config['poll_interval'] ?? 5),
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return ['ok' => false, 'error' => 'Configuration could not be encoded.'];
    }
    $contents = $json . "\n";

    try {
        $suffix = bin2hex(random_bytes(12));
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Configuration could not be written.'];
    }
    $tmp = $path . '.tmp.' . $suffix;

    $handle = @fopen($tmp, 'xb');
    if ($handle === false) {
        return ['ok' => false, 'error' => 'Configuration could not be written.'];
    }
    @chmod($tmp, 0600);

    $written = 0;
    $length = strlen($contents);
    while ($written < $length) {
        $chunk = @fwrite($handle, substr($contents, $written));
        if ($chunk === false || $chunk === 0) {
            @fclose($handle);
            @unlink($tmp);
            return ['ok' => false, 'error' => 'Configuration could not be written.'];
        }
        $written += $chunk;
    }
    if (!@fflush($handle) || !@fclose($handle)) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'Configuration could not be written.'];
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'Configuration could not be written.'];
    }
    @chmod($path, 0600);

    return ['ok' => true, 'path' => $path];
}

function silo_csrf_token(string $varIniPath = ''): ?string
{
    if ($varIniPath === '') {
        $varIniPath = silo_var_ini_path();
    }
    if (!is_file($varIniPath) || !is_readable($varIniPath)) {
        return null;
    }
    $contents = @file_get_contents($varIniPath);
    if (!is_string($contents) || strlen($contents) > 262144) {
        return null;
    }

    $token = null;
    $settings = @parse_ini_string($contents, false, INI_SCANNER_RAW);
    if (is_array($settings) && is_string($settings['csrf_token'] ?? null)) {
        $token = $settings['csrf_token'];
    }
    if ($token === null && preg_match('/^\s*csrf_token\s*=\s*"([^"\r\n]+)"/m', $contents, $matches)) {
        $token = $matches[1];
    }
    if (!is_string($token)) {
        return null;
    }
    $token = trim($token);
    if ($token === '' || strlen($token) > 256 || preg_match('/[\x00-\x1F\x7F]/', $token)) {
        return null;
    }
    return $token;
}

function silo_validate_endpoint(string $url): bool
{
    if ($url === '' || strlen($url) > 32768 || preg_match('/[\x00-\x20\x7F]/', $url)) {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }
    if (!is_string($parts['host'] ?? null) || $parts['host'] === '') {
        return false;
    }
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
        return false;
    }
    return true;
}

function silo_transport_message(string $code): string
{
    $messages = [
        'timeout' => 'The Silo server did not respond in time.',
        'tls' => 'TLS verification failed for the Silo server.',
        'too_large' => 'The Silo response was too large to process.',
        'invalid_response' => 'Silo returned a response that could not be read.',
        'upstream_auth' => 'Silo rejected the API key. Check the key and its admin monitoring permission.',
        'upstream_http' => 'Silo returned an error response.',
        'curl_missing' => 'PHP cURL is required on this server.',
        'invalid_key' => 'The saved API key is invalid.',
        'invalid_target' => 'The Silo endpoint is invalid.',
        'transport' => 'The Silo server could not be reached.',
    ];
    return $messages[$code] ?? 'The Silo server could not be reached.';
}

function silo_transport_curl(string $url, string $apiKey, int $maxBytes, int $timeoutMs = 8000): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'code' => 'curl_missing'];
    }
    $handle = curl_init($url);
    if ($handle === false) {
        return ['ok' => false, 'code' => 'transport'];
    }

    $body = '';
    $tooLarge = false;
    curl_setopt_array($handle, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_CONNECTTIMEOUT_MS => min(4000, max(1, $timeoutMs)),
        CURLOPT_TIMEOUT_MS => min(8000, max(1, $timeoutMs)),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
            'User-Agent: silo-streams/' . SILO_STREAMS_VERSION,
        ],
        CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (&$body, &$tooLarge, $maxBytes): int {
            $length = strlen($chunk);
            if (strlen($body) + $length > $maxBytes) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return $length;
        },
    ]);

    curl_exec($handle);
    $errno = curl_errno($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if ($tooLarge) {
        return ['ok' => false, 'code' => 'too_large'];
    }
    if ($errno === CURLE_OPERATION_TIMEDOUT) {
        return ['ok' => false, 'code' => 'timeout'];
    }
    if ($errno === CURLE_SSL_CACERT || $errno === CURLE_SSL_PEER_CERTIFICATE || $errno === CURLE_SSL_CONNECT_ERROR) {
        return ['ok' => false, 'code' => 'tls'];
    }
    if ($errno !== 0) {
        return ['ok' => false, 'code' => 'transport'];
    }
    return ['ok' => true, 'status' => $status, 'body' => $body];
}

function silo_http_get_json(string $url, string $apiKey, ?callable $transport = null, int $maxBytes = SILO_STREAMS_MAX_RESPONSE_BYTES, int $timeoutMs = 8000): array
{
    if (!silo_validate_endpoint($url)) {
        return ['ok' => false, 'code' => 'invalid_target'];
    }
    if (!silo_valid_api_key($apiKey)) {
        return ['ok' => false, 'code' => 'invalid_key'];
    }

    $transport ??= 'silo_transport_curl';
    try {
        $result = $transport($url, $apiKey, $maxBytes, $timeoutMs);
    } catch (Throwable $e) {
        return ['ok' => false, 'code' => 'transport'];
    }
    if (!is_array($result) || !isset($result['ok'])) {
        return ['ok' => false, 'code' => 'transport'];
    }
    if ($result['ok'] !== true) {
        $code = is_string($result['code'] ?? null) ? $result['code'] : 'transport';
        return ['ok' => false, 'code' => $code];
    }

    $status = (int) ($result['status'] ?? 0);
    if ($status === 401 || $status === 403) {
        return ['ok' => false, 'code' => 'upstream_auth'];
    }
    if ($status < 200 || $status > 299) {
        return ['ok' => false, 'code' => 'upstream_http'];
    }

    $body = $result['body'] ?? '';
    if (!is_string($body) || strlen($body) > $maxBytes) {
        return ['ok' => false, 'code' => 'too_large'];
    }
    $object = json_decode($body);
    if (json_last_error() !== JSON_ERROR_NONE || !is_object($object)) {
        return ['ok' => false, 'code' => 'invalid_response'];
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'code' => 'invalid_response'];
    }

    return ['ok' => true, 'data' => $data, 'status' => $status];
}

function silo_sessions_endpoint(string $baseUrl, ?string $cursor): string
{
    $query = ['limit' => SILO_STREAMS_PAGE_LIMIT];
    if (is_string($cursor) && $cursor !== '') {
        $query['cursor'] = $cursor;
    }
    return $baseUrl . SILO_STREAMS_SESSIONS_PATH . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function silo_fetch_failure(string $code, string $message, int $pagesFetched = 0): array
{
    return [
        'ok' => false,
        'items' => [],
        'pages' => $pagesFetched,
        'error' => silo_error($code, $message),
    ];
}

function silo_fetch_sessions(array $config, array $options = []): array
{
    $baseResult = silo_validate_server_url($config['server_url'] ?? '');
    if ($baseResult['ok'] !== true) {
        return silo_fetch_failure('invalid_config', 'The saved Silo server URL is invalid.');
    }
    $apiKey = $config['api_key'] ?? '';
    if (!silo_valid_api_key($apiKey)) {
        return silo_fetch_failure('invalid_config', 'The saved Silo API key is invalid.');
    }

    $transport = $options['transport'] ?? null;
    $now = $options['now'] ?? static function (): float {
        return microtime(true);
    };
    $deadlineSeconds = (float) ($options['deadline_seconds'] ?? SILO_STREAMS_AGGREGATE_DEADLINE);
    if ($deadlineSeconds <= 0) {
        $deadlineSeconds = SILO_STREAMS_AGGREGATE_DEADLINE;
    }

    $started = (float) $now();
    $deadline = $started + $deadlineSeconds;
    $baseUrl = (string) $baseResult['url'];
    $cursor = null;
    $seen = [];
    $items = [];
    $pages = 0;
    $sessionIds = [];

    while (true) {
        if ($pages >= SILO_STREAMS_MAX_PAGES) {
            return silo_fetch_failure(
                'page_limit',
                'Silo returned more session pages than this plugin can read.',
                $pages
            );
        }
        if ((float) $now() >= $deadline) {
            return silo_fetch_failure('deadline', 'Loading Silo sessions took too long.', $pages);
        }

        $url = silo_sessions_endpoint($baseUrl, $cursor);
        $remainingMs = max(1, (int) floor(($deadline - (float) $now()) * 1000));
        $response = silo_http_get_json($url, $apiKey, $transport, SILO_STREAMS_MAX_RESPONSE_BYTES, $remainingMs);
        if ((float) $now() > $deadline) {
            return silo_fetch_failure('deadline', 'Loading Silo sessions took too long.', $pages);
        }
        if ($response['ok'] !== true) {
            $code = (string) ($response['code'] ?? 'transport');
            return silo_fetch_failure($code, silo_transport_message($code), $pages);
        }

        $data = $response['data'];
        if (!array_key_exists('items', $data) || !is_array($data['items']) || !array_is_list($data['items']) || count($data['items']) > SILO_STREAMS_PAGE_LIMIT) {
            return silo_fetch_failure('invalid_response', silo_transport_message('invalid_response'), $pages);
        }
        foreach ($data['items'] as $item) {
            if (!is_array($item) || silo_token_text($item['session_id'] ?? null, 128) === null) {
                return silo_fetch_failure('invalid_response', silo_transport_message('invalid_response'), $pages);
            }
            $sessionId = (string) $item['session_id'];
            if (!isset($sessionIds[$sessionId])) {
                $items[] = $item;
                $sessionIds[$sessionId] = true;
            }
        }
        $pages++;

        $page = $data['page'] ?? null;
        if (!is_array($page) || !array_key_exists('has_more', $page) || !is_bool($page['has_more'])) {
            return silo_fetch_failure('invalid_response', silo_transport_message('invalid_response'), $pages);
        }
        $hasMore = $page['has_more'];
        if ($hasMore !== true) {
            break;
        }

        $next = $page['next_cursor'] ?? null;
        if (!is_string($next) || trim($next) === '' || strlen($next) > 8192) {
            return silo_fetch_failure(
                'missing_cursor',
                'Silo reported more sessions but did not provide a usable cursor.',
                $pages
            );
        }
        if (isset($seen[$next])) {
            return silo_fetch_failure('cursor_loop', 'Silo repeated a pagination cursor.', $pages);
        }
        $seen[$next] = true;
        $cursor = $next;
    }

    return [
        'ok' => true,
        'items' => $items,
        'pages' => $pages,
        'error' => null,
    ];
}

function silo_capability_tokens(array $data, int $depth = 0): array
{
    if ($depth > 8) {
        return [];
    }
    $tokens = [];
    foreach ($data as $key => $value) {
        if (is_string($key)) {
            $tokens[] = strtolower($key);
        }
        if (is_string($value)) {
            $tokens[] = strtolower(trim($value, " \t\n\r\0\x0B\"'"));
        } elseif (is_array($value)) {
            foreach (silo_capability_tokens($value, $depth + 1) as $token) {
                $tokens[] = $token;
            }
        }
    }
    return $tokens;
}

function silo_fetch_capabilities(array $config, array $options = []): array
{
    $baseResult = silo_validate_server_url($config['server_url'] ?? '');
    if ($baseResult['ok'] !== true) {
        return ['ok' => false, 'error' => silo_error('invalid_config', 'The Silo server URL is invalid.')];
    }
    $apiKey = $config['api_key'] ?? '';
    if (!silo_valid_api_key($apiKey)) {
        return ['ok' => false, 'error' => silo_error('invalid_config', 'The Silo API key is invalid.')];
    }

    $transport = $options['transport'] ?? null;
    $now = $options['now'] ?? static function (): float {
        return microtime(true);
    };
    $started = (float) $now();
    $url = (string) $baseResult['url'] . SILO_STREAMS_CAPABILITIES_PATH;
    $response = silo_http_get_json($url, $apiKey, $transport);
    if ($response['ok'] !== true) {
        $code = (string) ($response['code'] ?? 'transport');
        return ['ok' => false, 'error' => silo_error($code, silo_transport_message($code))];
    }

    $data = $response['data'];
    $latency = (int) round(((float) $now() - $started) * 1000);

    return [
        'ok' => true,
        'latency_ms' => max(0, $latency),
        'supports' => [
            'effective_play_method' => ($data['effective_play_method'] ?? false) === true,
            'stream_location' => ($data['stream_location'] ?? false) === true,
        ],
    ];
}

function silo_positive_kbps($value): ?int
{
    if (is_int($value) || is_float($value)) {
        $number = (float) $value;
    } elseif (is_string($value) && is_numeric(trim($value))) {
        $number = (float) trim($value);
    } else {
        return null;
    }
    if (!is_finite($number) || $number <= 0) {
        return null;
    }
    return (int) floor($number);
}

function silo_non_negative_int($value): ?int
{
    if (is_int($value)) {
        return $value >= 0 ? $value : null;
    }
    if (is_float($value)) {
        if (!is_finite($value) || $value < 0) {
            return null;
        }
        return (int) floor($value);
    }
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }
        $number = (float) $trimmed;
        return is_finite($number) && $number >= 0 ? (int) floor($number) : null;
    }
    return null;
}

function silo_bool_or_null($value): ?bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        if ($value === 1) {
            return true;
        }
        if ($value === 0) {
            return false;
        }
        return null;
    }
    if (is_string($value)) {
        $lower = strtolower(trim($value));
        if ($lower === 'true' || $lower === '1') {
            return true;
        }
        if ($lower === 'false' || $lower === '0') {
            return false;
        }
    }
    return null;
}

function silo_play_method_key(?string $value): string
{
    if ($value === null) {
        return 'unknown';
    }
    $normalized = strtolower($value);
    $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized);
    if (!is_string($normalized) || $normalized === '') {
        return 'unknown';
    }
    if ($normalized === 'directplay' || $normalized === 'direct' || $normalized === 'playdirect') {
        return 'direct';
    }
    if ($normalized === 'remux') {
        return 'remux';
    }
    if ($normalized === 'directstream') {
        return 'direct_stream';
    }
    if (strncmp($normalized, 'transcode', 9) === 0) {
        return 'transcode';
    }
    return 'unknown';
}

function silo_play_method_label(string $key): string
{
    $labels = [
        'direct' => 'Direct Play',
        'remux' => 'Remux',
        'direct_stream' => 'Direct Stream',
        'transcode' => 'Transcode',
        'unknown' => 'Unknown',
    ];
    return $labels[$key] ?? 'Unknown';
}

function silo_safe_poster_url($value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $url = trim($value);
    if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7F]/', $url)) {
        return null;
    }
    if (silo_artwork_path($url) !== null) {
        return $url;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return null;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }
    if (!is_string($parts['host'] ?? null) || $parts['host'] === '') {
        return null;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }
    return $url;
}

function silo_artwork_path(string $url): ?string
{
    if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
        return null;
    }
    $path = $parts['path'] ?? '';
    if (!str_starts_with($path, '/api/v2/artwork/') || str_contains($path, '//') || preg_match('/%(?:2f|5c)/i', $path)) {
        return null;
    }
    $decoded = rawurldecode($path);
    if (str_contains($decoded, '%') || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $decoded) || preg_match('/[\x00-\x20\x7F\\\\]/', $decoded)) {
        return null;
    }
    return $url;
}

function silo_artwork_request_url($poster, string $baseUrl): ?string
{
    if (!is_string($poster)) {
        return null;
    }
    $validated = silo_validate_server_url($baseUrl);
    if (!$validated['ok']) {
        return null;
    }
    $base = $validated['url'];
    $relative = silo_artwork_path($poster);
    if ($relative !== null) {
        return $base . $relative;
    }
    $source = parse_url($poster);
    $target = parse_url($base);
    if (!is_array($source) || !is_array($target) || isset($source['user']) || isset($source['pass']) || isset($source['fragment'])) {
        return null;
    }
    $port = static fn(array $url): int => (int) ($url['port'] ?? (($url['scheme'] ?? '') === 'https' ? 443 : 80));
    if (strtolower($source['scheme'] ?? '') !== $target['scheme'] || strtolower($source['host'] ?? '') !== $target['host'] || $port($source) !== $port($target)) {
        return null;
    }
    $path = $source['path'] ?? '';
    $prefix = $target['path'] ?? '';
    if ($prefix !== '' && str_starts_with($path, $prefix . '/api/v2/artwork/')) {
        $path = substr($path, strlen($prefix));
    }
    $candidate = $path . (isset($source['query']) ? '?' . $source['query'] : '');
    return silo_artwork_path($candidate) !== null ? $base . $candidate : null;
}

function silo_output_video(array $raw, string $methodKey): array
{
    $targetResolution = silo_display_text($raw['target_resolution'] ?? null, 64);
    $sourceResolution = silo_display_text($raw['source_video_resolution'] ?? null, 64);
    $targetCodec = silo_display_text($raw['target_video_codec'] ?? null, 64);
    $sourceCodec = silo_display_text($raw['source_video_codec'] ?? null, 64);
    $allowSource = in_array($methodKey, ['direct', 'remux', 'direct_stream'], true);

    return [
        'resolution' => $targetResolution ?? ($allowSource ? $sourceResolution : null),
        'codec' => $targetCodec ?? ($allowSource ? $sourceCodec : null),
    ];
}

function silo_normalize_session(array $raw): array
{
    $effectiveRaw = silo_token_text($raw['effective_play_method'] ?? null, 64);
    $playRaw = silo_token_text($raw['play_method'] ?? null, 64);
    $methodSource = $effectiveRaw !== null ? 'effective_play_method' : ($playRaw !== null ? 'play_method' : null);
    $methodValue = $effectiveRaw ?? $playRaw;
    $methodKey = silo_play_method_key($methodValue);
    $output = silo_output_video($raw, $methodKey);
    $paused = silo_bool_or_null($raw['is_paused'] ?? null);

    return [
        'session_id' => silo_token_text($raw['session_id'] ?? null, 128),
        'username' => silo_display_text($raw['username'] ?? null, 200),
        'profile_name' => silo_display_text($raw['profile_name'] ?? null, 200),
        'media_title' => silo_display_text($raw['media_title'] ?? null, 400),
        'series_name' => silo_display_text($raw['series_name'] ?? null, 400),
        'episode_name' => silo_display_text($raw['episode_name'] ?? null, 400),
        'season_number' => silo_non_negative_int($raw['season_number'] ?? null),
        'episode_number' => silo_non_negative_int($raw['episode_number'] ?? null),
        'poster_url' => silo_safe_poster_url($raw['poster_url'] ?? null),
        'position_seconds' => silo_non_negative_int($raw['position_seconds'] ?? null),
        'file_duration' => silo_non_negative_int($raw['file_duration'] ?? null),
        'is_paused' => $paused,
        'playback_state' => $paused === null ? 'unknown' : ($paused ? 'paused' : 'playing'),
        'effective_play_method' => $effectiveRaw,
        'play_method' => $playRaw,
        'play_method_used' => $methodValue,
        'play_method_key' => $methodKey,
        'play_method_label' => silo_play_method_label($methodKey),
        'play_method_source' => $methodSource,
        'stream_bitrate_kbps' => silo_positive_kbps($raw['stream_bitrate_kbps'] ?? null),
        'target_bitrate_kbps' => silo_positive_kbps($raw['target_bitrate_kbps'] ?? null),
        'source_bitrate_kbps' => silo_positive_kbps($raw['source_bitrate_kbps'] ?? null),
        'source_video_resolution' => silo_display_text($raw['source_video_resolution'] ?? null, 64),
        'source_video_codec' => silo_display_text($raw['source_video_codec'] ?? null, 64),
        'target_resolution' => silo_display_text($raw['target_resolution'] ?? null, 64),
        'target_video_codec' => silo_display_text($raw['target_video_codec'] ?? null, 64),
        'video_output_resolution' => $output['resolution'],
        'video_output_codec' => $output['codec'],
        'client_label' => silo_display_text($raw['client_label'] ?? null, 200),
        'client_name' => silo_display_text($raw['client_name'] ?? null, 200),
        'device_platform' => silo_display_text($raw['device_platform'] ?? null, 200),
        'node_display_name' => silo_display_text($raw['node_display_name'] ?? null, 200),
        'stream_location' => silo_display_text($raw['stream_location'] ?? null, 64),
    ];
}

function silo_normalize_sessions(array $rawItems): array
{
    $items = [];
    foreach ($rawItems as $raw) {
        if (is_array($raw)) {
            $items[] = silo_normalize_session($raw);
        }
    }
    return $items;
}

function silo_summarize_sessions(array $items): array
{
    $knownCount = 0;
    $knownKbps = 0;
    $paused = 0;
    $playing = 0;
    $unknownState = 0;

    foreach ($items as $item) {
        $bitrate = $item['stream_bitrate_kbps'] ?? null;
        if (is_int($bitrate) && $bitrate > 0) {
            $knownCount++;
            $knownKbps += $bitrate;
        }
        $state = $item['playback_state'] ?? 'unknown';
        if ($state === 'paused') {
            $paused++;
        } elseif ($state === 'playing') {
            $playing++;
        } else {
            $unknownState++;
        }
    }

    $count = count($items);
    return [
        'session_count' => $count,
        'playing_count' => $playing,
        'paused_count' => $paused,
        'unknown_state_count' => $unknownState,
        'known_bitrate_count' => $knownCount,
        'unknown_bitrate_count' => $count - $knownCount,
        'known_bitrate_kbps' => $knownKbps,
        'bitrate_complete' => $count > 0 ? ($count - $knownCount) === 0 : true,
    ];
}

function silo_server_label(array $config): string
{
    $name = silo_display_text($config['server_name'] ?? null, 160);
    if ($name !== null) {
        return $name;
    }
    $url = silo_validate_server_url($config['server_url'] ?? '');
    if ($url['ok'] === true) {
        $parts = parse_url((string) $url['url']);
        if (is_array($parts) && is_string($parts['host'] ?? null)) {
            return $parts['host'];
        }
    }
    return 'Silo';
}

function silo_sessions_payload(array $config, array $fetch): array
{
    $items = silo_normalize_sessions($fetch['items'] ?? []);
    foreach ($items as &$item) {
        if ($item['session_id'] !== null && silo_artwork_request_url($item['poster_url'], (string) ($config['server_url'] ?? '')) !== null) {
            $item['poster_url'] = '/plugins/silo-streams/poster.php?' . http_build_query(['session_id' => $item['session_id']], '', '&', PHP_QUERY_RFC3986);
        }
    }
    unset($item);
    return [
        'ok' => true,
        'version' => SILO_STREAMS_VERSION,
        'generated_at' => gmdate('c'),
        'pages' => (int) ($fetch['pages'] ?? 0),
        'poll_interval' => (int) ($config['poll_interval'] ?? 5),
        'server' => [
            'name' => silo_server_label($config),
        ],
        'items' => $items,
        'summary' => silo_summarize_sessions($items),
    ];
}
