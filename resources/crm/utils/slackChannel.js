/**
 * Resolve a click target for Slack / workspace URLs.
 * @param {string|null|undefined} raw
 * @returns {string|null}
 */
export function slackChannelHref(raw) {
  const s = String(raw ?? "").trim();
  if (!s) return null;
  if (/^https?:\/\//i.test(s)) {
    return s;
  }
  if (/^\/\//.test(s)) {
    return `https:${s}`;
  }
  if (/\bslack\.com\b/i.test(s)) {
    return `https://${s.replace(/^\/+/, "")}`;
  }
  return null;
}

/**
 * @param {string|null|undefined} raw
 * @returns {string}
 */
export function slackChannelLabel(raw) {
  const s = String(raw ?? "").trim();
  if (!s) return "Slack";
  return s;
}
