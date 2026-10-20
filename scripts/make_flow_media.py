# 制作例の「図面と写真 → 完成動画」のスライド用の画像を作る。出力は public/media/flow-*.jpg（コミットする）。
# 素材は blog-vault の 31_Project/間取り図動画/10_Input/20261019_架空{1K,1LDK,2LDK}move/ の plan.png と photos/（どれも AI 生成の架空物件）。
# 実在の住宅（自宅）の図面と写真は使わない（2026-10-20 ユーザー決定。自宅は制作例のポスター 1 コマだけ）。
# 図面は余白を切り、写真と同じ 3:2 の白地の真ん中に置く（スライドの高さをそろえるため）。
# 実行: ~/dev/blog-vault/31_Project/間取り図動画/20_3D/.venv/bin/python scripts/make_flow_media.py（Pillow が要る）
import os
from PIL import Image, ImageChops

SRC = os.environ.get("MADORI_SRC", os.path.expanduser("~/dev/blog-vault/31_Project/間取り図動画")) + "/10_Input"
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "public", "media")
W, H = 1080, 720
PROPS = {"1k": "20261019_架空1Kmove", "1ldk": "20261019_架空1LDKmove", "2ldk": "20261019_架空2LDKmove"}

def plan(src, out):
    im = Image.open(src).convert("RGB")
    box = ImageChops.difference(im, Image.new("RGB", im.size, "white")).point(lambda v: 255 if v > 24 else 0).getbbox()
    im = im.crop(box)
    s = min((W - 80) / im.width, (H - 60) / im.height)
    im = im.resize((round(im.width * s), round(im.height * s)), Image.LANCZOS)
    c = Image.new("RGB", (W, H), "white")
    c.paste(im, ((W - im.width) // 2, (H - im.height) // 2))
    c.save(out, quality=82, optimize=True, progressive=True)

for key, slug in PROPS.items():
    d = f"{SRC}/{slug}"
    plan(f"{d}/plan.png", f"{OUT}/flow-{key}-plan.jpg")
    for f in sorted(os.listdir(f"{d}/photos")):
        if f.endswith(".png"):
            im = Image.open(f"{d}/photos/{f}").convert("RGB").resize((W, H), Image.LANCZOS)
            im.save(f"{OUT}/flow-{key}-{f[:2]}.jpg", quality=78, optimize=True, progressive=True)
print(sorted(x for x in os.listdir(OUT) if x.startswith("flow-")))
