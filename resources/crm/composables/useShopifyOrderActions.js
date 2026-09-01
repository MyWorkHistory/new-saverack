import { ref } from "vue";
import api from "../services/api";
import { useToast } from "./useToast";

export const SHOPIFY_ORDER_HOLD_REASONS = [
  "Admin Hold",
  "Address Hold",
  "Payment Hold",
  "Client Hold",
];

export const SHOPIFY_DISPLAY_STATUS_LABELS = {
  ready_to_ship: "Ready to Ship",
  on_hold: "On Hold",
  backorder: "Backorder",
  shipped: "Shipped",
};

export function displayStatusLabel(status) {
  const key = String(status || "").trim();
  return SHOPIFY_DISPLAY_STATUS_LABELS[key] || key.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

export function displayStatusClass(status) {
  const key = String(status || "").trim();
  if (key === "ready_to_ship") return "shopify-order-status--ready";
  if (key === "on_hold") return "shopify-order-status--hold";
  if (key === "backorder") return "shopify-order-status--backorder";
  if (key === "shipped") return "shopify-order-status--shipped";
  return "bg-secondary-subtle text-secondary-emphasis";
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
        toast.error(`${data.errors.length} order(s) could not be held.`);
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

  async function cancelOrder(ids) {
    if (!ids?.length) return null;
    busy.value = true;
    try {
      if (ids.length === 1) {
        const { data } = await api.post(`/shopify/orders/${ids[0]}/cancel`);
        toast.success("Order canceled.");
        onUpdated?.(data?.order);
        return data?.order ?? null;
      }
      const { data } = await api.post("/shopify/orders/bulk/cancel", { ids });
      const count = Array.isArray(data?.updated) ? data.updated.length : 0;
      if (count) toast.success(`${count} order${count === 1 ? "" : "s"} canceled.`);
      if (Array.isArray(data?.errors) && data.errors.length) {
        toast.error(`${data.errors.length} order(s) could not be canceled.`);
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

  async function fulfillOrder(ids) {
    if (!ids?.length) return null;
    busy.value = true;
    try {
      if (ids.length === 1) {
        const { data } = await api.post(`/shopify/orders/${ids[0]}/fulfill-all`);
        toast.success("Order marked fulfilled.");
        onUpdated?.(data?.order);
        return data?.order ?? null;
      }
      const { data } = await api.post("/shopify/orders/bulk/fulfill", { ids });
      const count = Array.isArray(data?.updated) ? data.updated.length : 0;
      if (count) toast.success(`${count} order${count === 1 ? "" : "s"} marked fulfilled.`);
      if (Array.isArray(data?.errors) && data.errors.length) {
        toast.error(`${data.errors.length} order(s) could not be fulfilled.`);
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

  function stubAction(label) {
    toast.warning(`${label} is coming soon.`);
  }

  return {
    busy,
    syncOrder,
    holdOrder,
    cancelOrder,
    fulfillOrder,
    viewInShopify,
    viewPackingSlip,
    stubAction,
  };
}
