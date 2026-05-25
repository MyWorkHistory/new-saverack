/**
 * Portal Products inventory CSV export (user-facing columns only).
 */

function escapeCsvCell(value) {
  const s = String(value ?? "").replace(/"/g, '""');
  return `"${s}"`;
}

function downloadCsv(filenamePrefix, headers, rows) {
  const lines = [headers.join(",")];
  for (const cells of rows) {
    lines.push(cells.map(escapeCsvCell).join(","));
  }
  const blob = new Blob([lines.join("\r\n")], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `${filenamePrefix}-${new Date().toISOString().slice(0, 10)}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

export function exportPortalInventoryCsv(source, variant) {
  const list = Array.isArray(source) ? source : [];
  if (!list.length) {
    return false;
  }

  if (variant === "out-of-stock") {
    const headers = ["SKU", "Name", "Oversold", "On Hand", "Allocated"];
    const rows = list.map((r) => [
      r.sku,
      r.name,
      Number(r.backorder || 0),
      Number(r.on_hand || 0),
      Number(r.allocated || 0),
    ]);
    downloadCsv("out-of-stock", headers, rows);
    return true;
  }

  const headers = ["SKU", "Name", "Kit", "On Hand", "Allocated", "Backorder"];
  const rows = list.map((r) => [
    r.sku,
    r.name,
    r.kit ? "yes" : "no",
    Number(r.on_hand || 0),
    Number(r.allocated || 0),
    Number(r.backorder || 0),
  ]);
  downloadCsv("inventory", headers, rows);
  return true;
}
