<?php
// ドキュメントルートの外（PRIVATE_DIR）に config.php として置く。リポジトリには入れない。
// 例: PRIVATE_DIR=/home/{ユーザー}/madori-private → /home/{ユーザー}/madori-private/config.php
return [
    // SQLite のファイル。PRIVATE_DIR の中に置く（ドキュメントルートの中に置かない）
    'db_path' => __DIR__ . '/madori.sqlite',

    // 通知の宛先と差出人。差出人は DKIM 署名のある送信専用アドレス（2026-10-08。バリューサーバーのドメインメールと
    // Cloudflare の default._domainkey.madori・madori の SPF）。お客さまへの返信先と署名は contact_email
    'notify_to' => 'info@macmariya.com',
    'mail_from' => 'noreply@madori.macmariya.com',
    'contact_email' => 'info@macmariya.com',
    'mail_from_name' => 'マクマリ',
    // mail: 送る / file: PRIVATE_DIR/mail/ に .eml として書く（ローカル検証用）
    'mail_mode' => 'mail',

    // Cloudflare Turnstile の秘密鍵。空にすると確かめない（ローカル検証用）
    'turnstile_secret' => '',

    // IP アドレスをハッシュにするときの塩。公開前にランダムな文字列にする（php -r 'echo bin2hex(random_bytes(16));'）
    'ip_salt' => 'CHANGE-ME',

    // フォームを置くサイト
    'allowed_origins' => ['https://madori.macmariya.com'],

    // api/check.php を開くための合言葉。確かめ終わったら空にする
    'check_token' => '',

    // 受注管理アプリ（NAS の madori-orders）が api/sync.php を呼ぶときの合言葉。32 文字以上（openssl rand -hex 32）。
    // 空にすると sync.php は 404 を返す。NAS 側の .env の SERVER_SYNC_TOKEN と同じ値にする
    'sync_token' => '',
];
