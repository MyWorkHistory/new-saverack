/**
 * Storage cubic feet from product dimensions (inches): H × W × L / 1728.
 *
 * @param {{ height?: unknown, width?: unknown, length?: unknown } | null | undefined} dimensions
 * @returns {number | null}
 */
export function cubicFeetFromDimensions(dimensions) {
  if (!dimensions || typeof dimensions !== "object") return null;
  const h = Number(dimensions.height);
  const w = Number(dimensions.width);
  const l = Number(dimensions.length);
  if (!Number.isFinite(h) || !Number.isFinite(w) || !Number.isFinite(l)) return null;
  if (h <= 0 || w <= 0 || l <= 0) return null;
  const cubic = (h * w * l) / 1728;
  if (!Number.isFinite(cubic)) return null;
  return Math.round(cubic * 1000) / 1000;
}

/**
 * @param {number | null} cubicFeet
 * @returns {string}
 */
export function formatCubicFeetDisplay(cubicFeet) {
  if (cubicFeet == null || !Number.isFinite(cubicFeet)) return "—";
  return String(cubicFeet);
}
