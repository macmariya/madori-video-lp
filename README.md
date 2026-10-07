# madori-video-lp

「室内写真から作るAI内覧動画」のランディングページ。https://madori.macmariya.com/

- Astro（静的出力）＋素の CSS。サーバーに Node は要らない
- 配信: バリューサーバー（WordPress の www.macmariya.com と同じサーバー）のサブドメイン。DNS は Cloudflare の `*.macmariya.com`（プロキシ ON・SSL は Full）で、追加の DNS 設定は要らない
- 問い合わせは WordPress の https://www.macmariya.com/contact に飛ばす（LP 側にフォームは持たない）

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

## デプロイ

```bash
cp .env.deploy.example .env.deploy   # 接続情報を埋める（初回だけ）
./deploy.sh --dry-run                # 差分だけ見る
./deploy.sh                          # 差分を見てから y で反映
```

`DEPLOY_PATH` の末尾がサブドメイン用のフォルダでないとき、または上げ先に WordPress のファイルがあるときは止まる（`rsync --delete` で WordPress を消さないため）。
