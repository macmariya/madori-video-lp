# madori-video-lp

「室内写真から作るAI内覧動画」のランディングページ。https://madori.macmariya.com/

- Astro（静的出力）＋素の CSS。サーバーに Node は要らない
- 配信: バリューサーバー（WordPress の www.macmariya.com と同じサーバー）のサブドメイン。DNS は Cloudflare の `*.macmariya.com`（プロキシ ON・SSL は Full）で、追加の DNS 設定は要らない
- 見積もり・相談は専用フォーム `/contact/`。送信は `public/api/inquiry.php`（PHP）がドキュメントルート外の SQLite に保存し、自分への通知とお客さまへの控え（受付番号つき）をメールで送る。写真の添付は受け付けない（2026-10-08 ユーザー決定）。DB の設計は `docs/schema.md`

## 文言の正本

2026-10-08 から、サービス紹介の文言の正本はこのリポジトリ（`src/data/site.ts` と `src/pages/index.astro`）。
元は blog-vault の `10_Content/Pages/間取り図AI内覧動画.md`（WordPress 固定ページ 2055、LP へ 301 する予定）と `31_Project/間取り図動画/60_Sales/出品文案.md`。料金や条件を変えるときは、ココナラの出品文（出品文案.md）も合わせて直す。

守ること:
- 価格は景品表示法の決まりどおりに書く（「今だけ」「期間限定」「先着」「お得」「○% オフ」「通常 ○円」は書かない）
- 架空物件の制作例には、同じ場所に「架空の物件で、図面と室内写真もAIで生成」と書く
- 「AI生成映像」の表示と、AI 生成映像の注意・利用条件・写真の扱いの節は消さない

## 開発

```bash
npm install
npm run dev        # http://localhost:4321
npm run build      # dist/ に出力
```

## 素材の作り直し

`public/media/` と `public/ogp.jpg` はコミット済み。作り直すときだけ実行する（blog-vault と NAS のマウントが要る）。

```bash
bash scripts/make_media.sh
~/dev/blog-vault/31_Project/間取り図動画/20_3D/.venv/bin/python scripts/make_ogp.py
```

素材は架空物件（2LDK）の完成動画と入力写真。ヒーローだけはテロップを入れる前の生成クリップを NAS の素材 tar から取り出す。

## フォームのローカル検証

```bash
bash scripts/serve_local.sh      # http://127.0.0.1:8080 （メールは .local/private/mail/ に .eml で書く）
bash tests/inquiry_test.sh       # 別のターミナルで。DB を作り直して 16 項目を確かめる
```

PHP を直したら `public/api/` から `dist/api/` へ写すか、`serve_local.sh` を起動し直す（配信しているのは `dist/`）。

## デプロイ

バリューサーバーの SSH は、コントロールパネル（お役立ちツール → SSH接続）で接続元の IP を登録した場合だけつながる。**登録は 30 日で切れる**ので、つながらないときはまず登録し直す（登録から約 5 分で有効）。鍵は `~/.ssh/valueserver_madori`、接続名は `~/.ssh/config` の `Host valueserver`。

```bash
cp .env.deploy.example .env.deploy   # 接続情報を埋める（初回だけ）
bash scripts/setup_private.sh        # 初回だけ。ドキュメントルートの外に config.php を作る
./deploy.sh --dry-run                # 差分だけ見る
./deploy.sh                          # 差分を見てから y で反映
```

`public/media/` の動画・画像はファイル名にハッシュが無く、30 日のキャッシュを付けている。同じ名前で差し替えたときは Cloudflare のキャッシュを purge するか、ファイル名を変える。

公開直後に 500 が出たら、`public/.htaccess` の `Options -Indexes` を消す（共有サーバーで Options の上書きが許されていないと 500 になる。他の行は `IfModule` で守ってある）。

`DEPLOY_PATH` の末尾がサブドメイン用のフォルダでないとき、または上げ先に WordPress のファイルがあるときは止まる（`rsync --delete` で WordPress を消さないため）。

公開直後の確認（`scripts/setup_private.sh` が表示した合言葉を使う）:

1. `https://madori.macmariya.com/api/check.php?token=…` で `pdo_sqlite`・`private_dir_writable` が true、`allow_url_fopen` か `curl` のどちらかが true、`db_under_docroot` が false。500 になるときは config.php の権限（PHP の実行ユーザーが読めるか）を疑う
2. 自分宛てにフォームから 1 件送り、通知と控えの両方が届くこと、Gmail の「メッセージのソースを表示」で SPF が PASS であることを確かめる
3. 確かめたら config.php の `check_token` を空にし、試験の行を DB から消す
