import { computed, inject, ref } from "vue";
import { useRoute } from "vue-router";
import api from "../services/api";
import { useToast } from "./useToast.js";
import { cubicFeetFromDimensions, formatCubicFeetDisplay } from "../utils/inventoryStorage.js";

const METRIC_CARD_DEFS = [
  { key: "on_hand", label: "On Hand", iconPath: "M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z M12 12l9-4.5 M12 12 3 7.5 M12 12v9", tone: "blue" },
  { key: "allocated", label: "Allocated", iconPath: "M4 8h16M4 12h16M4 16h16M7 5h10", tone: "amber" },
  { key: "available", label: "Available", iconPath: "M4 12l5 5 11-11", tone: "green" },
  { key: "backorder", label: "Backorder", iconPath: "M12 7v6 M12 17h.01 M3 12a9 9 0 1 0 18 0 9 9 0 1 0-18 0", tone: "red" },
  { key: "asn", label: "ASN", iconPath: "M2 13h11l2-3h7v7h-2 M6 17a2 2 0 1 0 0 .01 M18 17a2 2 0 1 0 0 .01", tone: "purple" },
];

export function useInventoryProductDetail() {
  const route = useRoute();
  const toast = useToast();
  const crmUser = inject("crmUser", ref(null));

  const isPortalView = computed(() => Boolean(route.meta.userPortal));
  const canManageInventoryLocations = computed(() => !isPortalView.value);

  const loading = ref(true);
  const saving = ref(false);
  const product = ref(null);
  const errorMessage = ref("");

  const allocatedOrders = ref([]);
  const allocatedOrdersLoading = ref(false);
  const allocatedOrdersLoaded = ref(false);
  const backorderOrders = ref([]);
  const backorderOrdersLoading = ref(false);
  const backorderOrdersLoaded = ref(false);

  const summaryMetrics = computed(() => product.value?.metrics || {
    on_hand: 0,
    allocated: 0,
    available: 0,
    backorder: 0,
    asn: 0,
  });

  const metricCards = computed(() =>
    METRIC_CARD_DEFS.map((item) => ({
      ...item,
      value: Number(summaryMetrics.value?.[item.key] || 0),
    })),
  );

  const cubicFeetDisplay = computed(() => formatCubicFeetDisplay(product.value?.dimensions));

  const showKitSection = computed(
    () => Boolean(product.value?.kit) || Boolean(product.value?.kit_build),
  );

  const kitComponents = computed(() => {
    const list = product.value?.kit_components;
    return Array.isArray(list) ? list : [];
  });

  const portalClientAccountId = computed(() => {
    const portalAccountId = Number(crmUser.value?.client_account_id || 0);
    const queryAccountId = Number(route.query.client_account_id || 0);
    return isPortalView.value ? portalAccountId || queryAccountId : queryAccountId || portalAccountId;
  });

  function requestParams() {
    const params = {};
    const clientAccountId = portalClientAccountId.value;
    if (clientAccountId > 0) params.client_account_id = clientAccountId;
    if (route.query.warehouse_id) params.warehouse_id = String(route.query.warehouse_id);
    return params;
  }

  function displayVal(v) {
    if (v === null || v === undefined) return "—";
    if (typeof v === "string" && v.trim() === "") return "—";
    return v;
  }

  function displayNumber(v) {
    if (v === null || v === undefined) return 0;
    if (typeof v === "string" && v.trim() === "") return 0;
    const n = Number(v);
    if (Number.isNaN(n)) return 0;
    return n;
  }

  async function loadDetail() {
    loading.value = true;
    errorMessage.value = "";
    allocatedOrdersLoaded.value = false;
    backorderOrdersLoaded.value = false;
    allocatedOrders.value = [];
    backorderOrders.value = [];
    try {
      const sku = String(route.params.sku || "").trim();
      const { data } = await api.get(`/inventory/products/${encodeURIComponent(sku)}`, {
        params: requestParams(),
      });
      product.value = data?.product ?? null;
    } catch (e) {
      errorMessage.value = e.response?.data?.message || "Could not load inventory detail.";
      toast.errorFrom(e, "Could not load inventory detail.");
    } finally {
      loading.value = false;
    }
  }

  async function openPdf(path, fallbackMessage) {
    try {
      const { data } = await api.get(path, {
        params: requestParams(),
        responseType: "blob",
      });
      const blob = new Blob([data], { type: "application/pdf" });
      const url = window.URL.createObjectURL(blob);
      window.open(url, "_blank", "noopener");
      setTimeout(() => window.URL.revokeObjectURL(url), 30000);
    } catch (e) {
      toast.errorFrom(e, fallbackMessage);
    }
  }

  function openBarcodeLabelPdf() {
    const sku = String(product.value?.sku || route.params.sku || "").trim();
    if (!sku) return;
    openPdf(`/inventory/products/${encodeURIComponent(sku)}/barcode-label.pdf`, "Could not open barcode label PDF.");
  }

  async function loadAllocatedOrders() {
    const sku = String(product.value?.sku || route.params.sku || "").trim();
    if (!sku || allocatedOrdersLoading.value) return;
    allocatedOrdersLoading.value = true;
    try {
      const { data } = await api.get(`/inventory/products/${encodeURIComponent(sku)}/allocated-orders`, {
        params: requestParams(),
      });
      allocatedOrders.value = Array.isArray(data?.rows) ? data.rows : [];
      allocatedOrdersLoaded.value = true;
    } catch (e) {
      toast.errorFrom(e, "Could not load allocated orders.");
    } finally {
      allocatedOrdersLoading.value = false;
    }
  }

  async function loadBackorderOrders() {
    const sku = String(product.value?.sku || route.params.sku || "").trim();
    if (!sku || backorderOrdersLoading.value) return;
    backorderOrdersLoading.value = true;
    try {
      const { data } = await api.get(`/inventory/products/${encodeURIComponent(sku)}/backorder-orders`, {
        params: requestParams(),
      });
      backorderOrders.value = Array.isArray(data?.rows) ? data.rows : [];
      backorderOrdersLoaded.value = true;
    } catch (e) {
      toast.errorFrom(e, "Could not load backorder orders.");
    } finally {
      backorderOrdersLoading.value = false;
    }
  }

  function formatOrderDate(iso) {
    if (!iso) return "—";
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return "—";
    return d.toLocaleDateString();
  }

  function portalOrderDetailHref(row) {
    const orderId = String(row?.order_id || "").trim();
    if (!orderId) return null;
    const accountId = portalClientAccountId.value;
    const query = accountId > 0 ? { client_account_id: String(accountId) } : {};
    return {
      name: "user-order-detail",
      params: { shipheroOrderId: orderId },
      query,
    };
  }

  return {
    route,
    isPortalView,
    canManageInventoryLocations,
    loading,
    saving,
    product,
    errorMessage,
    metricCards,
    cubicFeetDisplay,
    showKitSection,
    kitComponents,
    portalClientAccountId,
    allocatedOrders,
    allocatedOrdersLoading,
    allocatedOrdersLoaded,
    backorderOrders,
    backorderOrdersLoading,
    backorderOrdersLoaded,
    displayVal,
    displayNumber,
    loadDetail,
    openBarcodeLabelPdf,
    loadAllocatedOrders,
    loadBackorderOrders,
    formatOrderDate,
    portalOrderDetailHref,
    requestParams,
  };
}
