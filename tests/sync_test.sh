#!/usr/bin/env bash
# 受注管理アプリとの同期口（api/sync.php）の試験。scripts/serve_local.sh で起動したローカルサーバーに対して流す。
# DB（.local/private/madori.sqlite）は最初に消して作り直し、フォームから 2 件送ってから確かめる。
set -uo pipefail
cd "$(dirname "$0")/.."
BASE="${BASE:-http://127.0.0.1:8080}"
TOKEN="${SYNC_TOKEN:-local-sync-token-0123456789abcdef0123}"
DB=.local/private/madori.sqlite
/bin/rm -f "$DB" .local/private/mail/*.eml
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "ok   $1"; pass=$((pass+1)); else echo "NG   $1: 期待 [$3] 実際 [$2]"; fail=$((fail+1)); fi; }
q() { sqlite3 "$DB" "$1"; }
j() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)"; }
old=$(( $(date +%s) * 1000 - 10000 ))
for mail in a@example.com b@example.com; do
  curl -s -o /dev/null -X POST "$BASE/api/inquiry.php" --data-urlencode company_name=試験工務店 --data-urlencode customer_type=builder \
    --data-urlencode contact_name=試験花子 --data-urlencode email=$mail --data-urlencode property_type=house --data-urlencode photo_count=8 \
    --data-urlencode floorplan_type=none --data-urlencode consent=1 --data-urlencode started_at=$old --data-urlencode cf-turnstile-response=XXXX.DUMMY.TOKEN
done
D=$(date +%Y%m%d); ID1="INQ-$D-001"; ID2="INQ-$D-002"
get() { curl -s -w '\n%{http_code}' -H "X-Sync-Token: $1" "$BASE/api/sync.php$2"; }
post() { curl -s -o /dev/null -w '%{http_code}' -X POST -H "X-Sync-Token: $TOKEN" -H 'Content-Type: application/json' -d "$1" "$BASE/api/sync.php"; }
# 状態を変える: to ID 状態 [追加の JSON 項目]（macOS の bash 3.2 は "$(… \"…\" …)" の入れ子の引用符を読み違えるので、JSON は printf で組む）
to() { post "$(printf '{"public_id":"%s","to":"%s","at":"%s"%s}' "$1" "$2" "$now" "${3:+,$3}")"; }

check "合言葉なしは 404" "$(get '' '' | tail -1)" "404"
check "合言葉違いは 404" "$(get 'wrong-token-wrong-token-wrong-token-xx' '' | tail -1)" "404"
r=$(get "$TOKEN" ''); check "合言葉ありは 200" "$(echo "$r" | tail -1)" "200"
body=$(echo "$r" | sed '$d')
check "2 件と全受付番号を返す" "$(echo "$body" | j "len(d['inquiries']), d['ids']")" "2 ['$ID1', '$ID2']"
check "顧客の写しと概算を含み、ip_hash は含まない" "$(echo "$body" | j "d['inquiries'][0]['email'], d['inquiries'][0]['estimate_amount'], 'ip_hash' in d['inquiries'][0]")" "a@example.com 10000 False"
check "Bearer でも通る" "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $TOKEN" "$BASE/api/sync.php")" "200"
check "since が不正なら 400" "$(get "$TOKEN" '?since=xyz' | tail -1)" "400"

sleep 1
now=$(date +%Y-%m-%dT%H:%M:%S+09:00)
check "返信した（new→replied）は 200" "$(to $ID1 replied)" "200"
check "replied_at と updated_at が入る" "$(q "SELECT status, replied_at IS NOT NULL, updated_at > created_at FROM inquiries WHERE public_id='$ID1'")" "replied|1|1"
check "履歴に nas の 1 行" "$(q "SELECT from_status||'>'||to_status||':'||actor FROM status_events WHERE entity_id=1 ORDER BY id DESC LIMIT 1")" "new>replied:nas"
check "同じ状態の送り直しは 200 で履歴を増やさない" "$(to $ID1 replied)/$(q "SELECT COUNT(*) FROM status_events WHERE entity_id=1")" "200/2"
check "見積（金額つき）" "$(to $ID1 quoted '"quote_amount":12000')/$(q "SELECT quote_amount FROM inquiries WHERE id=1")" "200/12000"
check "受注（quoted→won）で closed_at が入る" "$(to $ID1 won)/$(q "SELECT status, closed_at IS NOT NULL FROM inquiries WHERE id=1")" "200/won|1"
check "受注からは戻せない（409）" "$(to $ID1 lost)" "409"
check "見送り（理由つき）" "$(to $ID2 lost '"lost_reason":"予算"')/$(q "SELECT lost_reason FROM inquiries WHERE id=2")" "200/予算"
check "見送りから再開すると理由と締めの日時が消える" "$(to $ID2 replied)/$(q "SELECT status, IFNULL(lost_reason,'NULL'), IFNULL(closed_at,'NULL') FROM inquiries WHERE id=2")" "200/replied|NULL|NULL"
check "無い受付番号は 404" "$(to INQ-20000101-001 replied)" "404"
check "知らない状態は 400" "$(to $ID1 done)" "400"
check "JSON でなければ 400" "$(post 'xxx')" "400"
since=$(q "SELECT updated_at FROM inquiries WHERE id=1")
check "since 以降に更新された行だけ返す（同じ秒も含む）" "$(get "$TOKEN" "?since=$(python3 -c "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))" "$since")" | sed '$d' | j "[r['public_id'] for r in d['inquiries']], len(d['ids'])")" "['$ID1', '$ID2'] 2"
echo "pass=$pass fail=$fail"
[ "$fail" -eq 0 ]
