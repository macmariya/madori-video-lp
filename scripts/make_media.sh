#!/usr/bin/env bash
# LP の動画クリップ・ポスター画像を作る。出力は public/media/（コミットする）。
# 素材の正本は blog-vault の 31_Project/間取り図動画/（完成 mp4 と架空物件の入力写真）。
# 架空物件（図面・室内写真とも AI 生成）の素材だけを使う。実在の住宅（自宅）は YouTube のサムネ用の 1 コマだけ。
set -euo pipefail
cd "$(dirname "$0")/.."
SRC="${MADORI_SRC:-$HOME/dev/blog-vault/31_Project/間取り図動画}"
OUT=public/media
mkdir -p "$OUT"
V2LDK="$SRC/50_Deliverables/20261006_架空2LDK/20261006_架空2LDK_移動なし.mp4"
P2LDK="$SRC/10_Input/20261006_架空2LDK/photos"

# 区間 [開始秒, 長さ] を 1280x720 にし、往復（順再生＋逆再生）でつなぎ目の無いループにする
# 第 4 引数のフィルタで切り出し方を変える（既定は全体を縮小）
clip() { # out start dur [vf]
  local vf="${4:-scale=1280:720:flags=lanczos}"
  ffmpeg -v error -y -ss "$2" -t "$3" -i "$V2LDK" \
    -filter_complex "[0:v]$vf,fps=24,split[a][b];[b]reverse[r];[a][r]concat=n=2:v=1:a=0,format=yuv420p[v]" \
    -map "[v]" -an -c:v libx264 -preset slow -crf 27 -profile:v high -movflags +faststart "$OUT/$1.mp4"
  ffmpeg -v error -y -ss "$2" -i "$V2LDK" -frames:v 1 -vf "$vf" -q:v 3 "$OUT/$1.jpg"
}
# ヒーローは H3 の生成クリップ（テロップと「AI 生成映像」表示を入れる前）を使う。
# 完成版の素材は NAS の tar に退避済みなので、そこから 1 本だけ取り出す（ページ側で「AI生成映像」を表示する）
TAR="${MADORI_TAR:-/Volumes/personal_folder/Movies/間取り図動画/20261006_架空2LDK_素材.tar}"
RAW=scripts/.cache/S720_still03_crop_floorfix.mp4   # LDK・掃き出し窓（2LDK の 3 カット目。1280x736）
mkdir -p scripts/.cache
if [ ! -f "$RAW" ]; then
  tar -xOf "$TAR" "40_Video/20261006_架空2LDK/local_h3/S720_still03_crop_floorfix.mp4" > "$RAW"
fi
ffmpeg -v error -y -ss 0.2 -t 4.7 -i "$RAW" \
  -filter_complex "[0:v]crop=1280:720:0:8,fps=24,split[a][b];[b]reverse[r];[a][r]concat=n=2:v=1:a=0,format=yuv420p[v]" \
  -map "[v]" -an -c:v libx264 -preset slow -crf 26 -profile:v high -movflags +faststart "$OUT/hero.mp4"
ffmpeg -v error -y -ss 0.2 -i "$RAW" -frames:v 1 -vf "crop=1280:720:0:8" -q:v 3 "$OUT/hero.jpg"

# 比較の対は、入力写真を切り抜かずにそのまま H3 に渡したカットに限る（still_plan.json の crop が写真全体のもの）。
# 切り抜いたカット（still03・still04）を元写真と並べると「構図を変えない」の説明と食い違うため
clip compare-kitchen 23.3 2.4   # キッチン（still05・push_in・切り抜きなし）
clip compare-room    27.4 2.3   # 洋室1（still06・pan_left・切り抜きなし）

# 比較用の元写真（同じカットの入力写真）
photo() { sips -s format jpeg -s formatOptions 80 -Z 1280 "$1" --out "$OUT/$2.jpg" >/dev/null; }
photo "$P2LDK/05_kitchen.png"        photo-kitchen
photo "$P2LDK/06_room1.png"          photo-room

# YouTube の制作例 4 本のポスター（クリックで iframe を読み込む）
poster() { ffmpeg -v error -y -ss "$2" -i "$1" -frames:v 1 -vf scale=960:540:flags=lanczos -q:v 4 "$OUT/$3.jpg"; }
poster "$SRC/50_Deliverables/20261006_自宅/20261006_自宅_移動なし.mp4" 39.05 yt-house
poster "$SRC/50_Deliverables/20261006_架空1K/20261006_架空1K_移動なし.mp4" 27 yt-1k
poster "$SRC/50_Deliverables/20261006_架空1LDK/20261006_架空1LDK_移動なし.mp4" 12 yt-1ldk
poster "$V2LDK" 15.5 yt-2ldk
poster "$SRC/50_Deliverables/20261009_架空1K移動あり/20261009_架空1K移動あり_一筆書き.mp4" 38 yt-walk-1k   # 玄関から歩いて回る版（2026-10-10）。キッチンに正対するコマ

ls -la "$OUT"

# OGP の背景（テロップが出る前の 1 コマ）。make_ogp.py が読む。コミットしない
mkdir -p scripts/.cache
ffmpeg -v error -y -ss 13.9 -i "$V2LDK" -frames:v 1 scripts/.cache/ogp-bg.png
