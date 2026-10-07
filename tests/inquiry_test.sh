#!/usr/bin/env bash
# フォームの送信先（api/inquiry.php）の試験。scripts/serve_local.sh で起動したローカルサーバーに対して流す。
# DB（.local/private/madori.sqlite）は最初に消して作り直す。
set -uo pipefail
cd "$(dirname "$0")/.."
BASE="${BASE:-http://127.0.0.1:8080}"
DB=.local/private/madori.sqlite
/bin/rm -f "$DB" .local/private/mail/*.eml
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "ok   $1"; pass=$((pass+1)); else echo "NG   $1: 期待 [$3] 実際 [$2]"; fail=$((fail+1)); fi; }
q() { sqlite3 "$DB" "$1"; }
old=$(( $(date +%s) * 1000 - 10000 ))   # 10 秒前に開いた扱い
valid=(--data-urlencode company_name=試験工務店 --data-urlencode customer_type=builder --data-urlencode contact_name=試験花子
       --data-urlencode email=hanako@example.com --data-urlencode property_type=house --data-urlencode photo_count=15
       --data-urlencode floorplan_type=none --data-urlencode consent=1 --data-urlencode started_at=$old
       --data-urlencode cf-turnstile-response=XXXX.DUMMY.TOKEN)
post() { curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$BASE/api/inquiry.php" "$@"; }

check "GET は 405" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/inquiry.php")" "405"
r=$(post "${valid[@]}"); check "正常な送信は 303 で受付番号つきの完了ページへ" "$r" "303 $BASE/contact/thanks/?id=INQ-$(date +%Y%m%d)-001"
check "15 枚の概算は 17,000 円" "$(q "SELECT estimate_amount FROM inquiries WHERE id=1")" "17000"
r=$(post "${valid[@]}" --data-urlencode email=HANAKO@example.com --data-urlencode photo_count=over20)
check "同じメール（大文字違い）の 2 回目は 002" "$r" "303 $BASE/contact/thanks/?id=INQ-$(date +%Y%m%d)-002"
check "顧客は 1 件にまとまる" "$(q "SELECT COUNT(*) FROM customers")" "1"
check "21 枚以上は概算 NULL（要見積）" "$(q "SELECT IFNULL(estimate_amount,'NULL')||'/'||IFNULL(photo_count,'NULL') FROM inquiries WHERE id=2")" "NULL/NULL"
r=$(post "${valid[@]}" --data-urlencode website=http://spam.example); check "ハニーポットは完了ページへ（番号なし）" "$r" "303 $BASE/contact/thanks/"
check "ハニーポットは保存しない" "$(q "SELECT COUNT(*) FROM inquiries")" "2"
r=$(post "${valid[@]}" -H "Origin: https://evil.example"); check "他サイトからの送信は 403" "${r%% *}" "403"
now=$(( $(date +%s) * 1000 )); r=$(post "${valid[@]}" --data-urlencode started_at=$now); check "開いて 3 秒未満は 400" "${r%% *}" "400"
r=$(curl -s -X POST "$BASE/api/inquiry.php" --data-urlencode company_name=x --data-urlencode email=bad --data-urlencode started_at=$old --data-urlencode cf-turnstile-response=XXXX.DUMMY.TOKEN)
check "入力の誤りは理由を返す" "$(echo "$r" | grep -o -e 'メールアドレスの形式' -e '業種を選んで' -e '同意のうえ' | wc -l | tr -d ' ')" "3"
r=$(post "${valid[@]}"); check "入力の誤りは回数に数えない（4 回目の受け付けは通る）" "$r" "303 $BASE/contact/thanks/?id=INQ-$(date +%Y%m%d)-003"
r=$(post "${valid[@]}"); check "受け付け・迷惑送信の疑いが 1 時間に 5 回に達すると 429" "${r%% *}" "429"
check "記録の内訳" "$(q "SELECT group_concat(result, ',') FROM (SELECT result FROM submission_log ORDER BY id)")" "ok,ok,honeypot,invalid,too_fast,invalid,ok,rate_limited"
check "メールは 3 件 × 2 通" "$(ls .local/private/mail/*.eml | wc -l | tr -d ' ')" "6"
check "状態の履歴は問い合わせ 3 件ぶん" "$(q "SELECT COUNT(*) FROM status_events WHERE to_status='new'")" "3"
echo "pass=$pass fail=$fail"
[ "$fail" -eq 0 ]
