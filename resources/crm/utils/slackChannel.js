/**
 * @param {string|null|undefined} raw
 * @returns {string|null} URL when the stored value is linkable, otherwise null.
 */
export function slackChannelHref(raw) {
  const s = String(raw ?? "").trim();
  if (!s) return null;
  if (/^https?:\/\//i.test(s)) {
    return s;
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
