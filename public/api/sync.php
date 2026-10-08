<?php
// 受注管理アプリ（自宅の NAS。別リポジトリ madori-orders）との同期口。設定の sync_token と一致するヘッダー
// X-Sync-Token（または Authorization: Bearer）が付いたときだけ答える。トークンが空・不一致なら 404。
//   GET  ?since=<ISO 8601>  その日時以降に更新された問い合わせ（顧客の写しを含む）と、残っている全受付番号、選択肢の表示名を返す。
//                           NAS は ids に無い問い合わせを消す（_purge.php の 1 年の削除を NAS の写しにも効かせるため）
//   POST {"public_id","to","at","quote_amount"?,"lost_reason"?,"note"?}
//                           問い合わせの状態を変える。正本は NAS で、ここは写し（_purge.php が status と updated_at を見るため）。
//                           同じ状態への変更は何もせず ok を返す（NAS の送り直しで二重にしない）
declare(strict_types=1);
require __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');

/** @param mixed $body */
function sync_out(int $status, $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 状態の流れ（docs/schema.md）。見送り（lost）は再開できる
const INQUIRY_TRANSITIONS = [
    'new' => ['replied', 'quoted', 'won', 'lost', 'spam'],
    'replied' => ['quoted', 'won', 'lost'],
    'quoted' => ['quoted', 'won', 'lost'],
    'won' => [],
    'lost' => ['replied', 'quoted', 'won'],
    'spam' => ['new'],
];

try {
    $config = load_config();
} catch (Throwable $e) {
    sync_out(500, ['ok' => false, 'error' => 'config']);
}
$token = (string)($config['sync_token'] ?? '');
$given = (string)($_SERVER['HTTP_X_SYNC_TOKEN'] ?? '');
if ($given === '' && preg_match('/^Bearer\s+(\S+)$/', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m)) {
    $given = $m[1];
}
if ($token === '' || strlen($token) < 32 || !hash_equals($token, $given)) {
    http_response_code(404);
    exit;
}

try {
    $pdo = db($config);
} catch (Throwable $e) {
    error_log('[madori sync] db: ' . $e->getMessage());
    sync_out(500, ['ok' => false, 'error' => 'db']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    $since = (string)($_GET['since'] ?? '');
    if ($since !== '' && strtotime($since) === false) {
        sync_out(400, ['ok' => false, 'error' => 'since']);
    }
    // ip_hash・user_agent は渡さない（迷惑送信の把握にだけ使う値）
    $sql = 'SELECT i.id, i.public_id, i.status, i.channel, i.company_name, i.contact_name, i.email, i.phone, i.prefecture,
                   i.customer_type, i.property_type, i.layout, i.photo_count_choice, i.photo_count, i.floorplan_type,
                   i.photo_source, i.usage, i.wants_object_removal, i.desired_deadline, i.expected_volume,
                   i.contact_preference, i.message, i.estimate_amount, i.price_version, i.quote_amount, i.replied_at,
                   i.quoted_at, i.closed_at, i.lost_reason, i.consent_at, i.form_version, i.source_page, i.referrer,
                   i.utm_source, i.utm_medium, i.utm_campaign, i.created_at, i.updated_at,
                   c.email AS customer_email, c.note AS customer_note
            FROM inquiries i JOIN customers c ON c.id = i.customer_id';
    $params = [];
    if ($since !== '') {
        // 日時は秒までなので、同じ秒の更新を落とさないよう「以降」で返す（NAS は受け取った行を上書きするだけ）
        $sql .= ' WHERE i.updated_at >= ?';
        $params[] = date('c', (int)strtotime($since));
    }
    $sql .= ' ORDER BY i.updated_at, i.id';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['usage'] = json_decode((string)$r['usage'], true) ?: [];
        foreach (['id', 'photo_count', 'wants_object_removal', 'estimate_amount', 'quote_amount'] as $k) {
            $r[$k] = $r[$k] === null ? null : (int)$r[$k];
        }
    }
    unset($r);
    $ids = $pdo->query('SELECT public_id FROM inquiries ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    // 選択肢の表示名（form.json の options）も渡す。NAS の画面が英字の値を日本語で出すため
    sync_out(200, ['ok' => true, 'server_time' => now_iso(), 'inquiries' => $rows, 'ids' => $ids, 'options' => load_form()['options']]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    sync_out(405, ['ok' => false, 'error' => 'method']);
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) {
    sync_out(400, ['ok' => false, 'error' => 'json']);
}
$publicId = (string)($in['public_id'] ?? '');
$to = (string)($in['to'] ?? '');
$at = (string)($in['at'] ?? '');
if (!preg_match('/^INQ-\d{8}-\d{3,}$/', $publicId) || !array_key_exists($to, INQUIRY_TRANSITIONS) || strtotime($at) === false) {
    sync_out(400, ['ok' => false, 'error' => 'input']);
}
$at = date('c', (int)strtotime($at));
$quote = isset($in['quote_amount']) && $in['quote_amount'] !== null ? (int)$in['quote_amount'] : null;
$lostReason = isset($in['lost_reason']) ? mb_substr((string)$in['lost_reason'], 0, 500) : null;
$note = isset($in['note']) ? mb_substr((string)$in['note'], 0, 500) : null;

try {
    $pdo->exec('BEGIN IMMEDIATE');
    $st = $pdo->prepare('SELECT id, status, quote_amount FROM inquiries WHERE public_id = ?');
    $st->execute([$publicId]);
    $row = $st->fetch();
    if ($row === false) {
        $pdo->exec('ROLLBACK');
        sync_out(404, ['ok' => false, 'error' => 'not_found']);
    }
    $from = (string)$row['status'];
    $sameQuote = $quote === null || (int)$row['quote_amount'] === $quote;
    if ($from === $to && ($to !== 'quoted' || $sameQuote)) {
        $pdo->exec('ROLLBACK');
        sync_out(200, ['ok' => true, 'changed' => false, 'status' => $from]);
    }
    if (!in_array($to, INQUIRY_TRANSITIONS[$from], true)) {
        $pdo->exec('ROLLBACK');
        sync_out(409, ['ok' => false, 'error' => 'transition', 'status' => $from]);
    }
    $set = ['status = :to', 'updated_at = :now'];
    $params = [':to' => $to, ':now' => now_iso(), ':id' => (int)$row['id']];
    if ($to === 'replied') {
        $set[] = 'replied_at = COALESCE(replied_at, :at)';
        $params[':at'] = $at;
    } elseif ($to === 'quoted') {
        $set[] = 'quoted_at = :at';
        $params[':at'] = $at;
        if ($quote !== null) {
            $set[] = 'quote_amount = :quote';
            $params[':quote'] = $quote;
        }
    } elseif ($to === 'won' || $to === 'lost') {
        $set[] = 'closed_at = :at';
        $params[':at'] = $at;
        if ($to === 'lost') {
            $set[] = 'lost_reason = :reason';
            $params[':reason'] = $lostReason;
        }
    }
    // 見送りからの再開: 理由を消し、返信・見積に戻すなら締めた日時も消す
    if ($from === 'lost') {
        $set[] = 'lost_reason = NULL';
        if ($to !== 'won') {
            $set[] = 'closed_at = NULL';
        }
    }
    $st = $pdo->prepare('UPDATE inquiries SET ' . implode(', ', $set) . ' WHERE id = :id');
    $st->execute($params);
    $st = $pdo->prepare('INSERT INTO status_events (entity_type, entity_id, from_status, to_status, note, actor, created_at)
                         VALUES (\'inquiry\', ?, ?, ?, ?, \'nas\', ?)');
    $st->execute([(int)$row['id'], $from, $to, $note, $at]);
    $pdo->exec('COMMIT');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->exec('ROLLBACK');
    }
    error_log('[madori sync] post: ' . $e->getMessage());
    sync_out(500, ['ok' => false, 'error' => 'save']);
}
sync_out(200, ['ok' => true, 'changed' => true, 'status' => $to]);
