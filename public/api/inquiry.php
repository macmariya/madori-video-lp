<?php
// 問い合わせフォーム（/contact/）の送信先。保存して通知・自動返信を送り、/contact/thanks/ へ 303 で移す。
declare(strict_types=1);
require __DIR__ . '/_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    error_page(405, '送信できませんでした', ['お問い合わせはフォームから送信してください。']);
}

try {
    $config = load_config();
    $form = load_form();
    $pdo = db($config);
} catch (Throwable $e) {
    error_log('[madori inquiry] init: ' . $e->getMessage());
    error_page(500, '送信できませんでした', ['ただいま受け付けできません。お手数ですが info@macmariya.com までメールでお送りください。']);
}

$ipHash = ip_hash($config);

// 他のサイトからの送信を断る（Origin が付いていて許可外のとき）
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !in_array($origin, $config['allowed_origins'], true)) {
    log_submission($pdo, $ipHash, 'invalid', 'origin ' . $origin);
    error_page(403, '送信できませんでした', ['このページからは送信できません。']);
}

$in = static fn(string $k): string => trim(str_replace("\r\n", "\n", (string)($_POST[$k] ?? '')));

// 迷惑送信の対策 1: 人には見えない欄（website）に入力があれば、受け付けたふりをして保存しない
if ($in('website') !== '') {
    log_submission($pdo, $ipHash, 'honeypot');
    header('Location: /contact/thanks/', true, 303);
    exit;
}
// 2: 画面を開いてから 3 秒未満の送信（JS が入れる started_at。JS が無ければ確かめない）
$started = $in('started_at');
if ($started !== '' && ctype_digit($started) && (microtime(true) * 1000 - (int)$started) < 3000) {
    log_submission($pdo, $ipHash, 'too_fast');
    error_page(400, '送信できませんでした', ['入力の確認に時間がかかっています。少し待ってからもう一度送信してください。']);
}
// 3: 同じ送信元から 1 時間に 5 回まで（受け付けた送信と迷惑送信の疑いを数える。入力の誤りは数えない）
if ($ipHash !== null) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM submission_log WHERE ip_hash = ? AND created_at >= ?
                         AND result IN ('ok', 'honeypot', 'too_fast', 'turnstile')");
    $st->execute([$ipHash, date('c', time() - 3600)]);
    if ((int)$st->fetchColumn() >= 5) {
        log_submission($pdo, $ipHash, 'rate_limited');
        error_page(429, '送信できませんでした', ['短い時間に何度も送信されています。時間をおいてから、もう一度お試しください。']);
    }
}
// 4: Cloudflare Turnstile
if (!verify_turnstile($config, $in('cf-turnstile-response'))) {
    log_submission($pdo, $ipHash, 'turnstile');
    error_page(400, '送信できませんでした', ['送信の確認（ボット対策）が完了していません。入力画面に戻り、確認の表示が終わってから送信してください。']);
}

// 入力の確認
$errors = [];
$lim = $form['limits'];
$text = static function (string $k, string $label, bool $required) use ($in, $lim, &$errors): ?string {
    $v = $in($k);
    if ($v === '') {
        if ($required) {
            $errors[] = "{$label}を入力してください。";
        }
        return null;
    }
    if (isset($lim[$k]) && mb_strlen($v) > $lim[$k]) {
        $errors[] = "{$label}は{$lim[$k]}文字以内で入力してください。";
    }
    return $v;
};
$choice = static function (string $k, string $label, bool $required) use ($in, $form, &$errors): ?string {
    $v = $in($k);
    if ($v === '') {
        if ($required) {
            $errors[] = "{$label}を選んでください。";
        }
        return null;
    }
    if (!in_array($v, option_values($form, $k), true)) {
        $errors[] = "{$label}の選択が正しくありません。";
    }
    return $v;
};

$companyName = $text('company_name', '会社名・屋号', true);
$customerType = $choice('customer_type', '業種', true);
$contactName = $text('contact_name', 'ご担当者名', true);
$email = $text('email', 'メールアドレス', true);
if ($email !== null && (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email))) {
    $errors[] = 'メールアドレスの形式が正しくありません。';
}
$phone = $text('phone', '電話番号', false);
if ($phone !== null && !preg_match('/^[0-9+\-() ]{10,20}$/', $phone)) {
    $errors[] = '電話番号は数字とハイフンで入力してください。';
}
$prefecture = $choice('prefecture', '所在地', false);
$propertyType = $choice('property_type', '物件の種類', true);
$layout = $text('layout', '間取り', false);
$photoChoice = $choice('photo_count', '室内写真の枚数', true);
$floorplanType = $choice('floorplan_type', '間取り図', true);
$photoSource = $choice('photo_source', '写真の状態', false);
$expectedVolume = $choice('expected_volume', '今後のご依頼の見込み', false);
$contactPref = $choice('contact_preference', 'ご希望の連絡方法', false) ?? 'email';
if ($contactPref === 'phone' && $phone === null) {
    $errors[] = '電話でのご連絡をご希望の場合は、電話番号を入力してください。';
}
$usage = array_values(array_intersect(option_values($form, 'usage'), (array)($_POST['usage'] ?? [])));
$wantsRemoval = isset($_POST['wants_object_removal']) ? 1 : 0;
$deadline = $in('desired_deadline');
if ($deadline !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) {
    $errors[] = 'ご希望の納期の形式が正しくありません。';
}
$message = $text('message', 'ご質問・ご要望', false);
if (!isset($_POST['consent'])) {
    $errors[] = '個人情報の取り扱いに同意のうえ送信してください。';
}

if ($errors) {
    log_submission($pdo, $ipHash, 'invalid', implode(' / ', $errors));
    error_page(400, '入力内容をご確認ください', $errors);
}

$photoCount = ctype_digit((string)$photoChoice) ? (int)$photoChoice : null;
$pricing = $form['pricing'];
$estimate = estimate($pricing, $photoCount);
$emailNorm = mb_strtolower((string)$email);
$now = now_iso();

$raw = [];
foreach ($_POST as $k => $v) {
    if (in_array($k, ['cf-turnstile-response', 'website'], true)) {
        continue;
    }
    $raw[$k] = $v;
}

try {
    $pdo->exec('BEGIN IMMEDIATE');

    // 顧客はメールアドレスで 1 件にまとめ、最新の入力で上書きする
    $st = $pdo->prepare('SELECT id FROM customers WHERE email = ?');
    $st->execute([$emailNorm]);
    $customerId = $st->fetchColumn();
    if ($customerId === false) {
        $st = $pdo->prepare('INSERT INTO customers (email, company_name, contact_name, phone, prefecture, customer_type, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$emailNorm, $companyName, $contactName, $phone, $prefecture, $customerType, $now, $now]);
        $customerId = (int)$pdo->lastInsertId();
    } else {
        $st = $pdo->prepare('UPDATE customers SET company_name = ?, contact_name = ?, phone = COALESCE(?, phone),
                             prefecture = COALESCE(?, prefecture), customer_type = ?, updated_at = ? WHERE id = ?');
        $st->execute([$companyName, $contactName, $phone, $prefecture, $customerType, $now, $customerId]);
        $customerId = (int)$customerId;
    }

    $publicId = next_public_id($pdo, 'INQ', 'inquiries');
    $st = $pdo->prepare('INSERT INTO inquiries (
        public_id, customer_id, status, channel,
        company_name, contact_name, email, phone, prefecture, customer_type,
        property_type, layout, photo_count_choice, photo_count, floorplan_type, photo_source, usage,
        wants_object_removal, desired_deadline, expected_volume, contact_preference, message,
        estimate_amount, price_version, consent_at, form_version, raw_json,
        source_page, referrer, utm_source, utm_medium, utm_campaign, user_agent, ip_hash, created_at, updated_at
    ) VALUES (?, ?, \'new\', \'lp\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $cut = static fn(string $s, int $n): ?string => $s === '' ? null : mb_substr($s, 0, $n);
    $st->execute([
        $publicId, $customerId,
        $companyName, $contactName, $emailNorm, $phone, $prefecture, $customerType,
        $propertyType, $layout, $photoChoice, $photoCount, $floorplanType, $photoSource,
        json_encode($usage, JSON_UNESCAPED_UNICODE),
        $wantsRemoval, $deadline === '' ? null : $deadline, $expectedVolume, $contactPref, $message,
        $estimate, $pricing['version'], $now, $form['form_version'],
        json_encode($raw, JSON_UNESCAPED_UNICODE),
        $cut($in('source_page'), 300), $cut($in('referrer'), 500),
        $cut($in('utm_source'), 100), $cut($in('utm_medium'), 100), $cut($in('utm_campaign'), 100),
        $cut((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 300), $ipHash, $now, $now,
    ]);
    $inquiryId = (int)$pdo->lastInsertId();

    $st = $pdo->prepare('INSERT INTO status_events (entity_type, entity_id, from_status, to_status, note, actor, created_at)
                         VALUES (\'inquiry\', ?, NULL, \'new\', NULL, \'form\', ?)');
    $st->execute([$inquiryId, $now]);
    log_submission($pdo, $ipHash, 'ok', $publicId);
    $pdo->exec('COMMIT');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->exec('ROLLBACK');
    }
    error_log('[madori inquiry] save: ' . $e->getMessage());
    try {
        log_submission($pdo, $ipHash, 'error', mb_substr($e->getMessage(), 0, 300));
    } catch (Throwable $ignored) {
    }
    error_page(500, '送信できませんでした', ['保存中に問題が起きました。お手数ですが info@macmariya.com までメールでお送りください。']);
}

// メール本文（通知・自動返信で共通の「内容の控え」）
$yen = static fn(?int $n): string => $n === null ? '写真の枚数を伺ってお見積りします' : number_format($n) . '円';
$usageLabels = implode('、', array_map(fn($u) => option_label($form, 'usage', $u), $usage));
$lines = [
    '受付番号：' . $publicId,
    '会社名・屋号：' . $companyName,
    '業種：' . option_label($form, 'customer_type', $customerType),
    'ご担当者名：' . $contactName,
    'メールアドレス：' . $emailNorm,
    '電話番号：' . ($phone ?? '（未入力）'),
    '所在地：' . ($prefecture ?? '（未選択）'),
    'ご希望の連絡方法：' . option_label($form, 'contact_preference', $contactPref),
    '',
    '物件の種類：' . option_label($form, 'property_type', $propertyType),
    '間取り：' . ($layout ?? '（未入力）'),
    '室内写真の枚数：' . option_label($form, 'photo_count', $photoChoice),
    '間取り図：' . option_label($form, 'floorplan_type', $floorplanType),
    '写真の状態：' . ($photoSource === null ? '（未選択）' : option_label($form, 'photo_source', $photoSource)),
    '使い道：' . ($usageLabels === '' ? '（未選択）' : $usageLabels),
    '写り込んだ物の消去の相談：' . ($wantsRemoval ? 'あり' : 'なし'),
    'ご希望の納期：' . ($deadline === '' ? '（未入力）' : $deadline),
    '今後のご依頼の見込み：' . ($expectedVolume === null ? '（未選択）' : option_label($form, 'expected_volume', $expectedVolume)),
    '',
    'ご質問・ご要望：',
    $message ?? '（なし）',
    '',
    '料金の目安：' . $yen($estimate),
];
$summary = implode("\n", $lines);

$notifyBody = "LP のフォームからお問い合わせがありました。\n\n{$summary}\n\n"
    . "送信日時：{$now}\n参照元：" . ($in('referrer') ?: '（なし）') . "\n"
    . 'UTM：' . implode(' / ', array_filter([$in('utm_source'), $in('utm_medium'), $in('utm_campaign')])) . "\n\n"
    . "このメールに返信すると、お客さまのアドレスに届きます。\n";
$r = send_mail($config, $config['notify_to'], "【AI内覧動画】お問い合わせ {$publicId} {$companyName}", $notifyBody, $emailNorm);
log_mail($pdo, $inquiryId, 'notify', $config['notify_to'], $r);

$replyBody = "{$companyName}\n{$contactName} 様\n\n"
    . "AI内覧動画のお問い合わせをいただき、ありがとうございます。\n"
    . "次の内容で受け付けました。内容を確認のうえ、担当者から折り返しご連絡します。\n\n"
    . "--------------------------------\n{$summary}\n--------------------------------\n\n"
    . "料金の目安は、室内写真の枚数から出したものです。正式な金額は、写真と間取り図を拝見してからお見積りします。\n"
    . "このメールにお心当たりがない場合は、お手数ですがこのまま破棄してください。\n\n"
    . "マクマリ\n{$config['mail_from']}\nhttps://madori.macmariya.com/\n";
$r = send_mail($config, $emailNorm, "【マクマリ】お問い合わせを受け付けました（受付番号 {$publicId}）", $replyBody, $config['notify_to']);
log_mail($pdo, $inquiryId, 'auto_reply', $emailNorm, $r);

header('Cache-Control: no-store');
header('Location: /contact/thanks/?id=' . rawurlencode($publicId), true, 303);
