/**
 * Storage cubic feet from product dimensions (inches): H × W × L / 1728.
 *
 * @param {{ height?: unknown, width?: unknown, length?: unknown }|null|undefined} dimensions
 * @returns {string|null} Formatted value (3 decimals) or null when not calculable
 */
export function cubicFeetFromDimensions(dimensions) {
  const h = Number(dimensions?.height);
  const w = Number(dimensions?.width);
  const l = Number(dimensions?.length);
  if (!Number.isFinite(h) || !Number.isFinite(w) || !Number.isFinite(l)) {
    return null;
  }
  if (h <= 0 || w <= 0 || l <= 0) {
    return null;
  }
  const cubic = (h * w * l) / 1728;
  return cubic.toFixed(3);
}

/**
 * @param {{ height?: unknown, width?: unknown, length?: unknown }|null|undefined} dimensions
 * @returns {string}
 */
export function formatCubicFeetDisplay(dimensions) {
  const value = cubicFeetFromDimensions(dimensions);
  return value !== null ? value : "—";
}
