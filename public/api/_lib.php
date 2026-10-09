<?php
// 問い合わせ API の共通処理。直接は開けない（api/.htaccess で _ から始まるファイルを拒否）。
// PHP 7.4 以上で動くように書く（バリューサーバーは Web の PHP をドメインごとに選べ、既定は 7.4。8.0 以降の関数・構文は使わない）。
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');
mb_internal_encoding('UTF-8');

const SCHEMA_VERSION = 2;  // 2: id_sequences（2026-10-09）

/** ドキュメントルートの外に置いた設定を読む。場所は deploy.sh が書く _private_path.php が返す */
function load_config(): array
{
    $pathFile = __DIR__ . '/_private_path.php';
    if (!is_file($pathFile)) {
        throw new RuntimeException('_private_path.php がありません');
    }
    $dir = require $pathFile;
    $config = require rtrim((string)$dir, '/') . '/config.php';
    $config['private_dir'] = rtrim((string)$dir, '/');
    return $config;
}

function load_form(): array
{
    return json_decode((string)file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
}

function now_iso(): string
{
    return date('c');
}

function db(array $config): PDO
{
    $pdo = new PDO('sqlite:' . $config['db_path'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    migrate($pdo);
    return $pdo;
}

/** 初回はテーブルを作る。版が上がったら schema_migrations を見て足す */
function migrate(PDO $pdo): void
{
    $has = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'")->fetchColumn();
    $current = $has ? (int)$pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() : 0;
    if ($current >= SCHEMA_VERSION) {
        return;
    }
    $pdo->exec((string)file_get_contents(__DIR__ . '/schema.sql'));
    $st = $pdo->prepare('INSERT OR IGNORE INTO schema_migrations (version, applied_at) VALUES (?, ?)');
    $st->execute([SCHEMA_VERSION, now_iso()]);
}

/** Cloudflare 経由なので、送信元は CF-Connecting-IP を優先する（オリジンへ直接送られた場合は偽装できる） */
function client_ip(): string
{
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function ip_hash(array $config): ?string
{
    $ip = client_ip();
    return $ip === '' ? null : hash('sha256', $config['ip_salt'] . $ip);
}

/** 料金表（form.json の pricing）から概算を出す。範囲外は null（要見積） */
function estimate(array $pricing, ?int $photos): ?int
{
    if ($photos === null || $photos < 1 || $photos > $pricing['max_photos']) {
        return null;
    }
    $extra = max(0, $photos - $pricing['base_photos']);
    return $pricing['base_price'] + $extra * $pricing['per_extra_photo'];
}

function option_label(array $form, string $field, ?string $value): string
{
    foreach ($form['options'][$field] ?? [] as $o) {
        if ($o['value'] === $value) {
            return $o['label'];
        }
    }
    return (string)$value;
}

function option_values(array $form, string $field): array
{
    return array_column($form['options'][$field], 'value');
}

function log_submission(PDO $pdo, ?string $ipHash, string $result, ?string $detail = null): void
{
    $st = $pdo->prepare('INSERT INTO submission_log (ip_hash, result, detail, created_at) VALUES (?, ?, ?, ?)');
    $st->execute([$ipHash, $result, $detail, now_iso()]);
}

/** Turnstile のトークンを確かめる。設定に秘密鍵が無ければ確かめない（ローカル検証用） */
function verify_turnstile(array $config, string $token): bool
{
    $secret = (string)($config['turnstile_secret'] ?? '');
    if ($secret === '') {
        return true;
    }
    if ($token === '') {
        return false;
    }
    $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    $body = http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => client_ip()]);
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => 10,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
    } elseif (function_exists('curl_init')) {
        // 共有サーバーで URL の読み込み（allow_url_fopen）が切られている場合
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $res = curl_exec($ch);
    } else {
        error_log('[madori inquiry] turnstile: allow_url_fopen も curl も使えない');
        return false;
    }
    if ($res === false) {
        return false;
    }
    $json = json_decode($res, true);
    return is_array($json) && ($json['success'] ?? false) === true;
}

/**
 * 受付番号・受注番号を採番する（{PREFIX}-YYYYMMDD-NNN。NNN は日ごとに 001 から）。
 * 呼び出し側のトランザクション（BEGIN IMMEDIATE）の中で使う。
 * 日ごとの最後の番号を id_sequences に持ち、行を消しても番号を戻さない（2026-10-09。以前は
 * 「その日の行数＋1」で数えていたため、試験の行を消すたびに同じ番号を出し直していた）。
 * 表を作る前のデータに備え、カウンターが無い日はその日の既存の番号の最大値から続ける。
 */
function next_public_id(PDO $pdo, string $prefix, string $table): string
{
    $day = date('Ymd');
    $st = $pdo->prepare('SELECT last FROM id_sequences WHERE prefix = ? AND day = ?');
    $st->execute([$prefix, $day]);
    $last = $st->fetchColumn();
    if ($last === false) {
        $st = $pdo->prepare("SELECT MAX(CAST(substr(public_id, -3) AS INTEGER)) FROM {$table} WHERE public_id LIKE ?");
        $st->execute(["{$prefix}-{$day}-%"]);
        $last = (int)$st->fetchColumn();
    }
    $next = (int)$last + 1;
    $st = $pdo->prepare('INSERT OR REPLACE INTO id_sequences (prefix, day, last) VALUES (?, ?, ?)');
    $st->execute([$prefix, $day, $next]);
    return sprintf('%s-%s-%03d', $prefix, $day, $next);
}

/**
 * メールを送る。mail_mode が file ならドキュメントルート外の mail/ に .eml として書く（ローカル検証用）。
 * 封筒の差出人（-f）を from に合わせる。SPF が見るのはこちら。
 */
function send_mail(array $config, string $to, string $subject, string $body, ?string $replyTo = null): array
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) {
        return [false, 'invalid recipient'];
    }
    $from = $config['mail_from'];
    $headers = [
        'From: ' . mb_encode_mimeheader($config['mail_from_name'], 'UTF-8') . " <{$from}>",
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n]/', $replyTo)) {
        $headers[] = "Reply-To: {$replyTo}";
    }
    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8');
    $encodedBody = chunk_split(base64_encode($body));

    if (($config['mail_mode'] ?? 'mail') === 'file') {
        $dir = $config['private_dir'] . '/mail';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $eml = "To: {$to}\r\nSubject: {$encodedSubject}\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody;
        $name = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
        return [file_put_contents($name, $eml) !== false, null];
    }
    $ok = mail($to, $encodedSubject, $encodedBody, implode("\r\n", $headers), '-f' . $from);
    return [$ok, $ok ? null : 'mail() returned false'];
}

function log_mail(PDO $pdo, ?int $inquiryId, string $kind, string $to, array $result): void
{
    $st = $pdo->prepare('INSERT INTO mail_log (inquiry_id, kind, to_addr, ok, error, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $st->execute([$inquiryId, $kind, $to, $result[0] ? 1 : 0, $result[1], now_iso()]);
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** JS が無い・入力に誤りがあるときに返す簡単なページ。ブラウザの「戻る」で入力が残る */
function error_page(int $status, string $title, array $messages): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    $items = implode('', array_map(fn($m) => '<li>' . h($m) . '</li>', $messages));
    echo <<<HTML
<!doctype html>
<html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>{$title}｜AI内覧動画</title>
<style>body{font-family:'Hiragino Sans','Noto Sans JP',sans-serif;color:#1f2633;background:#f7f4ee;margin:0;padding:48px 16px;line-height:1.8}
main{max-width:640px;margin:0 auto;background:#fff;border-radius:12px;padding:32px}h1{color:#1c283a;font-size:22px;margin:0 0 16px}
li{margin:4px 0}a{display:inline-block;margin-top:20px;background:#e87828;color:#fff;padding:12px 24px;border-radius:999px;text-decoration:none;font-weight:700}</style>
</head><body><main><h1>{$title}</h1><ul>{$items}</ul>
<a href="javascript:history.back()">入力画面に戻る</a></main></body></html>
HTML;
    exit;
}
