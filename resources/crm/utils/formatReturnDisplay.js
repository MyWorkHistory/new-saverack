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
