#!/usr/bin/env python3
"""Rebuild square Slack webhook avatars — truck fills frame, no gray letterboxing."""

from __future__ import annotations

import sys
from pathlib import Path

from PIL import Image

SIZE = 512
# Fraction of canvas the artwork should cover (inscribed circle ~0.88 for Slack avatars).
FILL = 0.88
WHITE_THRESHOLD = 245
# Slack renders transparent webhook icons on gray; use opaque white instead.
BACKGROUND_RGB = (255, 255, 255)

ROOT = Path(__file__).resolve().parents[1]
OUT_DIR = ROOT / "public" / "images" / "slack"

FILES = [
    "shipping-status-live.png",
    "shipping-status-paused.png",
]


def strip_white_to_alpha(im: Image.Image) -> Image.Image:
    im = im.convert("RGBA")
    px = im.load()
    w, h = im.size
    for y in range(h):
        for x in range(w):
            r, g, b, a = px[x, y]
            if r >= WHITE_THRESHOLD and g >= WHITE_THRESHOLD and b >= WHITE_THRESHOLD:
                px[x, y] = (255, 255, 255, 0)
    return im


def process(src: Path, dest: Path) -> None:
    im = strip_white_to_alpha(Image.open(src))
    bbox = im.getbbox()
    if not bbox:
        raise RuntimeError(f"No visible pixels in {src}")
    cropped = im.crop(bbox)

    target = int(SIZE * FILL)
    # Fit by width so the full truck is visible in Slack's circular crop (wide art).
    scale = target / cropped.width
    new_w = target
    new_h = max(1, int(cropped.height * scale))
    resized = cropped.resize((new_w, new_h), Image.Resampling.LANCZOS)

    rgba = Image.new("RGBA", (SIZE, SIZE), (*BACKGROUND_RGB, 255))
    left = (SIZE - new_w) // 2
    top = (SIZE - new_h) // 2
    rgba.paste(resized, (left, top), resized)

    # Flatten to opaque RGB (Slack does not gray-out a solid background).
    flat = Image.new("RGB", (SIZE, SIZE), BACKGROUND_RGB)
    flat.paste(rgba, mask=rgba.split()[3])
    dest.parent.mkdir(parents=True, exist_ok=True)
    flat.save(dest, "PNG", optimize=True)
    print(f"Wrote {dest} ({dest.stat().st_size} bytes, fit {new_w}x{new_h})")


def main() -> int:
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    for name in FILES:
        src = OUT_DIR / name
        if not src.is_file():
            print(f"Missing source: {src}", file=sys.stderr)
            return 1
        process(src, OUT_DIR / name)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
