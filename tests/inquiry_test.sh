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
r=$(post "${valid[@]}" -H "Origin: https://evil.example" -H "CF-Connecting-IP: 192.0.2.10"); check "他サイトからの送信は 403" "${r%% *}" "403"
now=$(( $(date +%s) * 1000 )); r=$(post "${valid[@]}" --data-urlencode started_at=$now); check "開いて 3 秒未満は 400" "${r%% *}" "400"
r=$(curl -s -X POST "$BASE/api/inquiry.php" --data-urlencode company_name=x --data-urlencode email=bad --data-urlencode started_at=$old --data-urlencode cf-turnstile-response=XXXX.DUMMY.TOKEN)
check "入力の誤りは理由を返す" "$(echo "$r" | grep -o -e 'メールアドレスの形式' -e '業種を選んで' -e '同意のうえ' | wc -l | tr -d ' ')" "3"
# 行を消しても番号を出し直さない（002 を消してから送っても 003 になる）
q "DELETE FROM status_events WHERE entity_type='inquiry' AND entity_id=2; DELETE FROM mail_log WHERE inquiry_id=2; DELETE FROM inquiries WHERE id=2;"
r=$(post "${valid[@]}"); check "入力の誤りは回数に数えない・消した番号は出し直さない（003）" "$r" "303 $BASE/contact/thanks/?id=INQ-$(date +%Y%m%d)-003"
check "採番のカウンターは 3" "$(q "SELECT last FROM id_sequences WHERE prefix='INQ' AND day='$(date +%Y%m%d)'")" "3"
check "スキーマの版は 2" "$(q "SELECT MAX(version) FROM schema_migrations")" "2"
r=$(post "${valid[@]}"); check "受け付け・迷惑送信の疑いが 1 時間に 5 回に達すると 429" "${r%% *}" "429"
check "記録の内訳" "$(q "SELECT group_concat(result, ',') FROM (SELECT result FROM submission_log ORDER BY id)")" "ok,ok,honeypot,origin,too_fast,invalid,ok,rate_limited"
check "メールは 3 件 × 2 通（消した 1 件の .eml は残る）" "$(ls .local/private/mail/*.eml | wc -l | tr -d ' ')" "6"
check "状態の履歴は残っている問い合わせ 2 件ぶん" "$(q "SELECT COUNT(*) FROM status_events WHERE to_status='new'")" "2"

# 2026-10-14 のセキュリティの見直し
# 回数の制限は Turnstile より前の判定にも効き、断った記録は 1 時間に 1 行だけ
for i in 1 2 3; do post "${valid[@]}" >/dev/null; done
check "制限中は何度送っても rate_limited は 1 行" "$(q "SELECT COUNT(*) FROM submission_log WHERE result='rate_limited'")" "1"
long="https://evil.example/$(printf 'a%.0s' $(seq 1 2000))"
r=$(post -d x=1 -H "Origin: $long" -H "CF-Connecting-IP: 192.0.2.11"); check "長い Origin も 403" "${r%% *}" "403"
check "記録の detail は 300 文字で切る" "$(q "SELECT MAX(length(detail)) FROM submission_log")" "300"
codes=""; for i in 1 2 3 4 5 6; do codes="$codes$(post -d website=x -H "CF-Connecting-IP: 192.0.2.12" | cut -c1-3) "; done
check "ハニーポットも 1 時間に 5 回で 429" "$codes" "303 303 303 303 303 429 "
# CF-Connecting-IP は、直前の相手が Cloudflare のときだけ信じる（ローカルは中継の扱いで X-Forwarded-For の最後を見る）
codes=""; for i in 1 2 3 4 5 6; do codes="$codes$(post -d website=x -H "X-Forwarded-For: 198.51.100.1, 203.0.113.7" -H "CF-Connecting-IP: 192.0.2.$((100+i))" | cut -c1-3) "; done
check "Cloudflare 以外からの CF-Connecting-IP は無視する（偽っても 6 回目は 429）" "$codes" "303 303 303 303 303 429 "
codes=""; for i in 1 2 3 4 5 6; do codes="$codes$(post -d website=x -H "X-Forwarded-For: 162.158.1.1" -H "CF-Connecting-IP: 192.0.2.$((110+i))" | cut -c1-3) "; done
check "Cloudflare からなら CF-Connecting-IP を使う（送信元ごとに数える）" "$codes" "303 303 303 303 303 303 "
src() { curl -s "$BASE/api/check.php?token=local" "$@" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["client_ip_source"];'; }
check "送信元の判定（Cloudflare / サーバーが解決済み / 直接 / 中継で不明）" \
  "$(src -H 'X-Forwarded-For: 162.158.1.1' -H 'CF-Connecting-IP: 192.0.2.1') $(src -H 'X-Forwarded-For: 203.0.113.9' -H 'CF-Connecting-IP: 203.0.113.9') $(src -H 'X-Forwarded-For: 203.0.113.9' -H 'CF-Connecting-IP: 192.0.2.1') $(src)" \
  "cloudflare server_resolved direct unknown_proxy"
check "IPv6 の Cloudflare の帯も判定できる" "$(php -r 'require "public/api/_lib.php"; echo json_encode([is_cloudflare_ip("2a06:98c7::1"), is_cloudflare_ip("2a06:98d0::1"), is_cloudflare_ip("172.71.255.255"), is_cloudflare_ip("172.72.0.0")]);')" "[true,false,true,false]"
# 控えを踏み台にさせない
for f in company_name contact_name layout; do
  r=$(curl -s -X POST "$BASE/api/inquiry.php" "${valid[@]}" --data-urlencode "$f=bit.ly/abc" -H "CF-Connecting-IP: 192.0.2.20")
  check "$f に URL は入れられない" "$(echo "$r" | grep -c 'URLは入力できません')" "1"
done
r=$(curl -s -X POST "$BASE/api/inquiry.php" "${valid[@]}" --data-urlencode "company_name=ｈｔｔｐｓ：／／example" -H "CF-Connecting-IP: 192.0.2.20")
check "全角の URL も断る" "$(echo "$r" | grep -c 'URLは入力できません')" "1"
r=$(post "${valid[@]}" --data-urlencode email=third@example.com --data-urlencode "message=受け取りはこちら https://phish.example/" \
  --data-urlencode junk=xxxxxxxx --data-urlencode "referrer=$(printf 'r%.0s' $(seq 1 3000))" -H "CF-Connecting-IP: 192.0.2.21")
check "URL を含む要望欄は受け付ける" "${r%% *}" "303"
body() { php -r '$s = file_get_contents($argv[1]); $p = strpos($s, "\r\n\r\n"); echo base64_decode(substr($s, $p + 4));' "$1"; }
reply=$(grep -l '^To: third@example.com' .local/private/mail/*.eml)
notify=""; for f in $(grep -l '^To: info@macmariya.com' .local/private/mail/*.eml); do body "$f" | grep -q third@example.com && notify=$f; done
check "控えには要望欄の本文を載せない" "$(body "$reply" | grep -c phish)/$(body "$reply" | grep -c '控えでは省略')" "0/1"
check "自分への通知には本文を載せる" "$(body "$notify" | grep -c phish)" "1"
check "raw_json はフォームの欄だけ・2000 文字まで" "$(q "SELECT json_extract(raw_json,'$.junk') IS NULL, length(json_extract(raw_json,'$.referrer')) FROM inquiries WHERE email='third@example.com'")" "1|2000"

echo "pass=$pass fail=$fail"
[ "$fail" -eq 0 ]
