/** Cursive signature styles for fulfillment agreement e-sign / admin counter-sign. */
export const FULFILLMENT_SIGNATURE_STYLES = [
  {
    id: "dancing_script",
    label: "Dancing Script",
    fontFamily: '"Dancing Script", cursive',
  },
  {
    id: "great_vibes",
    label: "Great Vibes",
    fontFamily: '"Great Vibes", cursive',
  },
  {
    id: "allura",
    label: "Allura",
    fontFamily: '"Allura", cursive',
  },
  {
    id: "pacifico",
    label: "Pacifico",
    fontFamily: '"Pacifico", cursive',
  },
  {
    id: "sacramento",
    label: "Sacramento",
    fontFamily: '"Sacramento", cursive',
  },
];

export const FULFILLMENT_SIGNATURE_FONT_HREF =
  "https://fonts.googleapis.com/css2?family=Allura&family=Dancing+Script:wght@500;700&family=Great+Vibes&family=Pacifico&family=Sacramento&display=swap";

export function fulfillmentSignatureStyleById(id) {
  return FULFILLMENT_SIGNATURE_STYLES.find((s) => s.id === id) || FULFILLMENT_SIGNATURE_STYLES[0];
}

/**
 * Ensure Google Fonts stylesheet is present once.
 */
export function ensureFulfillmentSignatureFonts() {
  if (typeof document === "undefined") return;
  const id = "fulfillment-agreement-signature-fonts";
  if (document.getElementById(id)) return;
  const link = document.createElement("link");
  link.id = id;
  link.rel = "stylesheet";
  link.href = FULFILLMENT_SIGNATURE_FONT_HREF;
  document.head.appendChild(link);
}

/**
 * Render typed name in the chosen cursive style to a PNG data URL.
 * @param {string} text
 * @param {string} styleId
 * @returns {Promise<string>}
 */
export async function renderFulfillmentSignaturePng(text, styleId) {
  const style = fulfillmentSignatureStyleById(styleId);
  const value = String(text || "").trim() || "Signature";
  ensureFulfillmentSignatureFonts();

  if (document.fonts && document.fonts.load) {
    try {
      await document.fonts.load(`48px ${style.fontFamily}`);
    } catch {
      // continue with fallback metrics
    }
  }

  const canvas = document.createElement("canvas");
  const ctx = canvas.getContext("2d");
  const fontSize = 52;
  ctx.font = `${fontSize}px ${style.fontFamily}`;
  const metrics = ctx.measureText(value);
  const width = Math.ceil(Math.max(280, metrics.width + 40));
  const height = 96;
  canvas.width = width;
  canvas.height = height;
  ctx.clearRect(0, 0, width, height);
  ctx.font = `${fontSize}px ${style.fontFamily}`;
  ctx.fillStyle = "#1a1a1a";
  ctx.textBaseline = "middle";
  ctx.fillText(value, 16, height / 2);

  return canvas.toDataURL("image/png");
}

export function todayInputDate() {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}
