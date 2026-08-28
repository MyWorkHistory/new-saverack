export function gmailSearchHref(email) {
  const q = String(email || "").trim();
  if (!q) return "";
  return `https://mail.google.com/mail/#search/${encodeURIComponent(q)}`;
}
