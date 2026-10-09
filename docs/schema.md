# 問い合わせ・受注データベースの設計

フォーム（`/contact/`）の送信は `public/api/inquiry.php` が SQLite（サーバーの `PRIVATE_DIR/madori.sqlite`）に保存する。

> **2026-10-08 から、受注と制作の進捗の正本は自宅の NAS の受注管理アプリ（別リポジトリ `madori-orders`、非公開）に移した。** このサーバーの DB は問い合わせの受け付けと、その状態の写しだけを持つ。下の `orders` テーブルは使っていない（テーブルは残してある）。同期のしかたは末尾の「受注管理アプリとの同期」。テーブル定義の正本は `public/api/schema.sql`、選択肢の値と表示名・料金の計算は `public/api/form.json`。SQLite の方言だけで書いてあるので、Cloudflare D1 へはダンプをそのまま流して移せる。

## テーブルの関係

```
customers 1 ─── n inquiries 1 ─── 0..1 orders
    │                                   │
    └──────────── n orders ─────────────┘   （ココナラ経由など、問い合わせを経ない受注は inquiry_id が NULL）

status_events: inquiries / orders の状態が変わるたびに 1 行（entity_type + entity_id で指す）
id_sequences:  採番のカウンター（日ごとに最後に出した番号。行を消しても戻さない）
mail_log:      送ったメール（notify = 自分への通知 / auto_reply = お客さまへの控え）
submission_log: フォームの送信 1 回ごとの結果（受け付けなかった送信も含む）
```

| テーブル | 1 行の単位 | 主なキー |
|---|---|---|
| customers | 会社・担当者（メールアドレスで 1 件にまとめる。小文字に正規化） | `email` |
| inquiries | フォームの送信 1 回 | 受付番号 `public_id`（`INQ-YYYYMMDD-NNN`） |
| orders | 受注 1 件（1 物件） | 受注番号 `public_id`（`ORD-YYYYMMDD-NNN`）、制作フォルダの `slug` |

## 状態の流れ（blog-vault の `06_ビジネス手順.md` §0 の A〜F に対応）

問い合わせ（B 問い合わせ・見積）:

```
new（受付） → replied（返信した） → quoted（見積を出した） → won（受注になった） / lost（見送り）
                                                        spam（迷惑送信だった）
```

受注（C 受注・制作 → D 納品 → E 入金・記帳）:

```
ordered → materials_received → in_production → first_draft_sent → revising → delivered → invoiced → paid
                                                                                 cancelled（どこからでも）
```

状態を変えるときは、親テーブルの `status` と日時の列を更新し、同じトランザクションで `status_events` に 1 行足す。

## 採番（2026-10-09 改修）

受付番号 `INQ-YYYYMMDD-NNN` は、`id_sequences`（prefix・day・last）に日ごとの最後の番号を持って `last + 1` を出す。**問い合わせの行を消しても番号は戻らない**ので、同じ番号が二度出ることはない。カウンターが無い日（表を作る前のデータ）は、その日の既存の番号の最大値から続ける。

改修前は「その日の行数＋1」で数えていたため、行を消すと次の送信が消した番号をもう一度使っていた（2026-10-08 の試験で、試験の行を消すたびに INQ-20261008-001 が出た）。NAS の受注管理アプリの受注番号 `ORD-` も同じ作りに直した。

## 決めごと

- **日時**は ISO 8601（日本時間、`+09:00` 付き）の文字列。日付だけの列（`desired_deadline` など）は `YYYY-MM-DD`
- **金額**は円・税込の整数
- **選択肢**は英字の値で保存し、表示名は `form.json` から引く。選択肢を増やすときは値を変えずに足す（過去の行が読めなくなるため）
- **料金の概算**（`estimate_amount`）は送信時の料金表（`price_version`）で計算した値。価格を改定したら `form.json` の `pricing.version` を変える。見積で出した額は `quote_amount`、受注の確定額は `orders.amount`
- **送信された項目そのもの**を `raw_json` に残す。フォームの項目を増減しても過去分の中身を失わない（`form_version` でどの版のフォームかが分かる）
- **顧客情報**は `customers` に最新の値を持ち、`inquiries` にも送信時点の写しを持つ（社名変更・担当者の交代に耐える）
- **IP アドレス**は保存せず、`sha256(塩 + IP)` だけを持つ（連続送信の制限と迷惑送信の把握に使う）。Cloudflare 経由なので `CF-Connecting-IP` を使うが、オリジンへ直接送られた場合は偽装できる
- **外部の番号**: ココナラの取引は `orders.coconala_order_id`、freee の取引は `orders.freee_deal_id`
- **納品後 30 日の削除**（blog-vault `06_ビジネス手順.md` §5.1）: `orders.data_delete_due` に納品日＋30 日を入れ、消したら `data_deleted_at` を入れる。会話記録の削除に使う Claude のセッション ID は `orders.claude_session_ids`（JSON 配列）

## 保存期間（2026-10-08 ユーザー決定）

- 受注に至らなかった問い合わせ（`status` が `won` 以外で、`orders` から参照されていないもの）は、**最後のご連絡（`updated_at`）から 1 年**で消す。状態の履歴・メールの記録も一緒に消し、問い合わせも受注も残っていない顧客も消す
- `submission_log` は 1 年で消す
- 受注の記録（`orders` と、受注につながった問い合わせ）は消さない。帳簿として残す
- 消すのは `public/api/_purge.php`。サーバーで `/usr/local/bin/php84 -q ~/public_html/madori.macmariya.com/api/_purge.php` で件数を見て、`--apply` で消す。月 1 回、手で流すか、コントロールパネルの CRON ジョブに登録する
- 返信や見積で状態を変えたら、必ず `updated_at` も更新する（下の例のとおり）。更新しないと、やり取りの途中で消えることがある

## よく使う操作

サーバーで `sqlite3 ~/madori-private/madori.sqlite`（パスは `.env.deploy` の `PRIVATE_DIR`）。

```sql
-- 未対応の問い合わせ
SELECT public_id, created_at, company_name, photo_count_choice, estimate_amount
FROM inquiries WHERE status = 'new' ORDER BY created_at;

-- 返信した（状態の変更は履歴と同じトランザクションで）
BEGIN;
UPDATE inquiries SET status = 'replied', replied_at = strftime('%Y-%m-%dT%H:%M:%S+09:00', 'now', '+9 hours'),
       updated_at = strftime('%Y-%m-%dT%H:%M:%S+09:00', 'now', '+9 hours')
WHERE public_id = 'INQ-20261008-001';
INSERT INTO status_events (entity_type, entity_id, from_status, to_status, actor, created_at)
SELECT 'inquiry', id, 'new', 'replied', 'owner', strftime('%Y-%m-%dT%H:%M:%S+09:00', 'now', '+9 hours')
FROM inquiries WHERE public_id = 'INQ-20261008-001';
COMMIT;

-- 削除予定日を過ぎて、まだ消していない案件
SELECT public_id, slug, delivered_at, data_delete_due FROM orders
WHERE data_deleted_at IS NULL AND data_delete_due <= date('now', '+9 hours');

-- 試験の後片付け（この 5 表だけ消す。id_sequences と schema_migrations は消さない。sqlite_sequence も触らない）
-- BEGIN; DELETE FROM status_events; DELETE FROM mail_log; DELETE FROM inquiries; DELETE FROM customers; DELETE FROM submission_log; COMMIT;

-- バックアップ（手元へ）
-- ssh valueserver "sqlite3 ~/madori-private/madori.sqlite '.backup /tmp/madori.bak'" && scp valueserver:/tmp/madori.bak .
```

## 受注管理アプリとの同期（2026-10-08）

NAS の受注管理アプリが `public/api/sync.php` を 5 分ごとに呼ぶ。合言葉は設定の `sync_token`（ヘッダー `X-Sync-Token`。空・不一致なら 404）。

- `GET ?since=<日時>`: その日時以降に更新された問い合わせ（顧客の写しを含む。`ip_hash`・`user_agent` は渡さない）、残っている全受付番号、選択肢の表示名（`form.json` の `options`）を返す。NAS は全受付番号に無い問い合わせを写しから消す（`_purge.php` の 1 年の削除を NAS にも効かせる）
- `POST {"public_id","to","at",…}`: 問い合わせの状態を変える（返信・見積・受注・見送り）。**状態の正本は NAS** で、ここは写し。同じ状態への変更は何もせず 200 を返す（NAS の送り直しで二重にしない）。状態の流れは上の図と同じで、見送り（lost）からは再開できる
- **受注は NAS にしかないので、`_purge.php` が受注になった問い合わせを残せるのは `status = 'won'` だけによる。** NAS は受注を作るときに必ず won を送り、届くまで送り直す
- 試験: `bash scripts/serve_local.sh` を起動して `bash tests/sync_test.sh`（20 項目）

## blog-vault の案件台帳との関係

`31_Project/間取り図動画/60_Sales/案件台帳.md`（Vault）は 2026-10-08 に受注管理アプリへ置き換えた。顧客の情報を Vault に置かない決まりは変わらない（このサーバーと NAS のアプリにだけある）。
