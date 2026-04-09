/** In-house CRM workspace — app_redirect links use this host + `?channel=` slug */
export const SAVERACK_SLACK_APP_REDIRECT_BASE =
  "https://saverack.slack.com/app_redirect";

/**
 * Resolve a click target for client Slack (full URLs stored on account).
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
 * Normalize stored in-house value: trim, strip leading #.
 * @param {string|null|undefined} raw
 * @returns {string}
 */
export function inHouseSlackChannelSlug(raw) {
  let s = String(raw ?? "").trim();
  if (!s) return "";
  s = s.replace(/^#+/u, "").trim();
  return s;
}

/**
 * Resolve `slackChannelHref` first (legacy rows may store a full Slack URL),
 * otherwise build app_redirect from channel name (e.g. antonia-saint-ny).
 * @param {string|null|undefined} raw
 * @returns {string|null}
 */
export function inHouseSlackHref(raw) {
  const s = String(raw ?? "").trim();
  if (!s) return null;
  const asUrl = slackChannelHref(raw);
  if (asUrl) {
    return asUrl;
  }
  const slug = inHouseSlackChannelSlug(raw);
  if (!slug) return null;
  return `${SAVERACK_SLACK_APP_REDIRECT_BASE}?channel=${encodeURIComponent(slug)}`;
}

/**
 * Shown in UI: prefer #name when it’s a plain slug.
 * @param {string|null|undefined} raw
 * @returns {string}
 */
export function inHouseSlackDisplayLabel(raw) {
  const slug = inHouseSlackChannelSlug(raw);
  if (!slug) return "";
  const original = String(raw ?? "").trim();
  if (/^#/u.test(original)) return `#${slug}`;
  return slug;
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
