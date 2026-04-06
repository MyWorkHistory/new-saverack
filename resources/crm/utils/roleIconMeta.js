/** Vuexy-style role → icon (outline) mapping + Save Rack system roles. */

const WRAP_BY_KIND = {
  maintainer: "staff-role-icon--maintainer",
  subscriber: "staff-role-icon--subscriber",
  editor: "staff-role-icon--editor",
  author: "staff-role-icon--author",
  admin: "staff-role-icon--admin",
};

const FALLBACK_KIND_CYCLE = [
  "editor",
  "author",
  "subscriber",
  "admin",
  "maintainer",
];

/**
 * @param {{ name?: string, label?: string } | null | undefined} role
 * @returns {'maintainer'|'subscriber'|'editor'|'author'|'admin'|null}
 */
export function resolveRoleIconKind(role) {
  if (!role) return null;
  const name = String(role.name || "")
    .toLowerCase()
    .trim();
  const label = String(role.label || "")
    .toLowerCase()
    .trim();

  if (name === "admin") return "admin";
  if (name === "staff") return "maintainer";
  if (name === "client") return "subscriber";

  if (label === "administrator" || label === "admin") return "admin";
  if (label === "staff") return "maintainer";
  if (label === "3pl client" || (label.includes("3pl") && label.includes("client"))) {
    return "subscriber";
  }

  const demos = ["maintainer", "subscriber", "editor", "author"];
  for (const d of demos) {
    if (label === d) return d;
  }

  return null;
}

/**
 * Icon + wrapper class for one role (unknown labels get a stable hashed fallback).
 * @param {{ name?: string, label?: string } | null | undefined} role
 */
export function getRoleIconMetaForRole(role) {
  const resolved = resolveRoleIconKind(role);
  if (resolved) {
    return { kind: resolved, wrap: WRAP_BY_KIND[resolved] };
  }
  const seed = String(role?.label || role?.name || "");
  let h = 0;
  for (let i = 0; i < seed.length; i++) h = (h + seed.charCodeAt(i)) % 997;
  const kind = FALLBACK_KIND_CYCLE[h % FALLBACK_KIND_CYCLE.length];
  return { kind, wrap: WRAP_BY_KIND[kind] };
}

/**
 * Uses first assigned role (same as primary column in staff list).
 * @param {Array<{ name?: string, label?: string }>|null|undefined} roles
 */
export function getPrimaryRoleIconMeta(roles) {
  if (!roles || !roles.length) {
    return { kind: "none", wrap: "staff-role-icon--none" };
  }
  return getRoleIconMetaForRole(roles[0]);
}
