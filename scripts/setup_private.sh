#!/usr/bin/env bash
# 初回だけ: サーバーのドキュメントルートの外に PRIVATE_DIR を作り、フォームの設定 config.php を置く。
# 塩と check.php の合言葉はここで作る。Turnstile の秘密鍵は .env.deploy の TURNSTILE_SECRET から取る（リポジトリには入れない）。
# すでに config.php があれば上書きしない（塩を変えると過去の IP ハッシュと突き合わせられなくなるため）。
set -euo pipefail
cd "$(dirname "$0")/.."
# shellcheck disable=SC1091
source .env.deploy
: "${DEPLOY_HOST:?}" "${PRIVATE_DIR:?}" "${TURNSTILE_SECRET:?}"
SSH=(ssh -p "${DEPLOY_PORT:-22}")
[ -n "${DEPLOY_KEY:-}" ] && SSH+=(-i "$DEPLOY_KEY")
REMOTE="${DEPLOY_USER:+$DEPLOY_USER@}$DEPLOY_HOST"

if "${SSH[@]}" "$REMOTE" "test -f '$PRIVATE_DIR/config.php'"; then
  echo "$PRIVATE_DIR/config.php は既にあります。変えるときはサーバー上で直接直す"; exit 0
fi
SALT=$(openssl rand -hex 16)
TOKEN=$(openssl rand -hex 12)
"${SSH[@]}" "$REMOTE" "umask 077; mkdir -p '$PRIVATE_DIR' && cat > '$PRIVATE_DIR/config.php'" <<PHP
<?php
// scripts/setup_private.sh が $(date +%Y-%m-%d) に作成。書式は server/config.sample.php
return [
    'db_path' => __DIR__ . '/madori.sqlite',
    'notify_to' => '${NOTIFY_TO:-info@macmariya.com}',
    'mail_from' => 'info@macmariya.com',
    'mail_from_name' => 'マクマリ',
    'mail_mode' => 'mail',
    'turnstile_secret' => '${TURNSTILE_SECRET}',
    'ip_salt' => '${SALT}',
    'allowed_origins' => ['https://madori.macmariya.com'],
    'check_token' => '${TOKEN}',
];
PHP
"${SSH[@]}" "$REMOTE" "ls -la '$PRIVATE_DIR'"
echo "作成しました。公開後に https://madori.macmariya.com/api/check.php?token=${TOKEN} で動作を確かめ、確かめたら config.php の check_token を空にする"
