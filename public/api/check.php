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
    // 送信元 IP の取り方。Cloudflare 経由で開いて cloudflare か server_resolved になれば、IP の偽装を防げている
    // （direct は Cloudflare を通っていないか、前段の中継の IP が入っている。unknown_proxy はサーバー内の中継が直前の相手を渡しておらず判断できない）
    'client_ip_source' => client_ip_info()[1],
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
