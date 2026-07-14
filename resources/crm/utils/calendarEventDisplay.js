import { parseCalendarDay } from "./formatUserDates.js";

function formatDate(val, short = false) {
  const d = parseCalendarDay(val);
  if (!d) return "—";
  return new Intl.DateTimeFormat(
    "en-US",
    short
      ? { month: "short", day: "numeric" }
      : { weekday: "short", month: "short", day: "numeric", year: "numeric" },
  ).format(d);
}

export function formatCalendarEventDateRange(event, { short = false } = {}) {
  if (!event) return "—";
  const start = formatDate(event.start_date, short);
  if (!event.end_date || event.end_date === event.start_date) {
    return start;
  }
  const end = formatDate(event.end_date, short);
  return `${start} – ${end}`;
}

/**
 * Home calendar widget parts: uppercase month + day or day range ("23-24").
 * @returns {{ month: string, day: string }}
 */
export function calendarEventDateParts(event) {
  const start = parseCalendarDay(event?.start_date);
  if (!start) {
    return { month: "—", day: "—" };
  }

  const month = new Intl.DateTimeFormat("en-US", { month: "short" })
    .format(start)
    .toUpperCase();
  const startDay = start.getDate();

  const end =
    event?.end_date && event.end_date !== event.start_date
      ? parseCalendarDay(event.end_date)
      : null;

  if (!end) {
    return { month, day: String(startDay) };
  }

  const endDay = end.getDate();
  return { month, day: `${startDay}-${endDay}` };
}

/**
 * Soft wash of a hex color for date widgets (e.g. #ea580c → translucent fill).
 * @param {string|null|undefined} hex
 * @param {number} alpha
 * @returns {string}
 */
export function calendarColorWash(hex, alpha = 0.12) {
  const raw = String(hex || "#6b7280").trim();
  const m = raw.match(/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i);
  if (!m) {
    return `rgba(107, 114, 128, ${alpha})`;
  }
  let h = m[1];
  if (h.length === 3) {
    h = h
      .split("")
      .map((c) => c + c)
      .join("");
  }
  const n = parseInt(h, 16);
  const r = (n >> 16) & 255;
  const g = (n >> 8) & 255;
  const b = n & 255;
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}
