/**
 * Flat account fee items from API `fees.items` for the pricing-style card grid.
 */
export function normalizeAccountFeeItems(account) {
  const items = account?.fees?.items;
  if (!Array.isArray(items)) return [];

  return items
    .filter((row) => row && typeof row === "object" && row.id != null)
    .map((row) => {
      const mapped = {
        id: Number(row.id),
        name: row.name != null ? String(row.name) : "Fee",
        description: row.description != null ? String(row.description) : "",
        category: row.category != null ? String(row.category) : "",
        category_label: row.category_label != null ? String(row.category_label) : "",
        amount: row.amount != null && row.amount !== "" ? Number(row.amount) : null,
        icon_url: row.icon_url != null ? String(row.icon_url) : null,
        pricing_template_id:
          row.pricing_template_id != null ? Number(row.pricing_template_id) : null,
        sort_order: row.sort_order != null ? Number(row.sort_order) : 0,
        line_code: row.line_code != null ? String(row.line_code) : null,
      };

      // Only pass cost fields when the API included them (admin staff payloads).
      if (Object.prototype.hasOwnProperty.call(row, "cost")) {
        mapped.cost = row.cost != null && row.cost !== "" ? Number(row.cost) : null;
        mapped.default_cost =
          row.default_cost != null && row.default_cost !== ""
            ? Number(row.default_cost)
            : null;
        mapped.cost_is_override = Boolean(row.cost_is_override);
      }

      return mapped;
    })
    .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
}
