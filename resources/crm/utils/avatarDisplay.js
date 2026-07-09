const AVATAR_PALETTES = [
  "bg-primary-subtle text-primary-emphasis",
  "bg-success-subtle text-success-emphasis",
  "bg-info-subtle text-info-emphasis",
  "bg-warning-subtle text-warning-emphasis",
  "bg-danger-subtle text-danger-emphasis",
];

export function initialsFromName(name) {
  if (!name || typeof name !== "string") return "?";
  const trimmed = name.trim();
  if (!trimmed) return "?";
  const parts = trimmed.split(/\s+/).filter(Boolean);
  if (parts.length >= 2) {
    return (parts[0][0] + parts[1][0]).toUpperCase();
  }
  const word = parts[0] || trimmed;
  if (word.length >= 2) {
    return word.slice(0, 2).toUpperCase();
  }
  return word[0]?.toUpperCase() ?? "?";
}

/** Brand logo, then portal user avatar — for account directory rows. */
export function accountRowAvatarUrl(row) {
  if (!row || typeof row !== "object") return null;
  const brand = String(row.brand_logo_url || "").trim();
  if (brand) return brand;
  const primary = String(row.primary_avatar_url || "").trim();
  if (primary) return primary;
  return null;
}

export function accountRowInitials(row) {
  if (!row || typeof row !== "object") return "?";
  const name = String(row.company_name || row.contact_full_name || row.brand_name || "").trim();
  return initialsFromName(name);
}

export function staffUserAvatarUrl(user) {
  if (!user || typeof user !== "object") return null;
  const url = String(user.profile?.avatar_url || user.avatar_url || "").trim();
  return url || null;
}

export function staffUserInitials(user) {
  if (!user || typeof user !== "object") return "?";
  const name = String(user.name || user.email || "").trim();
  return initialsFromName(name);
}

export function avatarClassFromSeed(seed) {
  const s = String(seed ?? "");
  let hash = 0;
  for (let i = 0; i < s.length; i += 1) {
    hash = (hash + s.charCodeAt(i)) % 997;
  }
  return AVATAR_PALETTES[hash % AVATAR_PALETTES.length];
}
