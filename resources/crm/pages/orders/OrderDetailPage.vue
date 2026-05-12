<script setup>
import { computed, inject, onMounted, onUnmounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin } from "../../utils/crmUser";

const crmUser = inject("crmUser", ref(null));
const route = useRoute();
const router = useRouter();
const toast = useToast();

const loading = ref(false);
const order = ref(null);
const accounts = ref([]);
const accountsLoading = ref(false);
const selectedAccountId = ref(String(route.query.client_account_id || ""));
const loadError = ref("");
const loadNotice = ref("");
const activeLoadKey = ref("");
const itemSortKey = ref("name");
const itemSortDir = ref("asc");
const confirmFulfilledOpen = ref(false);
const confirmCancelOpen = ref(false);
const actionBusy = ref(false);

const shippingModalOpen = ref(false);
const shippingSaveBusy = ref(false);
const shippingForm = ref({
  first_name: "",
  last_name: "",
  company: "",
  address1: "",
  address2: "",
  phone: "",
  city: "",
  state: "",
  country: "",
  zip: "",
  email: "",
});

const carrierField = ref("");
const methodField = ref("");
const shippingLinesSaveBusy = ref(false);

const allowPartialLocal = ref(false);
const allowPartialSaveBusy = ref(false);

const tagsLocal = ref([]);
const tagInputValue = ref("");
const tagsSaveBusy = ref(false);

const addItemsModalOpen = ref(false);
const addItemForm = ref({ sku: "", quantity: 1, product_name: "" });
const addItemsBusy = ref(false);

const attachmentFileInput = ref(null);
const attachmentUploadBusy = ref(false);

const orderId = computed(() => String(route.params.shipheroOrderId || ""));

const isPortalUser = computed(() => Number(crmUser.value?.client_account_id || 0) > 0);
const portalClientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));

const accountOptions = computed(() =>
  (accounts.value || [])
    .filter((a) => !isPortalUser.value || Number(a?.id || 0) === portalClientAccountId.value)
    .filter((a) => a?.has_shiphero_customer)
    .map((a) => ({
      id: a.id,
      name: a.company_name || `Account #${a.id}`,
      email: a.email ? String(a.email) : "",
    })),
);

const headingOrderNumber = computed(() => String(order.value?.order_number || "—").replace(/^#\s*/, ""));
const statusClass = computed(() => {
  const raw = String(order.value?.status || "").toLowerCase();
  if (raw.includes("hold") || raw.includes("backorder")) return "text-danger bg-danger-subtle";
  if (raw.includes("ship")) return "text-success bg-success-subtle";
  return "text-secondary bg-secondary-subtle";
});

function userCanInventoryUpdate() {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes("inventory.update");
}

const canRunShipHeroActions = computed(() => !isPortalUser.value && userCanInventoryUpdate());

function orderHasActiveHold(o) {
  if (!o || typeof o !== "object") return false;
  if (o.has_active_hold === true) return true;
  const h = o.holds;
  if (h && typeof h === "object") {
    return Object.values(h).some((v) => v === true);
  }
  return false;
}

const showNotReadyToShipBanner = computed(() => order.value && orderHasActiveHold(order.value));

const orderHeaderBadgeLabel = computed(() => {
  if (showNotReadyToShipBanner.value) return "Not Ready To Ship";
  return order.value?.status || "—";
});

const orderHeaderBadgeClass = computed(() => {
  if (showNotReadyToShipBanner.value) {
    return "badge rounded-pill fw-medium text-danger-emphasis bg-danger-subtle";
  }
  return `badge rounded-pill fw-medium ${statusClass.value}`;
});

const notReadyBannerBody = computed(() => {
  const o = order.value;
  if (!o) return "";
  const sub = String(o.not_ready_subtitle || "").trim();
  if (sub) return sub;
  const hr = String(o.hold_reason || "").trim();
  if (hr) return `Order has ${hr.toLowerCase()}.`;
  return "Order has a hold.";
});
const sortedItems = computed(() => {
  const rows = Array.isArray(order.value?.items) ? [...order.value.items] : [];
  const dir = itemSortDir.value === "desc" ? -1 : 1;
  rows.sort((a, b) => {
    const av = a?.[itemSortKey.value];
    const bv = b?.[itemSortKey.value];
    if (typeof av === "number" || typeof bv === "number") {
      const na = Number(av ?? 0);
      const nb = Number(bv ?? 0);
      return (na - nb) * dir;
    }
    const sa = String(av ?? "").toLowerCase();
    const sb = String(bv ?? "").toLowerCase();
    return sa.localeCompare(sb) * dir;
  });
  return rows;
});
const formattedShippingAddress = computed(() => {
  const a = order.value?.shipping_address;
  if (!a || typeof a !== "object") return "";
  const parts = [];
  const name = [a.first_name, a.last_name].filter(Boolean).join(" ").trim();
  if (name) parts.push(name);
  if (a.company) parts.push(String(a.company));
  const line1 = [a.address1, a.address2].filter(Boolean).join(", ").trim();
  if (line1) parts.push(line1);
  const cityLine = [a.city, a.state, a.zip].filter(Boolean).join(", ").trim();
  if (cityLine) parts.push(cityLine);
  if (a.country) parts.push(String(a.country));
  return parts.length ? parts.join("\n") : "—";
});

function fmtDate(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleString();
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function sanitizeHistoryHtml(value) {
  const raw = String(value || "");
  if (!raw.trim()) return "—";
  if (typeof window === "undefined" || typeof DOMParser === "undefined") {
    return escapeHtml(raw);
  }

  const allowedTags = new Set(["P", "UL", "OL", "LI", "BR", "STRONG", "EM", "B", "I"]);
  const parser = new DOMParser();
  const doc = parser.parseFromString(raw, "text/html");
  const nodes = [doc.body];

  while (nodes.length > 0) {
    const current = nodes.pop();
    if (!current || !current.childNodes) continue;
    const children = Array.from(current.childNodes);
    for (const child of children) {
      if (child.nodeType === Node.ELEMENT_NODE) {
        const el = child;
        const tag = el.tagName.toUpperCase();
        if (!allowedTags.has(tag)) {
          const replacement = doc.createTextNode(el.textContent || "");
          el.replaceWith(replacement);
          continue;
        }
        Array.from(el.attributes || []).forEach((attr) => {
          el.removeAttribute(attr.name);
        });
        nodes.push(el);
      }
    }
  }

  const cleaned = (doc.body.innerHTML || "").trim();
  return cleaned !== "" ? cleaned : escapeHtml(raw);
}

function toggleItemSort(key) {
  if (itemSortKey.value === key) {
    itemSortDir.value = itemSortDir.value === "asc" ? "desc" : "asc";
    return;
  }
  itemSortKey.value = key;
  itemSortDir.value = "asc";
}

function sortIndicator(key) {
  if (itemSortKey.value !== key) return "↕";
  return itemSortDir.value === "asc" ? "↑" : "↓";
}

function extractErrorMessage(e) {
  const payload = e?.response?.data;
  if (payload && typeof payload === "object") {
    const title = typeof payload.title === "string" ? payload.title.trim() : "";
    const detail = typeof payload.detail === "string" ? payload.detail.trim() : "";
    const ownerAction =
      typeof payload.what_you_should_do === "string"
        ? payload.what_you_should_do.replace(/\*\*/g, "").trim()
        : "";
    if (title && ownerAction) return `${title} ${ownerAction}`;
    if (title && detail) return `${title} ${detail}`;
    if (title) return title;
    if (detail) return detail;
    if (ownerAction) return ownerAction;
  }
  const msg = e?.response?.data?.message;
  if (typeof msg === "string" && msg.trim() !== "") return msg;
  if (e?.message) return String(e.message);
  return "Could not load order details.";
}

function fallbackOrderSnapshot() {
  if (!selectedAccountId.value || !orderId.value) return null;
  const key = `orders.snapshot.${selectedAccountId.value}.${orderId.value}`;
  try {
    const raw = sessionStorage.getItem(key);
    if (!raw) return null;
    const row = JSON.parse(raw);
    if (!row || typeof row !== "object") return null;
    return {
      id: String(row.id || orderId.value),
      legacy_id: row.legacy_id ?? null,
      order_number: row.order_number || "",
      partner_order_id: "",
      status: row.status || "",
      hold_reason: row.hold_reason || null,
      holds: {
        fraud_hold: false,
        address_hold: false,
        shipping_method_hold: false,
        operator_hold: false,
        payment_hold: false,
        client_hold: false,
      },
      has_active_hold: !!(row.hold_reason && String(row.hold_reason).trim() !== ""),
      not_ready_subtitle: "",
      order_date: row.order_date || null,
      required_ship_date: null,
      account: row.account || "",
      email: row.email || "",
      shipping_carrier: row.shipping_carrier || "",
      method: row.method || "",
      shipping_cost: null,
      subtotal: null,
      total_tax: null,
      total_discounts: null,
      total_price: null,
      gift_invoice: false,
      allow_partial: false,
      require_signature: false,
      packing_note: null,
      tags: [],
      attachments: [],
      shipping_line: {
        title: "Shipping",
        carrier: row.shipping_carrier || "",
        method: row.method || "",
        price: "0",
      },
      shipping_address: {
        first_name: "",
        last_name: "",
        company: "",
        address1: "",
        address2: "",
        city: "",
        state: "",
        state_code: "",
        zip: "",
        country: row.country || "",
        country_code: "",
        email: "",
        phone: "",
      },
      billing_address: {},
      items: [],
      history: [],
    };
  } catch (_) {
    return null;
  }
}

async function loadAccounts() {
  accountsLoading.value = true;
  try {
    const { data } = await api.get("/inventory/client-account-options");
    accounts.value = Array.isArray(data?.accounts) ? data.accounts : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load account list.");
  } finally {
    accountsLoading.value = false;
  }
}

async function loadOrder() {
  loadError.value = "";
  loadNotice.value = "";
  if (!selectedAccountId.value || !orderId.value) {
    order.value = null;
    return;
  }
  const requestKey = `${selectedAccountId.value}:${orderId.value}`;
  if (loading.value && activeLoadKey.value === requestKey) {
    return;
  }
  activeLoadKey.value = requestKey;
  loading.value = true;
  order.value = null;
  try {
    const { data } = await api.get(`/orders/${encodeURIComponent(orderId.value)}`, {
      params: { client_account_id: Number(selectedAccountId.value) },
    });
    order.value = data?.order ?? null;
    if (data?.fallback?.source) {
      loadNotice.value = "Live detail endpoint was temporarily unavailable. Showing summary data from orders list.";
    }
    if (!order.value) {
      loadError.value = "ShipHero returned no order for this id and account.";
      toast.error("Order not found.");
    }
  } catch (e) {
    const cached = fallbackOrderSnapshot();
    if (cached) {
      order.value = cached;
      loadNotice.value = "Live detail endpoint was temporarily unavailable. Showing cached summary from this browser.";
    } else {
      loadError.value = extractErrorMessage(e);
      order.value = null;
    }
    toast.errorFrom(e, "Could not load order details.");
  } finally {
    loading.value = false;
    if (activeLoadKey.value === requestKey) {
      activeLoadKey.value = "";
    }
  }
}

watch(
  () => route.query.client_account_id,
  (q) => {
    const next = q != null && String(q) !== "" ? String(q) : "";
    if (next !== selectedAccountId.value) {
      selectedAccountId.value = next;
    }
  },
);

watch(selectedAccountId, (id) => {
  const current = route.query.client_account_id != null ? String(route.query.client_account_id) : "";
  if (id === current) return;
  const nextQuery = { ...route.query };
  if (id) {
    nextQuery.client_account_id = id;
  } else {
    delete nextQuery.client_account_id;
  }
  router.replace({ query: nextQuery });
});

watch(
  () => [orderId.value, selectedAccountId.value],
  () => {
    if (selectedAccountId.value && orderId.value) {
      loadOrder();
    } else {
      order.value = null;
      loadError.value = "";
    }
  },
  { immediate: true },
);

function closeActionConfirms() {
  confirmFulfilledOpen.value = false;
  confirmCancelOpen.value = false;
}

async function runMarkFulfilled() {
  if (!order.value || !selectedAccountId.value || !orderId.value) return;
  actionBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/mark-fulfilled`, {
      client_account_id: Number(selectedAccountId.value),
    });
    toast.success("Order marked fulfilled.");
    closeActionConfirms();
    await loadOrder();
  } catch (e) {
    toast.errorFrom(e, "Could not mark order fulfilled.");
  } finally {
    actionBusy.value = false;
  }
}

async function runCancelOrder() {
  if (!order.value || !selectedAccountId.value || !orderId.value) return;
  actionBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/cancel`, {
      client_account_id: Number(selectedAccountId.value),
    });
    toast.success("Order canceled.");
    closeActionConfirms();
    await loadOrder();
  } catch (e) {
    toast.errorFrom(e, "Could not cancel order.");
  } finally {
    actionBusy.value = false;
  }
}

watch(
  () => order.value,
  (o) => {
    if (!o || typeof o !== "object") return;
    allowPartialLocal.value = !!o.allow_partial;
    tagsLocal.value = Array.isArray(o.tags) ? [...o.tags] : [];
    carrierField.value = String(o.shipping_carrier || "");
    methodField.value = String(o.method || "");
  },
  { immediate: true, deep: true },
);

function openShippingModal() {
  const a = order.value?.shipping_address;
  const src = a && typeof a === "object" ? a : {};
  shippingForm.value = {
    first_name: String(src.first_name || ""),
    last_name: String(src.last_name || ""),
    company: String(src.company || ""),
    address1: String(src.address1 || ""),
    address2: String(src.address2 || ""),
    phone: String(src.phone || ""),
    city: String(src.city || ""),
    state: String(src.state || ""),
    country: String(src.country || ""),
    zip: String(src.zip || ""),
    email: String(src.email || order.value?.email || ""),
  };
  shippingModalOpen.value = true;
}

function closeShippingModal() {
  if (shippingSaveBusy.value) return;
  shippingModalOpen.value = false;
}

async function saveShippingAddress() {
  if (!selectedAccountId.value || !orderId.value) return;
  shippingSaveBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/shipping-address`, {
      client_account_id: Number(selectedAccountId.value),
      ...shippingForm.value,
    });
    toast.success("Shipping address updated.");
    shippingModalOpen.value = false;
    await loadOrder();
  } catch (e) {
    toast.errorFrom(e, "Could not update shipping address.");
  } finally {
    shippingSaveBusy.value = false;
  }
}

async function saveShippingLines() {
  if (!selectedAccountId.value || !orderId.value) return;
  shippingLinesSaveBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/shipping-lines`, {
      client_account_id: Number(selectedAccountId.value),
      carrier: carrierField.value,
      method: methodField.value,
    });
    toast.success("Shipping carrier and method updated.");
    await loadOrder();
  } catch (e) {
    toast.errorFrom(e, "Could not update shipping lines.");
  } finally {
    shippingLinesSaveBusy.value = false;
  }
}

async function onAllowPartialChange() {
  if (!order.value || !selectedAccountId.value || !orderId.value) return;
  const prev = !!order.value.allow_partial;
  allowPartialSaveBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/allow-partial`, {
      client_account_id: Number(selectedAccountId.value),
      allow_partial: allowPartialLocal.value,
    });
    toast.success("Allow partial updated.");
    await loadOrder();
  } catch (e) {
    allowPartialLocal.value = prev;
    toast.errorFrom(e, "Could not update allow partial.");
  } finally {
    allowPartialSaveBusy.value = false;
  }
}

function addTagFromInput() {
  const chunks = String(tagInputValue.value || "")
    .split(/[,\n]/)
    .map((t) => t.trim())
    .filter(Boolean);
  if (!chunks.length) return;
  const merged = [...tagsLocal.value];
  for (const t of chunks) {
    if (!merged.includes(t)) merged.push(t);
  }
  tagsLocal.value = merged;
  tagInputValue.value = "";
}

function onTagInputKeydown(e) {
  if (e.key === "Enter" || e.key === ",") {
    e.preventDefault();
    addTagFromInput();
  }
}

function removeTag(idx) {
  tagsLocal.value = tagsLocal.value.filter((_, i) => i !== idx);
}

async function saveTags() {
  if (!selectedAccountId.value || !orderId.value) return;
  tagsSaveBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/tags`, {
      client_account_id: Number(selectedAccountId.value),
      tags: tagsLocal.value,
    });
    toast.success("Order tags updated.");
    await loadOrder();
  } catch (e) {
    toast.errorFrom(e, "Could not update tags.");
  } finally {
    tagsSaveBusy.value = false;
  }
}

function openAddItemsModal() {
  addItemForm.value = { sku: "", quantity: 1, product_name: "" };
  addItemsModalOpen.value = true;
}

function closeAddItemsModal() {
  if (addItemsBusy.value) return;
  addItemsModalOpen.value = false;
}

async function submitAddItems() {
  const sku = String(addItemForm.value.sku || "").trim();
  const qty = Math.max(1, parseInt(String(addItemForm.value.quantity || 1), 10) || 1);
  if (!sku) {
    toast.error("Enter a SKU.");
    return;
  }
  if (!selectedAccountId.value || !orderId.value) return;
  addItemsBusy.value = true;
  try {
    const name = String(addItemForm.value.product_name || "").trim();
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/line-items`, {
      client_account_id: Number(selectedAccountId.value),
      line_items: [
        {
          sku,
          quantity: qty,
          ...(name ? { product_name: name } : {}),
        },
      ],
    });
    toast.success("Items added.");
    addItemsModalOpen.value = false;
    addItemForm.value = { sku: "", quantity: 1, product_name: "" };
    await loadOrder();
  } catch (e) {
    toast.errorFrom(e, "Could not add items.");
  } finally {
    addItemsBusy.value = false;
  }
}

function triggerAttachFile() {
  if (!canRunShipHeroActions.value || attachmentUploadBusy.value) return;
  attachmentFileInput.value?.click?.();
}

async function onAttachmentFileChange(ev) {
  const input = ev.target;
  const file = input?.files?.[0];
  if (!file || !selectedAccountId.value || !orderId.value) return;
  const fd = new FormData();
  fd.append("client_account_id", String(Number(selectedAccountId.value)));
  fd.append("file", file);
  attachmentUploadBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/attachments`, fd);
    toast.success("Attachment added.");
    input.value = "";
    await loadOrder();
  } catch (e) {
    toast.errorFrom(e, "Could not upload attachment.");
  } finally {
    attachmentUploadBusy.value = false;
  }
}

function modalEscHandler(e) {
  if (e.key !== "Escape") return;
  if (shippingSaveBusy.value || addItemsBusy.value) return;
  if (shippingModalOpen.value) shippingModalOpen.value = false;
  if (addItemsModalOpen.value) addItemsModalOpen.value = false;
}

watch([shippingModalOpen, addItemsModalOpen], ([s, a]) => {
  if (s || a) {
    document.addEventListener("keydown", modalEscHandler);
  } else {
    document.removeEventListener("keydown", modalEscHandler);
  }
});

onUnmounted(() => {
  document.removeEventListener("keydown", modalEscHandler);
});

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Order Detail",
    description: "ShipHero order detail.",
  });
  await loadAccounts();
  if (isPortalUser.value && portalClientAccountId.value > 0 && !selectedAccountId.value) {
    selectedAccountId.value = String(portalClientAccountId.value);
  }
});
</script>

<template>
  <div class="staff-page staff-page--wide order-detail-page">
    <div v-if="loading" class="order-detail-page__fullscreen-loading">
      <CrmLoadingSpinner message="Loading order detail..." :center="true" />
    </div>
    <template v-else>
    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100 mb-4">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
          <div class="flex-grow-1" style="min-width: 280px">
            <label class="form-label small text-secondary mb-1" for="order-detail-account-trigger">Account</label>
            <CrmSearchableSelect
              v-model="selectedAccountId"
              class="staff-toolbar-search staff-toolbar-search--inline"
              appearance="staff"
              aria-label="Client account"
              :options="accountOptions"
              :disabled="accountsLoading || loading"
              placeholder="Select account"
              search-placeholder="Search accounts…"
              :allow-empty="true"
              empty-label="Select account"
              button-id="order-detail-account-trigger"
            />
          </div>
          <button
            type="button"
            class="btn btn-outline-secondary staff-toolbar-btn align-self-end"
            :disabled="!selectedAccountId || !orderId || loading"
            @click="loadOrder"
          >
            Refresh
          </button>
        </div>
        <p class="small text-secondary mb-0 mt-2">
          Only accounts with a ShipHero customer ID can load orders.
        </p>
      </div>
    </div>

    <div v-if="!selectedAccountId" class="alert alert-light border mb-0">
      Select an account above to view this order.
    </div>
    <div v-else-if="loadError" class="alert alert-warning small mb-0" role="alert">
      {{ loadError }}
    </div>
    <div v-else-if="!order" class="alert alert-warning mb-0">
      No order data loaded. Choose another account or use Refresh.
    </div>
    <template v-else>
      <div class="order-detail-page__hero mb-3">
        <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
          <div>
            <button type="button" class="btn btn-link px-0 text-decoration-none" @click="router.back()">
              ← Orders
            </button>
            <h1 class="h4 mb-1 fw-semibold text-body">Order {{ headingOrderNumber }}</h1>
            <p class="staff-page__intro mb-0">
              <span :class="orderHeaderBadgeClass">{{ orderHeaderBadgeLabel }}</span>
            </p>
          </div>
          <div v-if="canRunShipHeroActions" class="dropdown align-self-start">
            <button
              id="order-detail-action-menu"
              type="button"
              class="btn btn-outline-secondary dropdown-toggle"
              data-bs-toggle="dropdown"
              aria-expanded="false"
            >
              Action
            </button>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="order-detail-action-menu">
              <li>
                <button type="button" class="dropdown-item" @click="confirmFulfilledOpen = true">
                  Mark As Fulfilled
                </button>
              </li>
              <li>
                <button type="button" class="dropdown-item text-danger" @click="confirmCancelOpen = true">
                  Cancel Order
                </button>
              </li>
            </ul>
          </div>
        </div>
        <div
          v-if="showNotReadyToShipBanner"
          class="alert alert-warning border-warning mt-3 mb-0 d-flex gap-2 align-items-start"
          role="status"
        >
          <span class="text-warning-emphasis" aria-hidden="true">
            <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
              <path
                d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"
              />
            </svg>
          </span>
          <div>
            <div class="fw-semibold">This order is not ready to ship</div>
            <div class="small mb-0">{{ notReadyBannerBody }}</div>
          </div>
        </div>
      </div>

      <div v-if="loadNotice" class="alert alert-warning small mb-4" role="status">
        {{ loadNotice }}
      </div>
      <div class="row g-4">
        <div class="col-lg-8">
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 mb-4">
            <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
              <h2 class="h6 mb-0 fw-semibold">Items</h2>
              <div class="d-flex align-items-center gap-3">
                <button
                  v-if="canRunShipHeroActions"
                  type="button"
                  class="btn btn-link btn-sm text-decoration-none px-0 fw-semibold"
                  :disabled="!order || loading"
                  @click="openAddItemsModal"
                >
                  Add Items
                </button>
                <span class="small text-secondary">{{ order.items?.length || 0 }} items</span>
              </div>
            </div>
            <div class="table-responsive staff-table-wrap">
              <table class="table table-hover align-middle mb-0 staff-data-table">
                <thead class="table-light staff-table-head">
                  <tr>
                    <th class="staff-table-head__th order-detail-page__items-col">
                      <button class="order-detail-page__sort-btn" type="button" @click="toggleItemSort('name')">
                        Items <span class="order-detail-page__sort-icon">{{ sortIndicator("name") }}</span>
                      </button>
                    </th>
                    <th class="staff-table-head__th text-end">
                      <button class="order-detail-page__sort-btn order-detail-page__sort-btn--right" type="button" @click="toggleItemSort('quantity')">
                        Quantity <span class="order-detail-page__sort-icon">{{ sortIndicator("quantity") }}</span>
                      </button>
                    </th>
                    <th class="staff-table-head__th text-end">
                      <button class="order-detail-page__sort-btn order-detail-page__sort-btn--right" type="button" @click="toggleItemSort('quantity_pending_fulfillment')">
                        Quantity to ship <span class="order-detail-page__sort-icon">{{ sortIndicator("quantity_pending_fulfillment") }}</span>
                      </button>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in sortedItems" :key="item.id || item.sku">
                    <td class="order-detail-page__items-col">
                      <div class="order-detail-page__item-cell">
                        <img
                          v-if="item.image_url"
                          :src="item.image_url"
                          alt=""
                          class="order-detail-page__item-thumb"
                          loading="lazy"
                        />
                        <div v-else class="order-detail-page__item-thumb order-detail-page__item-thumb--empty" aria-hidden="true"></div>
                        <div class="order-detail-page__item-copy">
                          <div
                            class="order-detail-page__item-name"
                            :title="item.name ? String(item.name) : undefined"
                          >
                            {{ item.name || "—" }}
                          </div>
                          <div
                            class="order-detail-page__item-sku"
                            :title="item.sku ? `SKU ${item.sku}` : undefined"
                          >
                            SKU {{ item.sku || "—" }}
                          </div>
                        </div>
                      </div>
                    </td>
                    <td class="text-end">{{ item.quantity ?? 0 }}</td>
                    <td class="text-end">
                      <div>{{ item.quantity_pending_fulfillment ?? 0 }}</div>
                      <div
                        v-if="Number(item.backorder_quantity || 0) > 0"
                        class="order-detail-page__backorder"
                      >
                        {{ Number(item.backorder_quantity) }} on backorder
                      </div>
                    </td>
                  </tr>
                  <tr v-if="!sortedItems.length">
                    <td colspan="3" class="text-center text-secondary py-4">No items</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p class="staff-table-mobile-scroll-cue d-md-none" aria-hidden="true">
              Scroll sideways or swipe to see all columns.
            </p>
          </div>

          <!-- History intentionally not shown for speed. -->
        </div>

        <div class="col-lg-4 d-flex flex-column gap-4">
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel">
            <h3 class="h6 fw-semibold mb-3">Order details</h3>
            <dl class="small mb-0">
              <dt class="text-secondary">Order date</dt>
              <dd>{{ fmtDate(order.order_date) }}</dd>
              <dt class="text-secondary">Email</dt>
              <dd>{{ order.email || "—" }}</dd>
            </dl>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel">
            <h3 class="h6 fw-semibold mb-3">Shipping Details</h3>
            <div class="mb-3">
              <div class="text-secondary small mb-1">Shipping address</div>
              <button
                v-if="canRunShipHeroActions"
                type="button"
                class="btn btn-link text-start p-0 text-decoration-none order-detail-page__address-link"
                @click="openShippingModal"
              >
                <span class="order-detail-page__address-text">{{ formattedShippingAddress }}</span>
              </button>
              <div v-else class="small order-detail-page__address-text text-body">{{ formattedShippingAddress }}</div>
            </div>

            <div class="mb-2">
              <label class="form-label small text-secondary mb-1" for="order-detail-carrier">Shipping Carrier</label>
              <input
                id="order-detail-carrier"
                v-model="carrierField"
                class="form-control form-control-sm"
                list="order-detail-carrier-datalist"
                autocomplete="off"
                :disabled="!canRunShipHeroActions"
              />
            </div>
            <div class="mb-2">
              <label class="form-label small text-secondary mb-1" for="order-detail-method">Method</label>
              <input
                id="order-detail-method"
                v-model="methodField"
                class="form-control form-control-sm"
                list="order-detail-method-datalist"
                autocomplete="off"
                :disabled="!canRunShipHeroActions"
              />
            </div>
            <div class="mb-3">
              <button
                type="button"
                class="btn btn-primary btn-sm"
                :disabled="!canRunShipHeroActions || shippingLinesSaveBusy"
                @click="saveShippingLines"
              >
                {{ shippingLinesSaveBusy ? "Saving…" : "Save Shipping" }}
              </button>
            </div>

            <div class="form-check mb-3">
              <input
                id="order-detail-allow-partial"
                v-model="allowPartialLocal"
                class="form-check-input"
                type="checkbox"
                :disabled="!canRunShipHeroActions || allowPartialSaveBusy"
                @change="onAllowPartialChange"
              />
              <label class="form-check-label small" for="order-detail-allow-partial">Allow Partial</label>
            </div>

            <div class="mb-1">
              <div class="text-secondary small mb-1">Order tags</div>
              <input
                v-model="tagInputValue"
                type="text"
                class="form-control form-control-sm mb-2"
                placeholder="Press enter or comma to add a tag."
                autocomplete="off"
                :disabled="!canRunShipHeroActions"
                @keydown="onTagInputKeydown"
              />
            </div>
            <div v-if="tagsLocal.length" class="d-flex flex-wrap gap-1 mb-2">
              <span
                v-for="(t, idx) in tagsLocal"
                :key="t + '-' + idx"
                class="badge bg-secondary-subtle text-dark border d-inline-flex align-items-center gap-1"
              >
                {{ t }}
                <button
                  v-if="canRunShipHeroActions"
                  type="button"
                  class="btn btn-link btn-sm p-0 lh-1 text-decoration-none"
                  aria-label="Remove tag"
                  @click="removeTag(idx)"
                >
                  ×
                </button>
              </span>
            </div>
            <button
              type="button"
              class="btn btn-primary btn-sm"
              :disabled="!canRunShipHeroActions || tagsSaveBusy"
              @click="saveTags"
            >
              {{ tagsSaveBusy ? "Saving…" : "Save Tags" }}
            </button>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h3 class="h6 fw-semibold mb-0">Attachments</h3>
              <button
                type="button"
                class="btn btn-sm btn-outline-primary"
                :disabled="!canRunShipHeroActions || attachmentUploadBusy"
                @click="triggerAttachFile"
              >
                Attach File
              </button>
            </div>
            <input
              ref="attachmentFileInput"
              type="file"
              class="d-none"
              accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.csv,.doc,.docx,.xlsx,image/*,application/pdf"
              @change="onAttachmentFileChange"
            />
            <div v-if="(order.attachments || []).length" class="list-group list-group-flush">
              <a
                v-for="att in order.attachments"
                :key="att.id || att.url"
                :href="att.url || '#'"
                class="list-group-item list-group-item-action small py-2 px-0 border-0"
                target="_blank"
                rel="noopener noreferrer"
              >
                {{ att.filename || att.url || "Attachment" }}
              </a>
            </div>
            <div
              v-else
              class="order-detail-page__attachments-empty text-center text-secondary py-5 px-2 border rounded-2 bg-light-subtle"
            >
              <div class="order-detail-page__attachments-empty-icon mb-2 text-secondary" aria-hidden="true">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25">
                  <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19A4 4 0 1 1 19 13.12l-8.69 8.69a2 2 0 0 1-2.83-2.83l8.49-8.48" />
                </svg>
              </div>
              <div>There are no attachments</div>
            </div>
          </div>
        </div>
      </div>
    </template>
    </template>

    <datalist id="order-detail-carrier-datalist">
      <option value="ups" />
      <option value="fedex" />
      <option value="usps" />
      <option value="dhl" />
      <option value="asendia_one" />
      <option value="ontrac" />
      <option value="lasership" />
    </datalist>
    <datalist id="order-detail-method-datalist">
      <option value="Ground" />
      <option value="Priority" />
      <option value="Express" />
      <option value="Select" />
    </datalist>

    <Teleport to="body">
      <Transition name="modal-backdrop">
        <div
          v-if="shippingModalOpen"
          class="crm-vx-modal-overlay"
          aria-modal="true"
          role="dialog"
          aria-labelledby="order-shipping-modal-title"
        >
          <div class="crm-vx-modal-backdrop" aria-hidden="true" @click="closeShippingModal" />
          <Transition name="modal-panel" appear>
            <div class="crm-vx-modal">
              <button
                type="button"
                class="crm-vx-modal__close"
                aria-label="Close"
                :disabled="shippingSaveBusy"
                @click="closeShippingModal"
              >
                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
              <header class="crm-vx-modal__head">
                <h2 id="order-shipping-modal-title" class="crm-vx-modal__title">Shipping Information</h2>
              </header>
              <div class="crm-vx-modal__body pt-0">
                <div class="row g-2">
                  <div class="col-md-6">
                    <label class="form-label small" for="ship-fn">First Name</label>
                    <input id="ship-fn" v-model="shippingForm.first_name" type="text" class="form-control form-control-sm" />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small" for="ship-ln">Last Name</label>
                    <input id="ship-ln" v-model="shippingForm.last_name" type="text" class="form-control form-control-sm" />
                  </div>
                  <div class="col-12">
                    <label class="form-label small" for="ship-co">Company</label>
                    <input id="ship-co" v-model="shippingForm.company" type="text" class="form-control form-control-sm" />
                  </div>
                  <div class="col-12">
                    <label class="form-label small" for="ship-a1">Address</label>
                    <input id="ship-a1" v-model="shippingForm.address1" type="text" class="form-control form-control-sm" />
                  </div>
                  <div class="col-12">
                    <label class="form-label small" for="ship-a2">Address 2</label>
                    <input id="ship-a2" v-model="shippingForm.address2" type="text" class="form-control form-control-sm" />
                  </div>
                  <div class="col-12">
                    <label class="form-label small" for="ship-ph">Phone</label>
                    <input id="ship-ph" v-model="shippingForm.phone" type="text" class="form-control form-control-sm" />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small" for="ship-city">City</label>
                    <input id="ship-city" v-model="shippingForm.city" type="text" class="form-control form-control-sm" />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small" for="ship-st">State</label>
                    <input id="ship-st" v-model="shippingForm.state" type="text" class="form-control form-control-sm" />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small" for="ship-ct">Country</label>
                    <input id="ship-ct" v-model="shippingForm.country" type="text" class="form-control form-control-sm" />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small" for="ship-zip">ZIP Code</label>
                    <input id="ship-zip" v-model="shippingForm.zip" type="text" class="form-control form-control-sm" />
                  </div>
                  <div class="col-12">
                    <label class="form-label small" for="ship-em">Email</label>
                    <input id="ship-em" v-model="shippingForm.email" type="email" class="form-control form-control-sm" />
                  </div>
                </div>
              </div>
              <footer class="crm-vx-modal__footer">
                <button
                  type="button"
                  class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                  :disabled="shippingSaveBusy"
                  @click="closeShippingModal"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                  :disabled="shippingSaveBusy"
                  @click="saveShippingAddress"
                >
                  {{ shippingSaveBusy ? "Updating…" : "Update" }}
                </button>
              </footer>
            </div>
          </Transition>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <Transition name="modal-backdrop">
        <div
          v-if="addItemsModalOpen"
          class="crm-vx-modal-overlay"
          aria-modal="true"
          role="dialog"
          aria-labelledby="order-add-items-modal-title"
        >
          <div
            class="crm-vx-modal-backdrop"
            aria-hidden="true"
            @click="closeAddItemsModal"
          />
          <Transition name="modal-panel" appear>
            <div class="crm-vx-modal crm-vx-modal--sm">
              <button
                type="button"
                class="crm-vx-modal__close"
                aria-label="Close"
                :disabled="addItemsBusy"
                @click="closeAddItemsModal"
              >
                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
              <header class="crm-vx-modal__head">
                <h2 id="order-add-items-modal-title" class="crm-vx-modal__title">Add Items</h2>
              </header>
              <div class="crm-vx-modal__body pt-0">
                <div class="mb-2">
                  <label class="form-label small" for="add-item-sku">SKU</label>
                  <input id="add-item-sku" v-model="addItemForm.sku" type="text" class="form-control form-control-sm" />
                </div>
                <div class="mb-2">
                  <label class="form-label small" for="add-item-qty">Quantity</label>
                  <input id="add-item-qty" v-model.number="addItemForm.quantity" type="number" min="1" class="form-control form-control-sm" />
                </div>
                <div class="mb-0">
                  <label class="form-label small" for="add-item-name">Product Name (Optional)</label>
                  <input id="add-item-name" v-model="addItemForm.product_name" type="text" class="form-control form-control-sm" />
                </div>
              </div>
              <footer class="crm-vx-modal__footer">
                <button
                  type="button"
                  class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                  :disabled="addItemsBusy"
                  @click="closeAddItemsModal"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                  :disabled="addItemsBusy"
                  @click="submitAddItems"
                >
                  {{ addItemsBusy ? "Adding…" : "Add Items" }}
                </button>
              </footer>
            </div>
          </Transition>
        </div>
      </Transition>
    </Teleport>

    <ConfirmModal
      :open="confirmFulfilledOpen"
      title="Mark As Fulfilled"
      message="This updates fulfillment status in ShipHero only. It does not create a shipment or remove inventory via the full fulfillment flow. Continue?"
      confirm-label="Mark As Fulfilled"
      cancel-label="Cancel"
      :danger="false"
      :busy="actionBusy"
      @close="confirmFulfilledOpen = false"
      @confirm="runMarkFulfilled"
    />
    <ConfirmModal
      :open="confirmCancelOpen"
      title="Cancel Order"
      message="This cancels the order in ShipHero. If ShipHero rejects the request, you may need to adjust options (for example void on store) from ShipHero directly."
      confirm-label="Cancel Order"
      cancel-label="Back"
      danger
      :busy="actionBusy"
      @close="confirmCancelOpen = false"
      @confirm="runCancelOrder"
    />
  </div>
</template>

<style scoped>
.order-detail-page__fullscreen-loading {
  min-height: 70vh;
  display: flex;
  align-items: center;
  justify-content: center;
}

.order-detail-page__sort-btn {
  background: transparent;
  border: 0;
  padding: 0;
  font: inherit;
  color: inherit;
  text-align: left;
}

.order-detail-page__sort-icon {
  opacity: 0.7;
  font-size: 0.8em;
  margin-left: 0.2rem;
}

.order-detail-page__sort-btn--right {
  width: 100%;
  text-align: right;
}

.order-detail-page__item-cell {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 0;
  width: 100%;
}

.order-detail-page__item-copy {
  min-width: 0;
  flex: 1 1 auto;
  max-width: 100%;
}

/* One line + ellipsis; full text on hover via native `title` on the element (see template). */
.order-detail-page__item-name {
  color: #2563eb;
  font-weight: 600;
  display: block;
  min-width: 0;
  max-width: 100%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 1.35;
}

.order-detail-page__item-sku {
  color: #6c757d;
  display: block;
  min-width: 0;
  max-width: 100%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-size: 0.875rem;
  line-height: 1.3;
}

/*
 * Global staff styles use `width: max-content` on tables inside `.staff-table-wrap` so other
 * datatables can scroll horizontally on mobile. That defeats fixed layout here and stretches
 * the table to the full product string — horizontal scrollbar. Keep this table viewport-width.
 */
.order-detail-page :deep(.table-responsive.staff-table-wrap) {
  overflow-x: clip;
  max-width: 100%;
}

.order-detail-page :deep(.staff-table-wrap .table.staff-data-table) {
  width: 100%;
  min-width: 0;
  max-width: 100%;
  table-layout: fixed;
}

.order-detail-page__items-col {
  width: 52%;
  min-width: 0;
  vertical-align: middle;
}

.order-detail-page__item-thumb {
  width: 32px;
  height: 32px;
  border-radius: 0.35rem;
  object-fit: cover;
  border: 1px solid rgba(0, 0, 0, 0.08);
  background: #fff;
  flex-shrink: 0;
}

.order-detail-page__item-thumb--empty {
  background: rgba(0, 0, 0, 0.04);
}

.order-detail-page__backorder {
  color: #dc3545;
  font-size: 0.85rem;
  font-weight: 600;
}

.order-detail-page__history-html :deep(p) {
  margin-bottom: 0.4rem;
}

.order-detail-page__history-html :deep(ul),
.order-detail-page__history-html :deep(ol) {
  margin: 0.25rem 0 0.35rem 1.1rem;
  padding: 0;
}

.order-detail-page__history-html :deep(li) {
  margin-bottom: 0.2rem;
}

.order-detail-page__side-panel {
  position: sticky;
  top: 1rem;
}

.order-detail-page__address-link {
  color: #2563eb;
  font-weight: 500;
}

.order-detail-page__address-text {
  white-space: pre-line;
  line-height: 1.45;
}

.order-detail-page__attachments-empty-icon {
  opacity: 0.55;
}

.modal-backdrop-enter-active,
.modal-backdrop-leave-active {
  transition: opacity 0.2s ease;
}
.modal-backdrop-enter-active .crm-vx-modal-backdrop,
.modal-backdrop-leave-active .crm-vx-modal-backdrop {
  transition: inherit;
}
.modal-backdrop-enter-from,
.modal-backdrop-leave-to {
  opacity: 0;
}

.modal-panel-enter-active {
  transition:
    opacity 0.2s ease,
    transform 0.2s ease;
}
.modal-panel-leave-active {
  transition:
    opacity 0.15s ease,
    transform 0.15s ease;
}
.modal-panel-enter-from,
.modal-panel-leave-to {
  opacity: 0;
  transform: scale(0.97) translateY(0.5rem);
}
</style>
