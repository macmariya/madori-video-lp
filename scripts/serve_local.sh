#!/usr/bin/env bash
# ローカル検証: ビルドして、PHP の組み込みサーバーで dist/ を配信する（静的ファイルとフォームの PHP を同じプロセスで動かす）。
# 設定は .local/private/config.php（無ければ作る）。メールは送らず .local/private/mail/ に .eml として書く。
# 組み込みサーバーは .htaccess を読まないので、アクセス拒否の確認は公開後に curl で行う。
#   TURNSTILE_SECRET=2x0000000000000000000000000000000AA bash scripts/serve_local.sh   # 常に不合格の鍵で試す
set -euo pipefail
cd "$(dirname "$0")/.."
PORT="${PORT:-8080}"
PRIV="$PWD/.local/private"
mkdir -p "$PRIV"
SECRET="${TURNSTILE_SECRET:-1x0000000000000000000000000000000AA}"   # Cloudflare の「常に合格」テスト用秘密鍵
cat > "$PRIV/config.php" <<PHP
<?php
return [
    'db_path' => __DIR__ . '/madori.sqlite',
    'notify_to' => 'info@macmariya.com',
    'mail_from' => 'info@macmariya.com',
    'mail_from_name' => 'マクマリ',
    'mail_mode' => 'file',
    'turnstile_secret' => '$SECRET',
    'ip_salt' => 'local-test-salt',
    'allowed_origins' => ['http://127.0.0.1:$PORT', 'http://localhost:$PORT'],
    'check_token' => 'local',
];
PHP
npm run build >/dev/null
printf "<?php\nreturn %s;\n" "'$PRIV'" > dist/api/_private_path.php
exec php -S "127.0.0.1:$PORT" -t dist
