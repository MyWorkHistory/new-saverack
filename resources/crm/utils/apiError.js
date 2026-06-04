const VALIDATION_KEY_MESSAGES = {
  "validation.unique": "This value is already in use.",
  "validation.required": "This field is required.",
  "validation.email": "Enter a valid email address.",
  "validation.confirmed": "Confirmation does not match.",
  "validation.min.string": "This value is too short.",
  "validation.max.string": "This value is too long.",
};

/** Map Laravel validation translation keys to plain English. */
export function humanizeValidationMessage(msg) {
  if (msg == null || msg === "") {
    return "";
  }
  const s = String(msg).trim();
  if (VALIDATION_KEY_MESSAGES[s]) {
    return VALIDATION_KEY_MESSAGES[s];
  }
  if (s.startsWith("validation.")) {
    return "Please check your entries and try again.";
  }
  return s;
}

/** Turn an axios/API error into a single user-facing string. */
export function errorMessage(e, fallback = "Something Went Wrong.") {
  const d = e?.response?.data;
  if (!d) {
    return typeof e?.message === "string" && e.message ? e.message : fallback;
  }
  if (d.errors && typeof d.errors === "object") {
    const vals = Object.values(d.errors);
    const first = vals[0];
    if (Array.isArray(first) && first.length) {
      return humanizeValidationMessage(first[0]) || fallback;
    }
    if (typeof first === "string") {
      return humanizeValidationMessage(first) || fallback;
    }
    const flat = vals
      .flatMap((v) => (Array.isArray(v) ? v : v != null ? [v] : []))
      .map((x) => humanizeValidationMessage(x))
      .filter(Boolean);
    if (flat.length) {
      return flat[0];
    }
  }
  if (typeof d.message === "string" && d.message) {
    const msg = humanizeValidationMessage(d.message) || fallback;
    if (
      msg.toLowerCase().includes("given data was invalid") ||
      msg.toLowerCase().includes("validation failed")
    ) {
      return fallback;
    }
    return msg;
  }
  if (typeof d.detail === "string" && d.detail) {
    return d.detail;
  }
  if (d.error && typeof d.error === "string") {
    return d.error;
  }
  if (d.cloudflare_error === true && typeof d.title === "string" && d.title) {
    return d.title;
  }
  return fallback;
}
