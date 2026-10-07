# 問い合わせ・受注データベースの設計

フォーム（`/contact/`）の送信は `public/api/inquiry.php` が SQLite（サーバーの `PRIVATE_DIR/madori.sqlite`）に保存する。テーブル定義の正本は `public/api/schema.sql`、選択肢の値と表示名・料金の計算は `public/api/form.json`。SQLite の方言だけで書いてあるので、Cloudflare D1 へはダンプをそのまま流して移せる。

## テーブルの関係

```
customers 1 ─── n inquiries 1 ─── 0..1 orders
    │                                   │
    └──────────── n orders ─────────────┘   （ココナラ経由など、問い合わせを経ない受注は inquiry_id が NULL）

status_events: inquiries / orders の状態が変わるたびに 1 行（entity_type + entity_id で指す）
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

-- バックアップ（手元へ）
-- ssh valueserver "sqlite3 ~/madori-private/madori.sqlite '.backup /tmp/madori.bak'" && scp valueserver:/tmp/madori.bak .
```

## blog-vault の案件台帳との関係

`31_Project/間取り図動画/60_Sales/案件台帳.md` は Vault に置くため、顧客名・連絡先を書かない決まりがある。顧客の情報はこの DB だけに置き、台帳とは `orders.slug` でつなぐ。台帳の列（経路・受注日・写真枚数・金額・オプション・セッション ID・納品日・削除予定日・削除日）は `orders` の列にすべて対応させてある。
