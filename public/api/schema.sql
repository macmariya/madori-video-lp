-- AI内覧動画の問い合わせ・受注データベース（SQLite。Cloudflare D1 でもそのまま流せる方言で書く）
-- 列の意味とステータスの遷移は docs/schema.md。
-- 日時はすべて ISO 8601（日本時間・+09:00 付き）の文字列、金額は円（税込）の整数。
-- 選択肢の値（customer_type など）は public/api/form.json の value。表示名はそちらを引く。

CREATE TABLE IF NOT EXISTS schema_migrations (
  version     INTEGER PRIMARY KEY,
  applied_at  TEXT NOT NULL
);

-- 顧客（会社・担当者）。メールアドレス（小文字）で 1 件にまとめる
CREATE TABLE IF NOT EXISTS customers (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  email          TEXT NOT NULL UNIQUE,
  company_name   TEXT NOT NULL,
  contact_name   TEXT NOT NULL,
  phone          TEXT,
  prefecture     TEXT,
  customer_type  TEXT NOT NULL,
  note           TEXT,
  created_at     TEXT NOT NULL,
  updated_at     TEXT NOT NULL
);

-- 問い合わせ。フォームの 1 回の送信が 1 行。顧客の情報は送信時点の写しも持つ（社名変更に耐える）
CREATE TABLE IF NOT EXISTS inquiries (
  id                   INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id            TEXT NOT NULL UNIQUE,              -- 受付番号 INQ-YYYYMMDD-NNN
  customer_id          INTEGER NOT NULL REFERENCES customers(id),
  status               TEXT NOT NULL DEFAULT 'new'
                       CHECK (status IN ('new', 'replied', 'quoted', 'won', 'lost', 'spam')),
  channel              TEXT NOT NULL DEFAULT 'lp',         -- lp / mail / phone / coconala

  company_name         TEXT NOT NULL,
  contact_name         TEXT NOT NULL,
  email                TEXT NOT NULL,
  phone                TEXT,
  prefecture           TEXT,
  customer_type        TEXT NOT NULL,

  property_type        TEXT NOT NULL,
  layout               TEXT,
  photo_count_choice   TEXT NOT NULL,                      -- '1'〜'20' / 'over20' / 'unknown'
  photo_count          INTEGER,                            -- 1〜20。21 枚以上・未定は NULL
  floorplan_type       TEXT NOT NULL,
  photo_source         TEXT,
  usage                TEXT NOT NULL DEFAULT '[]',         -- JSON 配列
  wants_object_removal INTEGER NOT NULL DEFAULT 0,         -- 写り込みの消去（オプション）の相談
  desired_deadline     TEXT,                               -- YYYY-MM-DD
  expected_volume      TEXT,
  contact_preference   TEXT NOT NULL DEFAULT 'email',
  message              TEXT,

  estimate_amount      INTEGER,                            -- 送信時の概算。21 枚以上・未定は NULL（要見積）
  price_version        TEXT NOT NULL,                      -- 概算に使った料金表の版
  quote_amount         INTEGER,                            -- 見積で提示した額
  replied_at           TEXT,
  quoted_at            TEXT,
  closed_at            TEXT,                               -- won / lost にした日時
  lost_reason          TEXT,

  consent_at           TEXT NOT NULL,                      -- 個人情報の取り扱いに同意した日時
  form_version         TEXT NOT NULL,
  raw_json             TEXT NOT NULL,                      -- 送信された項目そのもの（項目の増減に備える）
  source_page          TEXT,
  referrer             TEXT,
  utm_source           TEXT,
  utm_medium           TEXT,
  utm_campaign         TEXT,
  user_agent           TEXT,
  ip_hash              TEXT,                               -- sha256(salt + IP)。IP そのものは保存しない
  created_at           TEXT NOT NULL,
  updated_at           TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_inquiries_customer ON inquiries(customer_id);
CREATE INDEX IF NOT EXISTS idx_inquiries_status ON inquiries(status, created_at);

-- 受注。1 案件が 1 行。ココナラ経由など問い合わせを経ない受注は inquiry_id が NULL
CREATE TABLE IF NOT EXISTS orders (
  id                    INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id             TEXT NOT NULL UNIQUE,             -- ORD-YYYYMMDD-NNN
  slug                  TEXT UNIQUE,                      -- 制作フォルダの名前（案件台帳の slug。施主名・住所を入れない）
  inquiry_id            INTEGER REFERENCES inquiries(id),
  customer_id           INTEGER REFERENCES customers(id),
  channel               TEXT NOT NULL,                    -- direct / coconala
  status                TEXT NOT NULL DEFAULT 'ordered'
                        CHECK (status IN ('ordered', 'materials_received', 'in_production', 'first_draft_sent',
                                          'revising', 'delivered', 'invoiced', 'paid', 'cancelled')),
  photo_count           INTEGER,
  amount                INTEGER,                          -- 税込
  options               TEXT NOT NULL DEFAULT '[]',       -- JSON 配列（例 [{"code":"extra_revision","amount":2000}]）
  price_version         TEXT,
  ordered_at            TEXT,
  materials_received_at TEXT,
  first_draft_due       TEXT,                             -- 資料の受領から 3 営業日
  first_draft_sent_at   TEXT,
  delivered_at          TEXT,
  data_delete_due       TEXT,                             -- 納品日＋30 日
  data_deleted_at       TEXT,
  invoiced_at           TEXT,
  paid_at               TEXT,
  claude_session_ids    TEXT NOT NULL DEFAULT '[]',       -- JSON 配列（納品後の削除で会話記録を消すため）
  coconala_order_id     TEXT,
  freee_deal_id         TEXT,
  note                  TEXT,
  created_at            TEXT NOT NULL,
  updated_at            TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);
CREATE INDEX IF NOT EXISTS idx_orders_delete_due ON orders(data_delete_due);

-- 状態の履歴。現在の状態は inquiries.status / orders.status に置き、変えるたびにここへ 1 行足す
CREATE TABLE IF NOT EXISTS status_events (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  entity_type  TEXT NOT NULL CHECK (entity_type IN ('inquiry', 'order')),
  entity_id    INTEGER NOT NULL,
  from_status  TEXT,
  to_status    TEXT NOT NULL,
  note         TEXT,
  actor        TEXT NOT NULL,                             -- form / owner / claude
  created_at   TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_status_events_entity ON status_events(entity_type, entity_id);

-- 送ったメールの記録（通知・自動返信）
CREATE TABLE IF NOT EXISTS mail_log (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  inquiry_id  INTEGER REFERENCES inquiries(id),
  kind        TEXT NOT NULL,                              -- notify / auto_reply
  to_addr     TEXT NOT NULL,
  ok          INTEGER NOT NULL,
  error       TEXT,
  created_at  TEXT NOT NULL
);

-- フォーム送信の記録（受け付けなかった送信も残す。連続送信の制限と迷惑送信の把握に使う）
CREATE TABLE IF NOT EXISTS submission_log (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  ip_hash     TEXT,
  result      TEXT NOT NULL,                              -- ok / honeypot / too_fast / turnstile / invalid / rate_limited / error
  detail      TEXT,
  created_at  TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_submission_log_ip ON submission_log(ip_hash, created_at);
