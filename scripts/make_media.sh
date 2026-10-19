#!/usr/bin/env bash
# LP の動画クリップ・ポスター画像を作る。出力は public/media/（コミットする）。
# 素材の正本は blog-vault の 31_Project/間取り図動画/（完成 mp4、色合わせ済みの生成クリップ、架空物件の入力写真）。
# 冒頭の映像と「写真と映像の比較」は、架空物件（図面・室内写真とも AI 生成）の素材だけを使う。
# 実在の住宅（自宅）は YouTube のポスター用の 1 コマだけ（2026-10-20 ユーザー決定。move への切り替えで作り直した）。
# public/media/ は 30 日のキャッシュを付けているので、作り直すときは同じ名前で上書きせず、名前を変える（README「デプロイ」）。
set -euo pipefail
cd "$(dirname "$0")/.."
SRC="${MADORI_SRC:-$HOME/dev/blog-vault/31_Project/間取り図動画}"
OUT=public/media
mkdir -p "$OUT"
S1LDK=20261019_架空1LDKmove
H3="$SRC/40_Video/$S1LDK/local_h3"     # テロップと「AI 生成映像」表示を入れる前の、色合わせ済みの生成クリップ（1280x736）
P1LDK="$SRC/10_Input/$S1LDK/photos"   # 同じ物件の入力写真（AI 生成）

# 区間 [開始秒, 長さ] を 1280x720 に切り出し、往復（順再生＋逆再生）でつなぎ目の無いループにする
clip() { # src out start dur crf
  ffmpeg -v error -y -ss "$3" -t "$4" -i "$1" \
    -filter_complex "[0:v]crop=1280:720:0:8,fps=24,split[a][b];[b]reverse[r];[a][r]concat=n=2:v=1:a=0,format=yuv420p[v]" \
    -map "[v]" -an -c:v libx264 -preset slow -crf "$5" -profile:v high -movflags +faststart "$OUT/$2.mp4"
  ffmpeg -v error -y -ss "$3" -i "$1" -frames:v 1 -vf "crop=1280:720:0:8" -q:v 3 "$OUT/$2.jpg"
}
# 冒頭: 架空 1LDK の LDK（bwL01）。掃き出し窓 2 つの前を歩く。尾は水栓が崩れるので頭の 4.5 秒だけ
clip "$H3/P720_bwL01_tone.mp4"    hero-move            0.2 4.5 26
# 比較: 入力写真と、同じ部屋の区間の頭（写真の撮影位置の近くから歩き出す所）
clip "$H3/P720_bwK01_pp_tone.mp4" compare-move-kitchen 0   3.0 27   # キッチン（写真 04）。キッチンへ近づく
clip "$H3/P720_bwY01_tone.mp4"    compare-move-room    0   3.0 27   # 洋室（写真 05）。窓を見ながら奥へ進む

photo() { sips -s format jpeg -s formatOptions 80 -Z 1280 "$1" --out "$OUT/$2.jpg" >/dev/null; }
photo "$P1LDK/04_kitchen.png" photo-move-kitchen
photo "$P1LDK/05_room.png"    photo-move-room

# YouTube の制作例 4 本のポスター（クリックで iframe を読み込む）。テロップの出ていないコマを選んだ。映像は音入り版と同じ
poster() { ffmpeg -v error -y -ss "$2" -i "$1" -frames:v 1 -vf scale=960:540:flags=lanczos -q:v 4 "$OUT/$3.jpg"; }
poster "$SRC/50_Deliverables/20261017_bwまたぎ/20261017_自宅_bw部屋またぎ通し_v4_audio.mp4" 78 yt-move-house  # LDK とシーリングファン（139.25 秒の版）
poster "$SRC/50_Deliverables/20261019_架空1Kmove/20261019_架空1Kmove_v1_audio.mp4" 14 yt-move-1k             # キッチンの正面（47.5 秒の版）
poster "$SRC/50_Deliverables/20261019_架空1LDKmove/20261019_架空1LDKmove_v1_audio.mp4" 52 yt-move-1ldk        # LDK の掃き出し窓 2 つ（74.75 秒の版）
poster "$SRC/50_Deliverables/20261019_架空2LDKmove/20261019_架空2LDKmove_v1_audio.mp4" 56.5 yt-move-2ldk      # キッチンの正面（72.96 秒の版）

ls -la "$OUT"

# OGP の背景（冒頭と同じ LDK の 1 コマ）。make_ogp.py が読む。コミットしない
mkdir -p scripts/.cache
ffmpeg -v error -y -ss 0.2 -i "$H3/P720_bwL01_tone.mp4" -frames:v 1 scripts/.cache/ogp-bg.png
