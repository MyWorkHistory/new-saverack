/**
 * Human-readable current shipping method for orders list/detail.
 *
 * @param {string|null|undefined} carrier
 * @param {string|null|undefined} method
 * @param {string|null|undefined} title ShipHero shipping_lines.title (e.g. "UPS Expedited")
 * @returns {string}
 */
export function formatCurrentShippingMethod(carrier, method, title) {
  const c = String(carrier ?? "").trim();
  let m = String(method ?? "").trim();
  const t = String(title ?? "").trim();

  if (m && /^\d+$/.test(m) && t) {
    m = t;
  } else if (!m && t) {
    m = t;
  }

  if (!c && !m) return "—";
  if (c && m) return `${c} / ${m}`;
  return c || m;
}

/**
 * Normalize carrier for display (title-case common presets).
 *
 * @param {string|null|undefined} carrier
 * @returns {string}
 */
export function formatCarrierLabel(carrier) {
  const raw = String(carrier ?? "").trim();
  if (!raw) return "";
  const lower = raw.toLowerCase();
  if (lower === "cheapest") return "Cheapest";
  if (lower === "ups") return "UPS";
  if (lower === "fedex") return "FedEx";
  if (lower === "usps") return "USPS";
  if (lower === "dhl") return "DHL";
  return raw;
}

/**
 * @param {Record<string, unknown>|null|undefined} label
 * @returns {{ carrier: string, trackingNumber: string, trackingUrl: string|null }}
 */
export function formatCarrierTrackingLine(label) {
  const carrier = String(label?.carrier_display ?? "").trim() || "—";
  const trackingNumber = String(label?.tracking_number ?? "").trim() || "—";
  const trackingUrl = String(label?.tracking_url ?? "").trim() || null;

  return {
    carrier,
    trackingNumber,
    trackingUrl: trackingUrl && /^https?:\/\//i.test(trackingUrl) ? trackingUrl : null,
  };
}

/**
 * @param {Record<string, unknown>|null|undefined} label
 * @returns {string}
 */
export function formatCarrierTrackingText(label) {
  const { carrier, trackingNumber } = formatCarrierTrackingLine(label);
  return `${carrier} | ${trackingNumber}`;
}

/** ShipHero API carrier slug from UI preset label. */
export function carrierForApi(carrier) {
  const raw = String(carrier ?? "").trim();
  if (!raw) return "";
  const lower = raw.toLowerCase();
  if (lower === "cheapest") return "cheapest";
  if (lower === "ups") return "ups";
  if (lower === "fedex") return "fedex";
  if (lower === "usps") return "usps";
  if (lower === "dhl") return "dhl";
  if (lower === "asendia_one") return "asendia_one";
  if (lower === "ontrac") return "ontrac";
  if (lower === "lasership") return "lasership";
  return raw;
}
