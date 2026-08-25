/**
 * Supply catalog types + icons for Resources / Settings Supplies.
 */
export const SUPPLY_TYPES = [
  "box",
  "poly_mailer",
  "bubble_mailer",
  "kraft_mailer",
  "packaging_materials",
  "office_supplies",
  "warehouse_supplies",
];

export const SUPPLY_TYPE_LABELS = {
  box: "Box",
  poly_mailer: "Poly Mailer",
  bubble_mailer: "Bubble Mailer",
  kraft_mailer: "Kraft Mailer",
  packaging_materials: "Packaging Materials",
  office_supplies: "Office Supplies",
  warehouse_supplies: "Warehouse Supplies",
};

/** Maps supply type → PORTAL_MATERIAL_ICON key */
export const SUPPLY_TYPE_ICONS = {
  box: "inventoryBox",
  poly_mailer: "mail",
  bubble_mailer: "bubbleMail",
  kraft_mailer: "package",
  packaging_materials: "contentCut",
  office_supplies: "description",
  warehouse_supplies: "warehouse",
};

export function supplyTypeLabel(type) {
  const key = String(type || "");
  return SUPPLY_TYPE_LABELS[key] || (key.trim() !== "" ? key : "—");
}

export function supplyTypeIcon(type) {
  return SUPPLY_TYPE_ICONS[String(type || "")] || "inventoryBox";
}

export const SUPPLY_TYPE_OPTIONS = SUPPLY_TYPES.map((id) => ({
  id,
  name: SUPPLY_TYPE_LABELS[id],
}));
