/**
 * Capture a website screenshot via thum.io in the browser.
 * One (or few) fetch attempts — never hammer the API (that triggers "local rate limited").
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

  // Do NOT prefetch + poll aggressively — that triggers thum.io "local rate limited".
  // At most 2 attempts, spaced apart.
  const maxAttempts = 2;
  let lastError = "Could not capture website screenshot. Try again in a few minutes.";

  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    if (attempt > 1) {
      await sleep(4000);
    }

    try {
      const imgRes = await fetch(screenshotUrl, {
        mode: "cors",
        credentials: "omit",
        // Allow browser HTTP cache for the same URL — avoids burning rate limit.
        cache: attempt === 1 ? "default" : "reload",
      });

      const contentType = String(imgRes.headers.get("content-type") || "").toLowerCase();
      const raw = await imgRes.arrayBuffer();
      const bytes = new Uint8Array(raw);

      if (!imgRes.ok) {
        if (imgRes.status === 429) {
          throw rateLimitError();
        }
        lastError = `Screenshot service returned HTTP ${imgRes.status}.`;
        continue;
      }

      // Plain-text rate-limit / status messages (never save as a logo).
      if (contentType.includes("text/") || looksLikeTextPayload(bytes)) {
        const text = new TextDecoder().decode(bytes).trim();
        if (isRateLimitMessage(text)) {
          throw rateLimitError();
        }
        lastError = text
          ? `Screenshot not ready: ${text.slice(0, 120)}`
          : "Screenshot was not ready yet.";
        continue;
      }

      const blob = new Blob([bytes], {
        type: contentType.startsWith("image/") ? contentType : "image/png",
      });

      if (blob.size < 512) {
        lastError = "Screenshot was not ready yet.";
        continue;
      }

      const kind = await classifyScreenshotBlob(blob);
      if (kind === "rate_limited") {
        throw rateLimitError();
      }
      if (kind === "blank") {
        lastError = "Screenshot came back blank. Try again in a minute.";
        continue;
      }

      const type = blob.type || "image/png";
      const ext = type.includes("jpeg") || type.includes("jpg") ? "jpg" : "png";
      return new File([blob], `website-thumbnail.${ext}`, { type });
    } catch (e) {
      if (e && e.code === "THUM_RATE_LIMITED") {
        throw e;
      }
      lastError = e instanceof Error && e.message ? e.message : lastError;
    }
  }

  throw new Error(lastError);
}

function rateLimitError() {
  const err = new Error(
    "thum.io rate limited this request (\"local rate limited\"). Wait a few minutes and try once. If you have a paid plan, add app.saverack.com as an allowed referrer in the thum.io dashboard so paid quota applies.",
  );
  err.code = "THUM_RATE_LIMITED";
  return err;
}

function isRateLimitMessage(text) {
  const t = String(text || "").toLowerCase();
  return t.includes("rate limit") || t.includes("rate-limited") || t.includes("too many");
}

function looksLikeTextPayload(bytes) {
  if (!bytes || bytes.length < 4) return false;
  // PNG / JPEG / GIF / WEBP magic numbers
  if (bytes[0] === 0x89 && bytes[1] === 0x50) return false; // PNG
  if (bytes[0] === 0xff && bytes[1] === 0xd8) return false; // JPEG
  if (bytes[0] === 0x47 && bytes[1] === 0x49) return false; // GIF
  if (bytes[0] === 0x52 && bytes[1] === 0x49) return false; // RIFF/WEBP
  // Mostly printable ASCII → text
  let printable = 0;
  const n = Math.min(bytes.length, 200);
  for (let i = 0; i < n; i += 1) {
    const c = bytes[i];
    if (c === 9 || c === 10 || c === 13 || (c >= 32 && c < 127)) printable += 1;
  }
  return printable / n > 0.85;
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * @param {Blob} blob
 * @returns {Promise<'ok'|'blank'|'rate_limited'>}
 */
async function classifyScreenshotBlob(blob) {
  let bitmap;
  try {
    bitmap = await createImageBitmap(blob);
  } catch {
    return "blank";
  }

  try {
    const w = Math.min(96, bitmap.width);
    const h = Math.min(96, bitmap.height);
    if (w < 8 || h < 8) return "blank";

    // Tiny file + huge canvas often means an error placard, not a real page shot.
    if (blob.size < 20_000 && (bitmap.width >= 400 || bitmap.height >= 400)) {
      // Fall through to pixel analysis — rate-limit placards are small files.
    }

    const canvas = document.createElement("canvas");
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext("2d", { willReadFrequently: true });
    if (!ctx) return "ok";
    ctx.drawImage(bitmap, 0, 0, w, h);
    const { data } = ctx.getImageData(0, 0, w, h);

    let samples = 0;
    let nearBlack = 0;
    let nearWhite = 0;
    let darkInk = 0;
    let lumaSum = 0;
    const buckets = new Set();

    for (let i = 0; i < data.length; i += 8) {
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
      // Dark-gray/black ink on white (error placard text).
      if (luma < 80) darkInk += 1;
      buckets.add(`${(r / 32) | 0}-${(g / 32) | 0}-${(b / 32) | 0}`);
    }

    if (samples < 8) return "blank";
    const avg = lumaSum / samples;
    const blackRatio = nearBlack / samples;
    const whiteRatio = nearWhite / samples;
    const inkRatio = darkInk / samples;

    // "local rate limited" placard: mostly white, a little dark text, small file.
    if (blob.size < 40_000 && whiteRatio >= 0.75 && inkRatio > 0.01 && inkRatio < 0.2 && avg > 200) {
      return "rate_limited";
    }

    if (
      (blackRatio >= 0.95 && avg < 12) ||
      (whiteRatio >= 0.97 && avg > 245) ||
      (buckets.size <= 2 && (avg < 18 || avg > 240))
    ) {
      return "blank";
    }

    return "ok";
  } finally {
    bitmap.close?.();
  }
}
