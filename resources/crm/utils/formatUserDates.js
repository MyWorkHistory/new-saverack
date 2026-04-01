/** Leap year so Feb 29 is valid when syncing month/day to `birthday`. */
export const BIRTHDAY_SENTINEL_YEAR = 2004;

/** @param {unknown} val */
function parseToLocalDate(val) {
  if (val == null || val === "") return null;
  const s = String(val);
  const iso = s.match(/^(\d{4}-\d{2}-\d{2})/);
  if (iso) {
    const d = new Date(s.includes("T") ? s : `${iso[0]}T12:00:00`);
    return Number.isNaN(d.getTime()) ? null : d;
  }
  const d = new Date(s);
  return Number.isNaN(d.getTime()) ? null : d;
}

/**
 * Full calendar dates as MM/DD/YYYY (US).
 * @param {string|Date|null|undefined} val
 */
export function formatDateUs(val) {
  if (val == null || val === "") return "—";
  const d = val instanceof Date ? val : parseToLocalDate(val);
  if (!d) return "—";
  return new Intl.DateTimeFormat("en-US", {
    month: "2-digit",
    day: "2-digit",
    year: "numeric",
  }).format(d);
}

/**
 * Birthday display: MM/DD only (API stores YYYY-MM-DD with sentinel year).
 * @param {string|null|undefined} val
 */
export function formatBirthdayUs(val) {
  if (val == null || val === "") return "—";
  const s = String(val);
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!m) return s;
  const month = Number(m[2]);
  const day = Number(m[3]);
  if (month < 1 || month > 12 || day < 1 || day > 31) return "—";
  return `${String(month).padStart(2, "0")}/${String(day).padStart(2, "0")}`;
}

/** @deprecated Use formatBirthdayUs for MM/DD; kept for compatibility. */
export function formatBirthdayMonthDay(val) {
  return formatBirthdayUs(val);
}

/** Alias: ISO date strings displayed as MM/DD/YYYY. */
export function formatIsoDate(val) {
  return formatDateUs(val);
}

/**
 * Date-time as MM/DD/YYYY, h:mm AM/PM.
 * @param {string|Date|null|undefined} val
 */
export function formatDateTimeUs(val) {
  if (val == null || val === "") return "—";
  const d = val instanceof Date ? val : parseToLocalDate(val);
  if (!d) return "—";
  return new Intl.DateTimeFormat("en-US", {
    month: "2-digit",
    day: "2-digit",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
  }).format(d);
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
