import { ref } from "vue";
import api from "../services/api";
import { useToast } from "./useToast";

export const SHOPIFY_ORDER_HOLD_REASONS = [
  { label: "Admin Hold", description: "Internal review required" },
  { label: "Address Hold", description: "Shipping address needs attention" },
  { label: "Payment Hold", description: "Payment verification required" },
  { label: "Client Hold", description: "Requested by the client" },
];

export const SHOPIFY_DISPLAY_STATUS_LABELS = {
  ready_to_ship: "Ready to Ship",
  on_hold: "On Hold",
  backorder: "Backorder",
  fulfilled: "Fulfilled",
  shipped: "Fulfilled",
  cancelled: "Cancelled",
};

export function formatShopifyOrderName(name) {
  return String(name || "").replace(/^#/, "").trim();
}

export function displayStatusLabel(status) {
  const key = String(status || "").trim();
  return SHOPIFY_DISPLAY_STATUS_LABELS[key] || key.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

export function displayStatusClass(status) {
  const key = String(status || "").trim();
  if (key === "ready_to_ship") return "shopify-order-status--ready";
  if (key === "on_hold") return "shopify-order-status--hold";
  if (key === "backorder") return "shopify-order-status--backorder";
  if (key === "fulfilled" || key === "shipped") return "shopify-order-status--shipped";
  if (key === "cancelled") return "shopify-order-status--cancelled";
  return "bg-secondary-subtle text-secondary-emphasis";
}

export function isFulfilledStatus(status) {
  const key = String(status || "").trim();
  return key === "fulfilled" || key === "shipped";
}

export function isCancelledStatus(status) {
  return String(status || "").trim() === "cancelled";
}

export function useShopifyOrderActions({ onUpdated } = {}) {
  const toast = useToast();
  const busy = ref(false);

  async function syncOrder(row) {
    if (!row?.id) return null;
    busy.value = true;
    try {
      const { data } = await api.post(`/shopify/orders/${row.id}/sync`);
      toast.success("Order synced from Shopify.");
      onUpdated?.(data?.order, row);
      return data?.order ?? null;
    } catch (e) {
      toast.errorFrom(e, "Could not sync order.");
      return null;
    } finally {
      busy.value = false;
    }
  }

  async function holdOrder(ids, reasons) {
    if (!ids?.length || !reasons?.length) return null;
    busy.value = true;
    try {
      if (ids.length === 1) {
        const { data } = await api.post(`/shopify/orders/${ids[0]}/hold`, { reasons });
        toast.success("Order placed on hold.");
        onUpdated?.(data?.order);
        return data?.order ?? null;
      }
      const { data } = await api.post("/shopify/orders/bulk/hold", { ids, reasons });
      const count = Array.isArray(data?.updated) ? data.updated.length : 0;
      if (count) toast.success(`${count} order${count === 1 ? "" : "s"} placed on hold.`);
      if (Array.isArray(data?.errors) && data.errors.length) {
        toast.error(data.errors[0]?.message || `${data.errors.length} order(s) could not be held.`);
      }
      onUpdated?.(data);
      return data;
    } catch (e) {
      toast.errorFrom(e, "Could not hold order(s).");
      return null;
    } finally {
      busy.value = false;
    }
  }

  async function cancelOrder(ids, cancelInShopify = false) {
    if (!ids?.length) return null;
    busy.value = true;
    try {
      const body = { cancel_in_shopify: Boolean(cancelInShopify) };
      if (ids.length === 1) {
        const { data } = await api.post(`/shopify/orders/${ids[0]}/cancel`, body);
        toast.success("Order canceled.");
        onUpdated?.(data?.order);
        return data?.order ?? null;
      }
      const { data } = await api.post("/shopify/orders/bulk/cancel", { ids, ...body });
      const count = Array.isArray(data?.updated) ? data.updated.length : 0;
      if (count) toast.success(`${count} order${count === 1 ? "" : "s"} canceled.`);
      if (Array.isArray(data?.errors) && data.errors.length) {
        toast.error(data.errors[0]?.message || `${data.errors.length} order(s) could not be canceled.`);
      }
      onUpdated?.(data);
      return data;
    } catch (e) {
      toast.errorFrom(e, "Could not cancel order(s).");
      return null;
    } finally {
      busy.value = false;
    }
  }

  async function fulfillOrder(ids, { trackingNumber = "", deductLineIds = null } = {}) {
    if (!ids?.length) return null;
    busy.value = true;
    try {
      if (ids.length === 1) {
        const body = {};
        if (trackingNumber) body.tracking_number = trackingNumber;
        if (Array.isArray(deductLineIds)) body.deduct_line_ids = deductLineIds;
        const { data } = await api.post(`/shopify/orders/${ids[0]}/fulfill-all`, body);
        toast.success("Order marked fulfilled.");
        onUpdated?.(data?.order);
        return data?.order ?? null;
      }
      const { data } = await api.post("/shopify/orders/bulk/fulfill", {
        ids,
        tracking_number: trackingNumber || undefined,
      });
      const count = Array.isArray(data?.updated) ? data.updated.length : 0;
      if (count) toast.success(`${count} order${count === 1 ? "" : "s"} marked fulfilled.`);
      if (Array.isArray(data?.errors) && data.errors.length) {
        toast.error(data.errors[0]?.message || `${data.errors.length} order(s) could not be fulfilled.`);
      }
      onUpdated?.(data);
      return data;
    } catch (e) {
      toast.errorFrom(e, "Could not mark fulfilled.");
      return null;
    } finally {
      busy.value = false;
    }
  }

  async function reshipOrder(id, lineItemIds) {
    if (!id || !lineItemIds?.length) return null;
    busy.value = true;
    try {
      const { data } = await api.post(`/shopify/orders/${id}/reship`, {
        line_item_ids: lineItemIds,
      });
      toast.success("Re-shipment created.");
      onUpdated?.(data?.order);
      return data?.order ?? null;
    } catch (e) {
      toast.errorFrom(e, "Could not create re-shipment.");
      return null;
    } finally {
      busy.value = false;
    }
  }

  async function reprocessOrder(ids) {
    if (!ids?.length) return null;
    busy.value = true;
    try {
      if (ids.length === 1) {
        const { data } = await api.post(`/shopify/orders/${ids[0]}/reprocess`);
        toast.success("Order reprocessed.");
        onUpdated?.(data?.order);
        return data?.order ?? null;
      }
      let ok = 0;
      const errors = [];
      for (const id of ids) {
        try {
          const { data } = await api.post(`/shopify/orders/${id}/reprocess`);
          onUpdated?.(data?.order);
          ok += 1;
        } catch (e) {
          errors.push(e);
        }
      }
      if (ok) toast.success(`${ok} order${ok === 1 ? "" : "s"} reprocessed.`);
      if (errors.length) toast.error(`${errors.length} order(s) could not be reprocessed.`);
      return { ok, errors };
    } catch (e) {
      toast.errorFrom(e, "Could not reprocess order(s).");
      return null;
    } finally {
      busy.value = false;
    }
  }

  async function applyDisplayStatus(ids, status, reasons = []) {
    if (!ids?.length || !status) return null;
    busy.value = true;
    try {
      if (ids.length === 1) {
        const { data } = await api.post(`/shopify/orders/${ids[0]}/display-status`, {
          status,
          reasons,
        });
        toast.success("Status updated.");
        onUpdated?.(data?.order);
        return data?.order ?? null;
      }
      const { data } = await api.post("/shopify/orders/bulk/display-status", {
        ids,
        status,
        reasons,
      });
      const count = Array.isArray(data?.updated) ? data.updated.length : 0;
      if (count) toast.success(`${count} order${count === 1 ? "" : "s"} updated.`);
      if (Array.isArray(data?.errors) && data.errors.length) {
        toast.error(data.errors[0]?.message || `${data.errors.length} order(s) could not be updated.`);
      }
      onUpdated?.(data);
      return data;
    } catch (e) {
      toast.errorFrom(e, "Could not update status.");
      return null;
    } finally {
      busy.value = false;
    }
  }

  function viewInShopify(row) {
    const url = row?.shopify_admin_url;
    if (!url) {
      toast.error("Shopify admin URL unavailable.");
      return;
    }
    window.open(url, "_blank", "noopener,noreferrer");
  }

  async function viewPackingSlip(row) {
    if (!row?.id) return;
    try {
      const { data } = await api.get(`/shopify/orders/${row.id}/packing-slip.pdf`, {
        responseType: "blob",
      });
      const blob = new Blob([data], { type: "application/pdf" });
      const url = window.URL.createObjectURL(blob);
      window.open(url, "_blank", "noopener");
      setTimeout(() => window.URL.revokeObjectURL(url), 30000);
    } catch (e) {
      toast.errorFrom(e, "Could not open packing slip.");
    }
  }

  return {
    busy,
    syncOrder,
    holdOrder,
    cancelOrder,
    fulfillOrder,
    reshipOrder,
    reprocessOrder,
    applyDisplayStatus,
    viewInShopify,
    viewPackingSlip,
  };
}
