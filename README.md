# madori-video-lp

「室内写真から作るAI内覧動画」のランディングページ。https://madori.macmariya.com/

- Astro（静的出力）＋素の CSS。サーバーに Node は要らない
- 配信: バリューサーバー（WordPress の www.macmariya.com と同じサーバー）のサブドメイン。DNS は Cloudflare の `*.macmariya.com`（プロキシ ON・SSL は Full）で、追加の DNS 設定は要らない
- 見積もり・相談は専用フォーム `/contact/`。送信は `public/api/inquiry.php`（PHP）がドキュメントルート外の SQLite に保存し、自分への通知とお客さまへの控え（受付番号つき）をメールで送る。写真の添付は受け付けない（2026-10-08 ユーザー決定）。DB の設計は `docs/schema.md`

## 受注管理アプリとの同期

受注と制作の進捗は、自宅の NAS で動く別のアプリ（非公開）で管理している。そのアプリが `public/api/sync.php` で問い合わせを取り込み、問い合わせの状態（返信・見積・受注・見送り）を書き戻す。仕組みは `docs/schema.md` の「受注管理アプリとの同期」。

サーバーの `config.php` に合言葉 `sync_token`（`openssl rand -hex 32`。アプリ側の `SERVER_SYNC_TOKEN` と同じ値）を入れる。`scripts/setup_private.sh` は新しく作るときだけ入れるので、既にある `config.php` にはサーバー上で 1 行足す（2026-10-08 に足した）。空なら `sync.php` は 404 を返す。

## 公開リポジトリについて

ポートフォリオとして公開している。コードは参照用で、文章・画像・動画（`public/media/`・`public/ogp.jpg`）の再利用はできない。制作例の映像は AI で生成したもので、架空物件の図面と室内写真も AI 生成である。

サーバーのホスト名・ユーザー名・パスは載せていない。実際の値はローカルの `.env.deploy`（gitignore）と `~/.ssh/config.d/valueserver.conf` にある。

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
bash tests/sync_test.sh          # 受注管理アプリとの同期口（20 項目）
```

PHP を直したら `public/api/` から `dist/api/` へ写すか、`serve_local.sh` を起動し直す（配信しているのは `dist/`）。

## デプロイ

バリューサーバーの SSH は、コントロールパネル（お役立ちツール → SSH接続）で接続元の IP を登録した場合だけつながる。**登録は 30 日で切れる**ので、つながらないときはまず登録し直す（登録から約 5 分で有効）。鍵は `~/.ssh/valueserver_madori`、接続名は `~/.ssh/config.d/valueserver.conf` の `Host valueserver`（`~/.ssh/config` は dotfiles の管理なので書き足さない）。共通設定がパスワード認証を切っているので、鍵を登録し直すときは `ssh-copy-id -o PasswordAuthentication=yes -i ~/.ssh/valueserver_madori.pub {ユーザー}@{SSH ホスト}`。

サーバーの PHP はドメインごとに選べる（既定は 7.4、8.5 まである）。フォームの PHP は 7.4 以上で動くように書いてあり、2026-10-08 にサーバーの 7.4.33 と 8.4.17 で DB 作成・料金・Turnstile・採番を実際に動かして確かめた。

```bash
cp .env.deploy.example .env.deploy   # 接続情報を埋める（初回だけ）
bash scripts/setup_private.sh        # 初回だけ。ドキュメントルートの外に config.php を作る
./deploy.sh --dry-run                # 差分だけ見る
./deploy.sh                          # 差分を見てから y で反映
```

`public/media/` の動画・画像はファイル名にハッシュが無く、30 日のキャッシュを付けている。同じ名前で差し替えたときは Cloudflare のキャッシュを purge するか、ファイル名を変える。

公開直後に 500 が出たら、`public/.htaccess` の `Options -Indexes` を消す（共有サーバーで Options の上書きが許されていないと 500 になる。他の行は `IfModule` で守ってある）。

`DEPLOY_PATH` の末尾がサブドメイン用のフォルダでないとき、または上げ先に WordPress のファイルがあるときは止まる（`rsync --delete` で WordPress を消さないため）。

メールの差出人は送信専用の `noreply@madori.macmariya.com`（バリューサーバーのドメインメール。DKIM はバリューサーバーで有効にし、鍵は Cloudflare の `default._domainkey.madori.macmariya.com`、SPF は `madori.macmariya.com` の TXT。2026-10-08）。`madori.macmariya.com` には A レコード（オリジンの IP・プロキシ ON）を明示してある。同じ名前に TXT を足すとワイルドカード `*.macmariya.com` が効かなくなるため（2026-10-08 に一度つながらなくなった）。`macmariya.com` 自体にはバリューサーバーのドメインメールを作らない（サーバー内で配送され、info@ への通知が Gmail に届かなくなるおそれがあるため）。お客さまへの返信先と署名は設定の `contact_email`。

公開直後の確認（`scripts/setup_private.sh` が表示した合言葉を使う）:

1. `https://madori.macmariya.com/api/check.php?token=…` で `pdo_sqlite`・`private_dir_writable` が true、`allow_url_fopen` か `curl` のどちらかが true、`db_under_docroot` が false。500 になるときは config.php の権限（PHP の実行ユーザーが読めるか）を疑う
2. 自分宛てにフォームから 1 件送り、通知と控えの両方が届くこと、Gmail の「メッセージのソースを表示」で SPF が PASS であることを確かめる
3. 確かめたら config.php の `check_token` を空にし、試験の行を DB から消す
