/** Leap year so Feb 29 is valid when syncing month/day to `birthday`. */
export const BIRTHDAY_SENTINEL_YEAR = 2004;

/**
 * Calendar day in local timezone (noon) from API date/datetime strings.
 * Use for due dates, issue dates, etc. so `YYYY-MM-DDT00:00:00.000000Z` does not
 * shift the displayed day in US timezones.
 * @param {unknown} val
 * @returns {Date|null}
 */
export function parseCalendarDay(val) {
  if (val == null || val === "") return null;
  if (val instanceof Date) {
    if (Number.isNaN(val.getTime())) return null;
    return new Date(val.getFullYear(), val.getMonth(), val.getDate(), 12, 0, 0, 0);
  }
  const s = String(val);
  const iso = s.match(/^(\d{4}-\d{2}-\d{2})/);
  if (!iso) return null;
  const d = new Date(`${iso[1]}T12:00:00`);
  return Number.isNaN(d.getTime()) ? null : d;
}

/** @param {unknown} val */
function parseToLocalDate(val) {
  if (val == null || val === "") return null;
  if (val instanceof Date) {
    return Number.isNaN(val.getTime()) ? null : val;
  }
  const s = String(val);
  const iso = s.match(/^(\d{4}-\d{2}-\d{2})/);
  if (iso && !s.includes("T")) {
    const d = new Date(`${iso[1]}T12:00:00`);
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
  const d = parseCalendarDay(val);
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
 * Value for `<input type="date">` — same calendar day as {@link formatIsoDate}.
 * @param {unknown} val
 */
export function toDateInputValue(val) {
  if (val == null || val === "") return "";
  const d = parseCalendarDay(val);
  if (!d || Number.isNaN(d.getTime())) return "";
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
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
