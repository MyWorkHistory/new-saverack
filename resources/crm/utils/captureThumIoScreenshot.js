/**
 * Capture a website screenshot via thum.io in the browser.
 * Prefetch → poll until a real (non-blank) image is ready, then return a File.
 *
 * @see https://www.thum.io/documentation/api/url
 * @param {{ screenshot_url: string, prefetch_url?: string }} plan
 * @returns {Promise<File>}
 */
export async function captureThumIoScreenshot(plan) {
  const screenshotUrl = String(plan?.screenshot_url || "").trim();
  if (!screenshotUrl) {
    throw new Error("Could not build screenshot URL.");
  }

  const prefetchUrl = String(plan?.prefetch_url || "").trim()
    || screenshotUrl.replace("/get/", "/get/prefetch/");

  // Queue render (docs: /prefetch/). Ignore body — may be plain text.
  try {
    await fetch(prefetchUrl, { mode: "cors", credentials: "omit", cache: "no-store" });
  } catch {
    // Prefetch is best-effort; polling the image URL still works.
  }

  const maxAttempts = 12;
  let lastError = "Screenshot was not ready yet. Wait a moment and try again.";

  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    if (attempt > 1) {
      await sleep(1500);
    }

    try {
      const bust = screenshotUrl.includes("?")
        ? `${screenshotUrl}&_t=${Date.now()}`
        : `${screenshotUrl}?_t=${Date.now()}`;
      const imgRes = await fetch(bust, { mode: "cors", credentials: "omit", cache: "no-store" });
      if (!imgRes.ok) {
        lastError = "Could not capture website screenshot. Try again in a moment.";
        continue;
      }

      const blob = await imgRes.blob();
      if (!blob || blob.size < 512 || !String(blob.type || "").startsWith("image/")) {
        lastError = "Screenshot was not ready yet. Wait a moment and try again.";
        continue;
      }

      if (await isMostlyBlankImageBlob(blob)) {
        lastError = "Screenshot came back blank. Trying again…";
        continue;
      }

      const type = blob.type || "image/png";
      const ext = type.includes("jpeg") || type.includes("jpg") ? "jpg" : "png";
      return new File([blob], `website-thumbnail.${ext}`, { type });
    } catch (e) {
      lastError = e instanceof Error && e.message ? e.message : lastError;
    }
  }

  throw new Error(lastError);
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Detect white/black loader frames so we don't save empty thumbnails.
 * @param {Blob} blob
 */
async function isMostlyBlankImageBlob(blob) {
  let bitmap;
  try {
    bitmap = await createImageBitmap(blob);
  } catch {
    return true;
  }

  try {
    const w = Math.min(48, bitmap.width);
    const h = Math.min(48, bitmap.height);
    if (w < 8 || h < 8) return true;

    const canvas = document.createElement("canvas");
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext("2d", { willReadFrequently: true });
    if (!ctx) return false;
    ctx.drawImage(bitmap, 0, 0, w, h);
    const { data } = ctx.getImageData(0, 0, w, h);

    let samples = 0;
    let nearBlack = 0;
    let nearWhite = 0;
    let lumaSum = 0;
    const buckets = new Set();

    for (let i = 0; i < data.length; i += 16) {
      const r = data[i];
      const g = data[i + 1];
      const b = data[i + 2];
      const a = data[i + 3];
      if (a < 20) continue;
      const luma = 0.2126 * r + 0.7152 * g + 0.0722 * b;
      lumaSum += luma;
      samples += 1;
      if (luma < 12) nearBlack += 1;
      if (luma > 245) nearWhite += 1;
      buckets.add(`${(r / 32) | 0}-${(g / 32) | 0}-${(b / 32) | 0}`);
    }

    if (samples < 8) return true;
    const avg = lumaSum / samples;
    const blackRatio = nearBlack / samples;
    const whiteRatio = nearWhite / samples;

    return (
      (blackRatio >= 0.95 && avg < 12) ||
      (whiteRatio >= 0.92 && avg > 240) ||
      (buckets.size <= 2 && (avg < 18 || avg > 240))
    );
  } finally {
    bitmap.close?.();
  }
}
