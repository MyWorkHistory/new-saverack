/**
 * @param {number} cents
 * @param {string} [currency]
 */
export function formatCents(cents, currency = "USD") {
  const n = Number(cents);
  if (!Number.isFinite(n)) return "—";
  try {
    return new Intl.NumberFormat(undefined, {
      style: "currency",
      currency: currency || "USD",
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(n / 100);
  } catch {
    return (n / 100).toFixed(2);
  }
}
