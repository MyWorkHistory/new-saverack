const AVATAR_PALETTES = [
  "bg-primary-subtle text-primary-emphasis",
  "bg-success-subtle text-success-emphasis",
  "bg-info-subtle text-info-emphasis",
  "bg-warning-subtle text-warning-emphasis",
  "bg-danger-subtle text-danger-emphasis",
];

export function initialsFromName(name) {
  if (!name || typeof name !== "string") return "?";
  const parts = name.trim().split(/\s+/).filter(Boolean).slice(0, 2);
  return parts.map((p) => p[0]?.toUpperCase() ?? "").join("") || "?";
}

export function avatarClassFromSeed(seed) {
  const s = String(seed ?? "");
  let hash = 0;
  for (let i = 0; i < s.length; i += 1) {
    hash = (hash + s.charCodeAt(i)) % 997;
  }
  return AVATAR_PALETTES[hash % AVATAR_PALETTES.length];
}
