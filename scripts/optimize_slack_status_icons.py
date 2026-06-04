#!/usr/bin/env python3
"""Build 512×512 Slack webhook avatars from design sources in public/images/slack/sources/."""

from __future__ import annotations

import sys
from pathlib import Path

from PIL import Image

SIZE = 512

ROOT = Path(__file__).resolve().parents[1]
OUT_DIR = ROOT / "public" / "images" / "slack"

SOURCES = [
    ("sources/live-source.png", "shipping-status-live.png"),
    ("sources/paused-source.png", "shipping-status-paused.png"),
]


def process(src: Path, dest: Path) -> None:
    im = Image.open(src).convert("RGB")
    if im.size != (SIZE, SIZE):
        im = im.resize((SIZE, SIZE), Image.Resampling.LANCZOS)
    dest.parent.mkdir(parents=True, exist_ok=True)
    im.save(dest, "PNG", optimize=True)
    print(f"Wrote {dest} ({dest.stat().st_size} bytes)")


def main() -> int:
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    for src_rel, dest_name in SOURCES:
        src = OUT_DIR / src_rel
        if not src.is_file():
            print(f"Missing source: {src}", file=sys.stderr)
            return 1
        process(src, OUT_DIR / dest_name)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
