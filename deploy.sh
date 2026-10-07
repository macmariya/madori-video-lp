#!/usr/bin/env bash
# dist/ をバリューサーバーのサブドメインのドキュメントルートへ rsync で上げる。
#   ./deploy.sh            ビルドして、差分を表示（dry-run）してから本番に反映する
#   ./deploy.sh --dry-run  ビルドして差分を表示するだけ
# 接続情報は .env.deploy（gitignore）に書く。書式は .env.deploy.example。
# フォームの設定（config.php）はドキュメントルートの外の PRIVATE_DIR に置く。初回は scripts/setup_private.sh で作る。
set -euo pipefail
cd "$(dirname "$0")"

[ -f .env.deploy ] || { echo "Error: .env.deploy がありません（.env.deploy.example をコピーして埋める）" >&2; exit 1; }
# shellcheck disable=SC1091
source .env.deploy
: "${DEPLOY_HOST:?}" "${DEPLOY_PATH:?}" "${PRIVATE_DIR:?}" "${PUBLIC_TURNSTILE_SITEKEY:?}"
DEPLOY_PORT="${DEPLOY_PORT:-22}"
SSH=(ssh -p "$DEPLOY_PORT")
[ -n "${DEPLOY_KEY:-}" ] && SSH+=(-i "$DEPLOY_KEY")
REMOTE="${DEPLOY_USER:+$DEPLOY_USER@}$DEPLOY_HOST"

# --delete で WordPress を消さないための確認。ドキュメントルートの末尾は必ずサブドメイン用のフォルダ名にする
case "$DEPLOY_PATH" in
  */madori.macmariya.com|*/madori.macmariya.com/|*/madori|*/madori/) ;;
  *) echo "Error: DEPLOY_PATH の末尾がサブドメイン用のフォルダではありません: $DEPLOY_PATH" >&2; exit 1 ;;
esac
# DB と設定がドキュメントルートの中にあると、Web から読めるうえ --delete で消える
case "${PRIVATE_DIR%/}/" in
  "${DEPLOY_PATH%/}/"*|*/public_html/*) echo "Error: PRIVATE_DIR がドキュメントルートの中にあります: $PRIVATE_DIR" >&2; exit 1 ;;
esac
case "$PUBLIC_TURNSTILE_SITEKEY" in
  1x0000*|2x0000*|3x0000*) echo "Error: PUBLIC_TURNSTILE_SITEKEY がテスト用のキーです" >&2; exit 1 ;;
esac
if "${SSH[@]}" "$REMOTE" "test -e '$DEPLOY_PATH/wp-config.php' -o -d '$DEPLOY_PATH/wp-content' -o -d '$DEPLOY_PATH/wp-admin'"; then
  echo "Error: $DEPLOY_PATH に WordPress のファイルがあります。上げ先を確かめてください" >&2
  exit 1
fi
if ! "${SSH[@]}" "$REMOTE" "test -f '$PRIVATE_DIR/config.php'"; then
  echo "Error: $PRIVATE_DIR/config.php がありません。先に bash scripts/setup_private.sh を実行する" >&2
  exit 1
fi

PUBLIC_TURNSTILE_SITEKEY="$PUBLIC_TURNSTILE_SITEKEY" npm run build
# 設定の場所を PHP に教えるファイル（ビルドの後・rsync の前に書く。ビルドで dist/ は作り直される）
printf "<?php\nreturn '%s';\n" "${PRIVATE_DIR%/}" > dist/api/_private_path.php
grep -q "$PUBLIC_TURNSTILE_SITEKEY" dist/contact/index.html || { echo "Error: フォームにサイトキーが入っていません" >&2; exit 1; }

RSYNC=(rsync -rlvz --checksum --delete --exclude '.DS_Store' -e "${SSH[*]}" dist/ "$REMOTE:$DEPLOY_PATH/")
echo "== 差分（dry-run）"
"${RSYNC[@]}" --dry-run
[ "${1:-}" = "--dry-run" ] && exit 0

read -r -p "この内容で反映しますか？ [y/N] " ans
[ "$ans" = "y" ] || { echo "中止しました"; exit 1; }
"${RSYNC[@]}"

echo "== 反映の確認"
curl -sI "https://madori.macmariya.com/" | grep -i -E '^(HTTP|server|cf-cache-status|last-modified)'
for p in api/_lib.php api/_private_path.php api/schema.sql; do
  printf "%s %s（403 か 404 なら正しい）\n" "$(curl -s -o /dev/null -w '%{http_code}' "https://madori.macmariya.com/$p")" "$p"
done
