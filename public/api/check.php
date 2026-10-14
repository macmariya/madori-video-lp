<?php
// 公開直後の動作確認。設定の check_token と一致する ?token= を付けたときだけ答える。確かめたら設定の check_token を空にする
declare(strict_types=1);
require __DIR__ . '/_lib.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
try {
    $config = load_config();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'config']);
    exit;
}
$token = (string)($config['check_token'] ?? '');
if ($token === '' || !hash_equals($token, (string)($_GET['token'] ?? ''))) {
    http_response_code(404);
    exit;
}
$out = [
    'php' => PHP_VERSION,
    'pdo_sqlite' => extension_loaded('pdo_sqlite'),
    'mbstring' => extension_loaded('mbstring'),
    'mail' => function_exists('mail'),
    'allow_url_fopen' => (bool)ini_get('allow_url_fopen'),
    'curl' => function_exists('curl_init'),
    'private_dir_writable' => is_writable($config['private_dir']),
    'db_under_docroot' => strpos(realpath(dirname($config['db_path'])) ?: '', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '//') === 0,
    // 送信元 IP の取り方。cloudflare は PHP が Cloudflare からの通信と確かめて CF-Connecting-IP を使った。server_resolved は
    // サーバー側がすでに書き換えていた（バリューサーバーはどこから来た通信でも書き換えるので、偽装は origin_auth で防ぐ）。
    // direct は Cloudflare を通っていないか前段の中継の IP が入っている。unknown_proxy はサーバー内の中継が直前の相手を渡しておらず判断できない
    'client_ip_source' => client_ip_info()[1],
    // Cloudflare の Transform Rule が付ける合言葉。ok なら Cloudflare を通った通信だと確かめられている。
    // off は設定の origin_secret が空、missing はヘッダーが無い（Cloudflare を通っていないか、ルールが無い）、mismatch は値が違う
    'origin_auth' => origin_auth_status($config),
    'origin_auth_header_present' => isset($_SERVER['HTTP_X_ORIGIN_AUTH']),
    // 判断の材料（開いた人自身の IP が出る。合言葉を知る人しか見られない）
    'ip_headers' => [
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
        'cf_connecting_ip' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        'x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        'x_real_ip' => $_SERVER['HTTP_X_REAL_IP'] ?? null,
    ],
    'timezone' => date_default_timezone_get(),
    'now' => now_iso(),
];
try {
    $pdo = db($config);
    $out['schema_version'] = (int)$pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
    $out['inquiries'] = (int)$pdo->query('SELECT COUNT(*) FROM inquiries')->fetchColumn();
} catch (Throwable $e) {
    $out['db_error'] = $e->getMessage();
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
