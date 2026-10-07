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
