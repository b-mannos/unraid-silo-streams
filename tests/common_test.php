<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/usr/local/emhttp/plugins/silo-streams/includes/common.php';
require_once __DIR__ . '/../src/usr/local/emhttp/plugins/silo-streams/includes/widget.php';
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function fixture_response(array $data, int $status = 200): array {
    return ['ok' => true, 'status' => $status, 'body' => json_encode($data)];
}
$config = ['server_name' => 'Cinema', 'server_url' => 'http://silo.test/prefix', 'api_key' => 'sa_fixture_only', 'poll_interval' => 5];
$row = ['session_id' => 'one', 'username' => '<img src=x onerror=alert(1)>', 'media_title' => 'First Light', 'is_paused' => false, 'effective_play_method' => 'transcode', 'source_video_codec' => 'hevc', 'source_video_resolution' => '2160p', 'target_video_codec' => 'h264', 'target_resolution' => '720p', 'stream_bitrate_kbps' => 600, 'file_duration' => 1800, 'position_seconds' => 600, 'season_number' => 0, 'episode_number' => 1, 'api_key' => 'must-not-leak'];
foreach (['file:///etc/passwd', 'http://user:pass@silo.test', "http://silo.test/\r\n", 'http://silo.test?token=secret', 'http://silo.test/#a', 'http://silo.test/../other', 'http://silo.test/%2e%2e/'] as $bad) {
    check(!silo_validate_server_url($bad)['ok'], 'Unsafe base URL accepted');
}
check(silo_validate_server_url('https://SILO.TEST:8090/prefix/')['url'] === 'https://silo.test:8090/prefix', 'Prefix normalization');
check(silo_validate_server_url('http://[::1]:8090')['ok'], 'IPv6 host');
check(!silo_valid_api_key("sa_x\r\nBad: value"), 'Header injection');
$v = silo_validate_config(['server_url' => $config['server_url'], 'server_name' => 'New', 'api_key' => '', 'poll_interval' => '10'], $config);
check($v['ok'] && $v['config']['api_key'] === $config['api_key'], 'Blank key must preserve saved key');
check(!silo_validate_config(['server_url' => $config['server_url'], 'api_key' => 'sa_fixture', 'poll_interval' => '4'])['ok'], 'Reject short interval');
check(str_contains(silo_widget_markup(60), 'data-poll-interval="60"'), 'Initial poll interval survives before first API success');

$normalized = silo_normalize_session($row);
check($normalized['season_number'] === 0, 'Season zero specials preserved');
check($normalized['video_output_resolution'] === '720p' && $normalized['video_output_codec'] === 'h264', 'Use transcode output');
check($normalized['playback_state'] === 'playing', 'Playing state');
check(!array_key_exists('api_key', $normalized), 'Drop unknown/private fields');
check($normalized['username'] === $row['username'], 'Retain literal text for safe DOM rendering');
$unknown = silo_normalize_session(['session_id' => 'unknown', 'source_bitrate_kbps' => 9000, 'source_video_codec' => 'h264']);
check($unknown['stream_bitrate_kbps'] === null, 'Source bitrate is not stream bandwidth');
check($unknown['file_duration'] === null && $unknown['playback_state'] === 'unknown', 'Unknown metadata stays unknown');
check($unknown['video_output_codec'] === null, 'Unknown method cannot imply output codec');
check(silo_safe_poster_url('javascript:alert(1)') === null, 'Reject executable poster');
check(silo_safe_poster_url('https://user:pass@image.test/a.jpg') === null, 'Reject poster userinfo');
check(silo_safe_poster_url('https://image.test/a.jpg?signature=fixture') !== null, 'Support signed image URL without API credentials');
$artwork = '/api/v2/artwork/tmdb/movies/123/poster/w300.hash.webp?signature=fixture';
check(silo_safe_poster_url($artwork) === $artwork, 'Keep signed relative native artwork');
check(silo_artwork_request_url($artwork, 'https://silo.test/prefix') === 'https://silo.test/prefix' . $artwork, 'Artwork preserves configured base prefix and signature');
check(silo_artwork_request_url('https://silo.test/prefix' . $artwork, 'https://silo.test/prefix') === 'https://silo.test/prefix' . $artwork, 'Allow same-origin absolute native artwork');
check(silo_artwork_request_url('https://silo.test:443' . $artwork, 'https://silo.test') === 'https://silo.test' . $artwork, 'Normalize default origin port');
foreach ([
    '//evil.test/api/v2/artwork/x.webp',
    'https://evil.test' . $artwork,
    'https://silo.test:8443' . $artwork,
    'http://silo.test' . $artwork,
    'https://user:pass@silo.test' . $artwork,
    '/api/v2/admin/settings',
    '/api/v2/artwork/../admin/settings',
    '/api/v2/artwork/%2e%2e/admin/settings',
    '/api/v2/artwork/%252e%252e/admin/settings',
    '/api/v2/artwork/a%2fb.webp',
    '/api/v2/artwork/a%5cb.webp',
    '/api/v2/artwork/a.webp#fragment',
    "/api/v2/artwork/a.webp\n",
] as $badArtwork) check(silo_artwork_request_url($badArtwork, 'https://silo.test') === null, 'Artwork proxy stays on configured origin and native artwork path');
$posterPayload = silo_sessions_payload($config, ['items' => [array_replace($row, ['session_id' => 'one+two', 'poster_url' => $artwork])], 'pages' => 1]);
check($posterPayload['items'][0]['poster_url'] === '/plugins/silo-streams/poster.php?session_id=one%2Btwo', 'Browser receives an opaque same-origin poster reference');
check(!str_contains(json_encode($posterPayload), 'signature=fixture'), 'Native signed artwork URL remains server-side');
$audio = silo_normalize_session(['session_id' => 'audio', 'effective_play_method' => 'direct_stream', 'source_video_codec' => 'hevc']);
check($audio['video_output_codec'] === 'hevc', 'Audio conversion copies source video');
$paused = silo_normalize_session(array_replace($row, ['session_id' => 'two', 'is_paused' => true]));
check($paused['playback_state'] === 'paused', 'Pause status');
$summary = silo_summarize_sessions([$normalized, $paused, $unknown]);
check($summary['session_count'] === 3 && $summary['known_bitrate_kbps'] === 1200 && !$summary['bitrate_complete'], 'Known subtotal explicitly incomplete');

$calls = [];
$transport = static function ($url, $key, $maxBytes, $timeoutMs) use (&$calls, $row): array {
    $calls[] = [$url, $key, $timeoutMs];
    if (count($calls) === 1) return fixture_response(['items' => [$row], 'page' => ['has_more' => true, 'next_cursor' => 'opaque+/=']]);
    return fixture_response(['items' => [array_replace($row, ['session_id' => 'two'])], 'page' => ['has_more' => false]]);
};
$fetch = silo_fetch_sessions($config, ['transport' => $transport]);
check($fetch['ok'] && count($fetch['items']) === 2 && $fetch['pages'] === 2, 'Follow every page');
check(str_contains($calls[0][0], '/prefix/api/v2/admin/sessions?limit=200'), 'Fixed native path with base prefix');
check(str_contains($calls[1][0], 'cursor=opaque%2B%2F%3D'), 'Opaque cursor query encoding');
check($calls[0][1] === $config['api_key'] && $calls[0][2] <= 15000, 'Pass key privately and bound deadline');
$payload = silo_sessions_payload($config, $fetch);
check(!str_contains(json_encode($payload), 'sa_fixture_only') && !str_contains(json_encode($payload), 'must-not-leak'), 'Public sessions payload has no credential');
check($payload['summary']['session_count'] === 2, 'Two viewers of same item remain distinct');
$same = silo_fetch_sessions($config, ['transport' => static fn() => fixture_response(['items' => [$row, $row], 'page' => ['has_more' => false]])]);
check($same['ok'] && count($same['items']) === 1, 'Repeated same session deduplicated');
foreach ([
    ['items' => [$row]],
    ['items' => [$row], 'page' => []],
    ['items' => [$row], 'page' => null],
    ['items' => [$row], 'page' => ['has_more' => true]],
    ['items' => [$row], 'page' => ['has_more' => true, 'next_cursor' => 'repeat']],
    ['items' => [[]], 'page' => ['has_more' => false]],
    ['items' => [$row], 'page' => ['has_more' => 'yes']],
] as $malformed) {
    $bad = silo_fetch_sessions($config, ['transport' => static fn() => fixture_response($malformed)]);
    check(!$bad['ok'] && $bad['items'] === [], 'Malformed/looping page must fail without partial rows');
}
$badJson = silo_fetch_sessions($config, ['transport' => static fn() => ['ok' => true, 'status' => 200, 'body' => '<html>Sign in</html>']]);
check(!$badJson['ok'] && $badJson['error']['code'] === 'invalid_response', 'Reject HTML/login body');
$call = 0;
$failedPage = silo_fetch_sessions($config, ['transport' => static function () use (&$call, $row) {
    if (++$call === 1) return fixture_response(['items' => [$row], 'page' => ['has_more' => true, 'next_cursor' => 'second']]);
    return ['ok' => true, 'status' => 403, 'body' => 'private upstream error'];
}]);
check(!$failedPage['ok'] && $failedPage['items'] === [] && !str_contains(json_encode($failedPage), 'private upstream'), 'Later page failure and error redaction');
$time = 0;
$late = silo_fetch_sessions($config, ['now' => static function () use (&$time) { return $time += 10; }, 'transport' => static fn() => fixture_response(['items' => [], 'page' => ['has_more' => false]])]);
check(!$late['ok'] && $late['error']['code'] === 'deadline', 'Aggregate deadline');
$empty = silo_fetch_sessions($config, ['transport' => static fn() => fixture_response(['items' => [], 'page' => ['has_more' => false]])]);
check($empty['ok'] && $empty['items'] === [], 'Successful zero is empty, not error');
$cap = silo_fetch_capabilities($config, ['transport' => static function ($url) { check(str_ends_with($url, '/api/v2/admin/sessions/capabilities'), 'Correct capability path'); return fixture_response(['effective_play_method' => false, 'stream_location' => true]); }]);
check(!$cap['supports']['effective_play_method'] && $cap['supports']['stream_location'], 'Capability booleans, not mere key presence');

$dir = sys_get_temp_dir() . '/silo-streams-' . bin2hex(random_bytes(6));
$path = $dir . '/config.json';
try {
    check(silo_write_config($config, $path)['ok'], 'Atomic config save');
    check(silo_load_config($path)['config']['api_key'] === $config['api_key'], 'Save/load preserves key');
    check((fileperms($path) & 0777) === 0600, 'Private config file');
    check((fileperms($dir) & 0777) === 0700, 'Private config directory');
    file_put_contents($dir . '/var.ini', "csrf_token=\"fixture_csrf\"\n");
    check(silo_csrf_token($dir . '/var.ini') === 'fixture_csrf', 'Read host CSRF token');
    check(silo_csrf_token($dir . '/missing.ini') === null, 'CSRF fails closed');
    file_put_contents($path, '{broken');
    check(!silo_load_config($path)['valid'], 'Corrupt saved config does not masquerade as empty');
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) unlink($file);
    if (is_dir($dir)) rmdir($dir);
}
echo "PHP behavior/security checks passed: $checks\n";
