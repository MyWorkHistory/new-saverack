/** Public carrier tracking URLs for ASN tracking numbers. */
export function asnTrackingUrl(carrier, trackingNumber) {
  const num = String(trackingNumber || "").trim();
  if (!num) {
    return null;
  }
  const c = String(carrier || "").trim().toLowerCase();
  if (c.includes("ups")) {
    return `https://www.ups.com/track?tracknum=${encodeURIComponent(num)}`;
  }
  if (c.includes("usps")) {
    return `https://tools.usps.com/go/TrackConfirmAction?tLabels=${encodeURIComponent(num)}`;
  }
  if (c.includes("fedex")) {
    return `https://www.fedex.com/fedextrack/?trknbr=${encodeURIComponent(num)}`;
  }
  if (c.includes("dhl")) {
    return `https://www.dhl.com/us-en/home/tracking.html?tracking-id=${encodeURIComponent(num)}`;
  }
  if (c.includes("amazon")) {
    return `https://track.amazon.com/tracking/${encodeURIComponent(num)}`;
  }

  return null;
}
