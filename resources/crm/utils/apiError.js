/** Turn an axios/API error into a single user-facing string. */
export function errorMessage(e, fallback = "Something Went Wrong.") {
  const d = e?.response?.data;
  if (!d) {
    return typeof e?.message === "string" && e.message ? e.message : fallback;
  }
  if (typeof d.message === "string" && d.message) {
    return d.message;
  }
  if (d.error && typeof d.error === "string") {
    return d.error;
  }
  if (d.errors && typeof d.errors === "object") {
    const vals = Object.values(d.errors);
    const first = vals[0];
    if (Array.isArray(first) && first.length) {
      return String(first[0]);
    }
    if (typeof first === "string") {
      return first;
    }
  }
  return fallback;
}
