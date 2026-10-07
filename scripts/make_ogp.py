# OGP 画像（1200×630）を public/ogp.jpg に作る。背景は make_media.sh が書き出す scripts/.cache/ogp-bg.png（架空 2LDK の LDK、テロップが出る前の 1 コマ）。
# 色と書体はココナラのサービス画像（blog-vault 31_Project/間取り図動画/60_Sales/coconala_images/make_images.py）に合わせる。
# 実行: ~/dev/blog-vault/31_Project/間取り図動画/20_3D/.venv/bin/python scripts/make_ogp.py（Pillow が要る）
import os, unicodedata
from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FD = "/System/Library/Fonts"
def font(w, size):
    for f in os.listdir(FD):
        if unicodedata.normalize("NFC", f) == f"ヒラギノ角ゴシック W{w}.ttc":
            return ImageFont.truetype(os.path.join(FD, f), size)
    raise SystemExit("font")

W, H = 1200, 630
ACC = (232, 120, 40); WHITE = (255, 255, 255)
src = Image.open(f"{ROOT}/scripts/.cache/ogp-bg.png").convert("RGB")
# 幅 1260 に縮めて左下を切り出す（右上にある動画側の「AI 生成映像」表示を外し、下で同じ表示を描き直す）
im = src.resize((1260, round(src.height * 1260 / src.width)), Image.LANCZOS)
im = im.crop((0, im.height - H, W, im.height))

# 左から右へ暗くするグラデーション（文字を左に置く）
ov = Image.new("RGBA", (W, H), (0, 0, 0, 0)); od = ImageDraw.Draw(ov)
for x in range(W):
    a = int(230 * max(0, 1 - x / 820))
    od.line([(x, 0), (x, H)], fill=(18, 26, 40, a))
im = Image.alpha_composite(im.convert("RGBA"), ov).convert("RGB"); d = ImageDraw.Draw(im)

f_pill = font(6, 28)
pw = d.textlength("不動産会社・工務店さま向け", font=f_pill)
d.rounded_rectangle((64, 150, 64 + pw + 48, 202), radius=26, fill=ACC)
d.text((88, 158), "不動産会社・工務店さま向け", font=f_pill, fill=WHITE)
d.text((64, 236), "室内写真から作る", font=font(6, 50), fill=WHITE)
d.text((58, 298), "AI内覧動画", font=font(8, 120), fill=WHITE)
d.text((66, 452), "写真1枚ごとに、カメラが寄る・振る・前に進む", font=font(6, 32), fill=(235, 238, 242))

fl = font(5, 22); lw = d.textlength("AI生成映像", font=fl)
d.rounded_rectangle((W - 40 - lw - 28, 28, W - 40, 66), radius=8, fill=(30, 30, 30))
d.text((W - 40 - lw - 14, 34), "AI生成映像", font=fl, fill=WHITE)
im.save(f"{ROOT}/public/ogp.jpg", quality=85)
print("ok", im.size)
