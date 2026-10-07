<?php
// ドキュメントルートの外（PRIVATE_DIR）に config.php として置く。リポジトリには入れない。
// 例: PRIVATE_DIR=/home/{ユーザー}/madori-private → /home/{ユーザー}/madori-private/config.php
return [
    // SQLite のファイル。PRIVATE_DIR の中に置く（ドキュメントルートの中に置かない）
    'db_path' => __DIR__ . '/madori.sqlite',

    // 通知の宛先と差出人。差出人のドメインは SPF でバリューサーバーを許可している macmariya.com にする
    'notify_to' => 'info@macmariya.com',
    'mail_from' => 'info@macmariya.com',
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
];
