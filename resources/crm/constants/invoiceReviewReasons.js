/** @typedef {{ value: string, label: string }} InvoiceReviewReasonOption */

/** @type {InvoiceReviewReasonOption[]} */
export const INVOICE_REVIEW_REASONS = [
  { value: "other_charges", label: "Other Charges" },
  { value: "fpp_not_matching", label: "FPP Not Matching" },
  { value: "high_postage", label: "High Postage" },
  { value: "missing_fees", label: "Missing Fees" },
];

export const DEFAULT_INVOICE_REVIEW_REASON = "other_charges";

/**
 * @param {string|null|undefined} key
 */
export function invoiceReviewReasonLabel(key) {
  const k = String(key || "").trim();
  const hit = INVOICE_REVIEW_REASONS.find((o) => o.value === k);
  return hit ? hit.label : k || "—";
}
