#!/usr/bin/env python3
"""Rebuild square Slack webhook avatars from wide source PNGs."""

from __future__ import annotations

import sys
from pathlib import Path

from PIL import Image

SIZE = 512
MARGIN = 0.05
WHITE_THRESHOLD = 245

ROOT = Path(__file__).resolve().parents[1]
OUT_DIR = ROOT / "public" / "images" / "slack"

PAIRS = [
    ("shipping-status-live.png", "shipping-status-live.png"),
    ("shipping-status-paused.png", "shipping-status-paused.png"),
]


def process(src: Path, dest: Path) -> None:
    im = Image.open(src).convert("RGBA")
    px = im.load()
    w, h = im.size
    for y in range(h):
        for x in range(w):
            r, g, b, a = px[x, y]
            if r >= WHITE_THRESHOLD and g >= WHITE_THRESHOLD and b >= WHITE_THRESHOLD:
                px[x, y] = (255, 255, 255, 0)
    bbox = im.getbbox()
    if not bbox:
        raise RuntimeError(f"No visible pixels in {src}")
    cropped = im.crop(bbox)
    avail = int(SIZE * (1 - 2 * MARGIN))
    scale = avail / max(cropped.size)
    new_w = max(1, int(cropped.width * scale))
    new_h = max(1, int(cropped.height * scale))
    resized = cropped.resize((new_w, new_h), Image.Resampling.LANCZOS)
    canvas = Image.new("RGBA", (SIZE, SIZE), (0, 0, 0, 0))
    canvas.paste(resized, ((SIZE - new_w) // 2, (SIZE - new_h) // 2), resized)
    dest.parent.mkdir(parents=True, exist_ok=True)
    canvas.save(dest, "PNG", optimize=True)
    print(f"Wrote {dest} ({dest.stat().st_size} bytes)")


def main() -> int:
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    for src_name, dest_name in PAIRS:
        src = OUT_DIR / src_name
        if not src.is_file():
            print(f"Missing source: {src}", file=sys.stderr)
            return 1
        process(src, OUT_DIR / dest_name)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
