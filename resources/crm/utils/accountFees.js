/**
 * Format a fee for display. Accepts API scalars, or objects with
 * { amount, currency?, display? }.
 */
export function formatFeeDisplay(raw) {
  if (raw == null || raw === "") return null;
  if (typeof raw === "object" && raw !== null) {
    if (raw.display != null && String(raw.display).trim() !== "") {
      return String(raw.display);
    }
    const a = raw.amount;
    if (a != null && a !== "") {
      const n = Number(a);
      if (!Number.isNaN(n)) {
        const cur = typeof raw.currency === "string" && raw.currency ? raw.currency : "USD";
        try {
          return new Intl.NumberFormat(undefined, {
            style: "currency",
            currency: cur,
          }).format(n);
        } catch {
          return String(a);
        }
      }
      return String(a);
    }
    return null;
  }
  if (typeof raw === "number") {
    if (Number.isNaN(raw)) return null;
    try {
      return new Intl.NumberFormat(undefined, {
        style: "currency",
        currency: "USD",
      }).format(raw);
    } catch {
      return String(raw);
    }
  }
  return String(raw);
}

/**
 * Normalize optional `account.fees` (and legacy nesting) for the Account Fees UI.
 *
 * Expected API shape (all optional):
 * {
 *   fulfillment: {
 *     first_pick_fee | order_fulfillment_1st_pick_fee | order_fulfillment_first_pick_fee,
 *     additional_picks_fee | additional_picks
 *   },
 *   returns: {
 *     processing_fee | returns_processing_fee,
 *     additional_items_fee | additional_items
 *   },
 *   storage | storage_fees: Array<{ label?, type?, name?, amount?, value?, display?, currency? }>
 *        | Record<string, number|string|{amount?}>>
 * }
 */
export function normalizeAccountFees(account) {
  const fees = account?.fees;
  const f = fees && typeof fees === "object" ? fees : {};

  const ful = f.fulfillment ?? f.fulfillment_fees ?? {};
  const ret = f.returns ?? f.return_fees ?? {};

  const firstPick =
    ful.first_pick_fee ??
    ful.order_fulfillment_1st_pick_fee ??
    ful.order_fulfillment_first_pick_fee;
  const additionalPicks =
    ful.additional_picks_fee ?? ful.additional_picks ?? ful.additional_items_same_order_fee;

  const processing =
    ret.processing_fee ?? ret.returns_processing_fee ?? ret.returns_processing;
  const additionalItems =
    ret.additional_items_fee ?? ret.additional_items ?? ret.additional_return_items_fee;

  /** @type {{ label: string, value: string | null, id: number | null }[]} */
  let storageRows = [];
  const storageRaw = f.storage ?? f.storage_fees;

  if (Array.isArray(storageRaw)) {
    storageRows = storageRaw.map((item, i) => {
      if (item == null) {
        return { label: `Storage fee ${i + 1}`, value: null, id: null };
      }
      if (typeof item === "string" || typeof item === "number") {
        return {
          label: `Storage fee ${i + 1}`,
          value: formatFeeDisplay(item),
          id: null,
        };
      }
      if (typeof item === "object") {
        const label =
          item.label ??
          item.name ??
          item.type ??
          item.key ??
          (item.description ? String(item.description) : null) ??
          `Storage fee ${i + 1}`;
        const value = formatFeeDisplay(
          item.amount ?? item.value ?? item.fee ?? item.price ?? item,
        );
        const rawId = item.id;
        const id = rawId != null && rawId !== "" ? Number(rawId) : null;
        return {
          label: String(label),
          value,
          id: Number.isFinite(id) ? id : null,
        };
      }
      return { label: `Storage fee ${i + 1}`, value: null, id: null };
    });
  } else if (storageRaw && typeof storageRaw === "object") {
    storageRows = Object.entries(storageRaw).map(([k, v]) => ({
      label: humanizeFeeKey(k),
      value: formatFeeDisplay(
        v && typeof v === "object" && !Array.isArray(v) ? v.amount ?? v.value ?? v : v,
      ),
      id: null,
    }));
  }

  return {
    fulfillment: {
      firstPick: formatFeeDisplay(firstPick),
      additionalPicks: formatFeeDisplay(additionalPicks),
    },
    returns: {
      processing: formatFeeDisplay(processing),
      additionalItems: formatFeeDisplay(additionalItems),
    },
    storageRows,
  };
}

function humanizeFeeKey(k) {
  const s = String(k || "").replace(/_/g, " ").trim();
  if (!s) return "Storage fee";
  return s.charAt(0).toUpperCase() + s.slice(1);
}
