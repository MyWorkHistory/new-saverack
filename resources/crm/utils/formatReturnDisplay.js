export function formatRmaLabel(rmaNumber) {
  const s = String(rmaNumber || "").trim();
  if (!s) return "";
  return `RMA #${s}`;
}

export function returnTypeLabel(type) {
  const t = String(type || "").toLowerCase();
  if (t === "amazon") return "Amazon";
  if (t === "nordstrom") return "Nordstrom";
  return "Direct";
}

export function returnStatusLabel(status) {
  const s = String(status || "").toLowerCase();
  if (s === "received") return "Received";
  if (s === "completed") return "Completed";
  if (s === "draft") return "Draft";
  return "Pending";
}

export function returnStatusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "pending") return "bg-warning-subtle text-warning-emphasis";
  if (s === "received") return "bg-primary-subtle text-primary-emphasis";
  if (s === "completed") return "bg-success-subtle text-success-emphasis";
  if (s === "draft") return "bg-secondary-subtle text-secondary-emphasis";
  return "bg-body-secondary text-body-secondary";
}

/** Admin process page display status (not_returned | pending | returned | non_compliant_return). */
export function processDisplayStatusLabel(displayStatus) {
  const s = String(displayStatus || "").toLowerCase();
  if (s === "not_returned") return "Not Returned";
  if (s === "returned") return "Returned";
  if (s === "non_compliant_return") return "Non-Compliant Return";
  return "Pending";
}

export function processDisplayStatusBadgeClass(displayStatus) {
  const s = String(displayStatus || "").toLowerCase();
  if (s === "not_returned") return "bg-secondary-subtle text-secondary-emphasis";
  if (s === "returned") return "bg-success-subtle text-success-emphasis";
  if (s === "non_compliant_return") return "bg-danger-subtle text-danger-emphasis";
  return "bg-warning-subtle text-warning-emphasis";
}
