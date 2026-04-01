/** Leap year so Feb 29 is valid when syncing month/day to `birthday`. */
export const BIRTHDAY_SENTINEL_YEAR = 2004;

export function formatBirthdayMonthDay(val) {
  if (val == null || val === "") return "—";
  const s = String(val);
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!m) return s;
  const month = Number(m[2]) - 1;
  const day = Number(m[3]);
  if (month < 0 || month > 11 || day < 1 || day > 31) return "—";
  const d = new Date(BIRTHDAY_SENTINEL_YEAR, month, day);
  return new Intl.DateTimeFormat("en-US", {
    month: "short",
    day: "numeric",
  }).format(d);
}

export function formatIsoDate(val) {
  if (val == null || val === "") return "—";
  const s = String(val);
  const iso = s.match(/^(\d{4}-\d{2}-\d{2})/);
  return iso ? iso[1] : s;
}

export function daysInMonth(month1To12) {
  const m = Number(month1To12);
  if (!m || m < 1 || m > 12) return 31;
  return new Date(BIRTHDAY_SENTINEL_YEAR, m, 0).getDate();
}

/**
 * @param {string} monthStr "1" .. "12"
 * @param {string} dayStr "1" .. "31"
 * @returns {string|null} YYYY-MM-DD or null
 */
export function birthdayFromMonthDay(monthStr, dayStr) {
  const month = Number(monthStr);
  const day = Number(dayStr);
  if (!monthStr || !dayStr || !month || !day) return null;
  if (month < 1 || month > 12) return null;
  const maxD = daysInMonth(month);
  const d = Math.min(day, maxD);
  const mm = String(month).padStart(2, "0");
  const dd = String(d).padStart(2, "0");
  return `${BIRTHDAY_SENTINEL_YEAR}-${mm}-${dd}`;
}

/** @param {string|null|undefined} iso */
export function parseBirthdayParts(iso) {
  if (iso == null || iso === "") {
    return { month: "", day: "" };
  }
  const s = String(iso);
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!m) return { month: "", day: "" };
  return { month: String(Number(m[2])), day: String(Number(m[3])) };
}
