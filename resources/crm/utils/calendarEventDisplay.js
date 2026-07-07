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
