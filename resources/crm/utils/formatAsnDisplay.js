/**
 * Display ASN number without redundant ASN/ASN#/ASN- prefix (e.g. "ASN-00004" → "00004").
 */
export function formatAsnDisplay(raw) {
  const s = String(raw ?? "").trim();
  if (!s) return "";
  const stripped = s.replace(/^ASN[#\s-]*/i, "").trim();
  return stripped || s;
}

/** Page headings, list cells, and confirmations (e.g. `ASN# 0004`). */
export function formatAsnHeading(raw) {
  const n = formatAsnDisplay(raw);
  return n ? `ASN# ${n}` : "";
}

/** Ship To, print label, and identification slip (e.g. `ASN #0007`). */
export function formatAsnLabel(raw) {
  const n = formatAsnDisplay(raw);
  return n ? `ASN #${n}` : "";
}
