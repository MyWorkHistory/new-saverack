import { publicAssetUrl } from "./brandAssets.js";

/** Crossfade interval between slides (ms). */
export const LOGIN_BG_INTERVAL_MS = 4500;

/**
 * Files in `public/login_images/` (served at /login_images/...).
 * Add or remove filenames to match assets you ship.
 */
export const LOGIN_IMAGE_FILES = [
  "1.jpg",
  "2.jpg",
  "3.jpg",
  "4.jpg",
  "5.jpg",
];

export function loginBackgroundImageUrls() {
  const v = "1";
  return LOGIN_IMAGE_FILES.map((name) =>
    publicAssetUrl(`/login_images/${name}?v=${v}`),
  );
}
