function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

const URL_PATTERN =
  /(\bhttps?:\/\/[^\s<]+[^\s<.,;:!?)\]"']|\bwww\.[^\s<]+[^\s<.,;:!?)\]"'])/gi;

/**
 * Escape text and auto-link http(s) and www. URLs. Preserves line breaks.
 * @param {string|null|undefined} text
 * @returns {string}
 */
export function formatTextWithLinks(text) {
  const raw = String(text ?? "");
  if (!raw.trim()) return "";

  const escaped = escapeHtml(raw).replace(/\r\n/g, "\n");
  const withBreaks = escaped.replace(/\n/g, "<br>");

  return withBreaks.replace(URL_PATTERN, (match) => {
    const href = match.toLowerCase().startsWith("www.") ? `https://${match}` : match;
    const safeHref = escapeHtml(href);
    const safeLabel = escapeHtml(match);
    return `<a href="${safeHref}" target="_blank" rel="noopener noreferrer" class="crm-linked-text__link">${safeLabel}</a>`;
  });
}
