/**
 * @param {unknown} price
 * @returns {string|null} e.g. "$1,000.00", or null if empty/invalid
 */
export function formatUsdPrice(price) {
  if (price == null || price === "") return null;
  const n = Number(price);
  if (Number.isNaN(n)) return null;
  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: "USD",
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(n);
}

/** For detail panels where empty should show an em dash. */
export function formatUsdPriceOrDash(price) {
  const s = formatUsdPrice(price);
  return s ?? "—";
}
