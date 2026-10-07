<?php
// 保存期間を過ぎた記録を消す（2026-10-08 ユーザー決定: 受注に至らなかった問い合わせは、最後のご連絡から 1 年で削除）。
// Web からは開けない（api/.htaccess が _ から始まるファイルを拒否し、下でも Web からの実行を断る）。サーバーで実行する:
//   /usr/local/bin/php84 -q ~/public_html/madori.macmariya.com/api/_purge.php           消す件数を表示するだけ
//   /usr/local/bin/php84 -q ~/public_html/madori.macmariya.com/api/_purge.php --apply   消す
// 「最後のご連絡」は inquiries.updated_at。返信・見積などで状態を変えたら updated_at も更新する（docs/schema.md）。
// 受注になった問い合わせ（orders から参照されているもの）と受注の記録は消さない。
declare(strict_types=1);
if (isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}
require __DIR__ . '/_lib.php';

$apply = in_array('--apply', $argv ?? [], true);
$pdo = db(load_config());
$cutoff = date('c', strtotime('-1 year'));

$target = "FROM inquiries WHERE updated_at < :cutoff AND status <> 'won'
           AND id NOT IN (SELECT inquiry_id FROM orders WHERE inquiry_id IS NOT NULL)";
$count = static function (string $sql) use ($pdo, $cutoff): int {
    $st = $pdo->prepare($sql);
    $st->execute([':cutoff' => $cutoff]);
    return (int)$st->fetchColumn();
};
$n = [
    'inquiries' => $count("SELECT COUNT(*) {$target}"),
    'submission_log' => $count('SELECT COUNT(*) FROM submission_log WHERE created_at < :cutoff'),
];
echo ($apply ? '削除する' : '削除の対象（--apply で消す）'), "  基準日時 {$cutoff}\n";
foreach ($n as $k => $v) {
    echo "  {$k}: {$v}\n";
}
if (!$apply) {
    exit;
}

$pdo->exec('BEGIN IMMEDIATE');
$ids = "SELECT id {$target}";
foreach ([
    "DELETE FROM status_events WHERE entity_type = 'inquiry' AND entity_id IN ({$ids})",
    "DELETE FROM mail_log WHERE inquiry_id IN ({$ids})",
    "DELETE {$target}",
    'DELETE FROM submission_log WHERE created_at < :cutoff',
] as $sql) {
    $st = $pdo->prepare($sql);
    $st->execute([':cutoff' => $cutoff]);
}
// 問い合わせも受注も残っていない顧客を消す
$deleted = $pdo->exec('DELETE FROM customers WHERE id NOT IN (SELECT customer_id FROM inquiries)
                       AND id NOT IN (SELECT customer_id FROM orders WHERE customer_id IS NOT NULL)');
$pdo->exec('COMMIT');
echo "  customers: {$deleted}\n完了\n";
