<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsPortalUser } from "../../utils/crmUser";
import { canWriteShipHeroOrders } from "../../utils/crmShipHeroOrders";

const crmUser = inject("crmUser", ref(null));
const route = useRoute();
const router = useRouter();
const toast = useToast();

const loading = ref(false);
const order = ref(null);
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

const confirmRemoveHoldsOpen = ref(false);
const removeHoldsBusy = ref(false);
const moreActionsOpen = ref(false);
const moreActionsBtnRef = ref(null);
const moreActionsMenuRef = ref(null);
const moreActionsMenuStyle = ref({ visibility: "hidden" });
const moreActionsLayoutBound = ref(false);
const requireSignatureLocal = ref(false);
const giftNoteLocal = ref("");
const optionsSaveBusy = ref(false);

const CARRIER_PRESETS = ["Cheapest", "ups", "fedex", "usps", "dhl", "asendia_one", "ontrac", "lasership"];
const METHOD_PRESETS = ["Select", "Ground", "Priority", "Express", "Standard", "A124"];

const orderId = computed(() => String(route.params.shipheroOrderId || ""));

const isPortalUser = computed(() => crmIsPortalUser(crmUser.value));
const portalClientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));

const headingOrderNumber = computed(() => String(order.value?.order_number || "—").replace(/^#\s*/, ""));
const statusClass = computed(() => {
  const raw = String(
    order.value?.status || order.value?.raw_fulfillment_status || ""
  ).toLowerCase();
  if (raw.includes("hold") || raw.includes("backorder")) return "text-danger bg-danger-subtle";
  if (raw.includes("ship")) return "text-success bg-success-subtle";
  return "text-secondary bg-secondary-subtle";
});

const canRunShipHeroActions = computed(() => canWriteShipHeroOrders(crmUser.value));

const canUseStaffOrderHeaderActions = computed(() => Boolean(order.value) && !crmIsPortalUser(crmUser.value));

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
  const normalized = String(order.value?.status || "").trim();
  if (normalized) return normalized;
  const rawFulfillment = String(order.value?.raw_fulfillment_status || "").trim();
  if (rawFulfillment) return rawFulfillment;
  return "—";
});

const orderHeaderBadgeClass = computed(() => {
  if (showNotReadyToShipBanner.value) {
    return "badge rounded-pill fw-medium text-danger-emphasis bg-danger-subtle";
  }
  return `badge rounded-pill fw-medium ${statusClass.value}`;
});

const orderLineItemQtySum = computed(() => {
  const rows = order.value?.items;
  if (!Array.isArray(rows)) return 0;
  return rows.reduce((sum, row) => sum + Number(row?.quantity ?? 0), 0);
});

const taxPercentLabel = computed(() => {
  const subtotal = Number(order.value?.subtotal ?? 0);
  const tax = Number(order.value?.total_tax ?? 0);
  if (!Number.isFinite(subtotal) || subtotal <= 0 || !Number.isFinite(tax)) return "0.00%";
  return `${((tax / subtotal) * 100).toFixed(2)}%`;
});

const sortedItems = computed(() => {
  const rows = Array.isArray(order.value?.items) ? [...order.value.items] : [];
  const dir = itemSortDir.value === "desc" ? -1 : 1;
  const key = itemSortKey.value;
  const numericKeys = new Set([
    "quantity",
    "quantity_allocated",
    "quantity_pending_fulfillment",
    "backorder_quantity",
    "price",
  ]);
  rows.sort((a, b) => {
    if (numericKeys.has(key)) {
      const na = Number(a?.[key] ?? 0);
      const nb = Number(b?.[key] ?? 0);
      return (na - nb) * dir;
    }
    const av = a?.[key];
    const bv = b?.[key];
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

const customerDisplayName = computed(() => {
  const a = order.value?.shipping_address;
  if (!a || typeof a !== "object") return "";
  const name = [a.first_name, a.last_name].filter(Boolean).join(" ").trim();
  return name || "—";
});

const shippingAddressDisplayCaps = computed(() => {
  const t = formattedShippingAddress.value;
  if (!t || t === "—") return "—";
  return t.toUpperCase();
});

const carrierSelectOptions = computed(() => {
  const cur = String(carrierField.value || "").trim();
  const set = new Set(["", ...CARRIER_PRESETS, cur]);
  return Array.from(set);
});

const methodSelectOptions = computed(() => {
  const cur = String(methodField.value || "").trim();
  const set = new Set(["", ...METHOD_PRESETS, cur]);
  return Array.from(set);
});

const notReadyBannerBullets = computed(() => {
  const o = order.value;
  if (!o) return [];
  const sub = String(o.not_ready_subtitle || "").trim();
  let text = sub;
  if (!text) {
    const hr = String(o.hold_reason || "").trim();
    if (hr) text = `Order has ${hr.toLowerCase()}.`;
    else text = "Order has a hold.";
  }
  const parts = text.split(/(?<=\.)\s+/).map((s) => s.trim()).filter(Boolean);
  return parts.length ? parts : [text];
});

function fmtDate(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleString();
}

function fmtCreationDate(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleString("en-US", {
    month: "2-digit",
    day: "2-digit",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
    second: "2-digit",
    hour12: true,
  });
}

function fmtMoney(v) {
  const n = Number(v);
  if (!Number.isFinite(n)) return "—";
  return new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" }).format(n);
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
      gift_note: "",
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
  confirmRemoveHoldsOpen.value = false;
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

async function runRemoveHolds() {
  if (!order.value || !selectedAccountId.value || !orderId.value) return;
  removeHoldsBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/remove-holds`, {
      client_account_id: Number(selectedAccountId.value),
    });
    toast.success("Holds removed.");
    confirmRemoveHoldsOpen.value = false;
    await loadOrder();
  } catch (e) {
    toast.errorFrom(e, "Could not remove holds.");
  } finally {
    removeHoldsBusy.value = false;
  }
}

async function saveSignatureGiftNote() {
  if (!selectedAccountId.value || !orderId.value) return;
  optionsSaveBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/signature-gift-note`, {
      client_account_id: Number(selectedAccountId.value),
      require_signature: requireSignatureLocal.value,
      gift_note: giftNoteLocal.value,
    });
    toast.success("Options updated.");
    await loadOrder();
  } catch (e) {
    toast.errorFrom(e, "Could not save options.");
  } finally {
    optionsSaveBusy.value = false;
  }
}

watch(
  () => order.value,
  (o) => {
    if (!o || typeof o !== "object") return;
    allowPartialLocal.value = !!o.allow_partial;
    requireSignatureLocal.value = !!o.require_signature;
    giftNoteLocal.value = String(o.gift_note ?? "");
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
  if (!canRunShipHeroActions.value) {
    toast.error("You do not have permission to add items.");
    return;
  }
  addItemForm.value = { sku: "", quantity: 1, product_name: "" };
  addItemsModalOpen.value = true;
}

function layoutMoreActionsMenu() {
  const btn = moreActionsBtnRef.value;
  if (!btn || !moreActionsOpen.value) return;
  const r = btn.getBoundingClientRect();
  const menuWidth = 220;
  const left = Math.min(window.innerWidth - menuWidth - 8, Math.max(8, r.right - menuWidth));
  moreActionsMenuStyle.value = {
    position: "fixed",
    top: `${Math.floor(r.bottom + 6)}px`,
    left: `${Math.floor(left)}px`,
    minWidth: `${menuWidth}px`,
    zIndex: 20050,
    visibility: "visible",
  };
}

function onMoreActionsWindowLayout() {
  if (!moreActionsOpen.value) return;
  layoutMoreActionsMenu();
}

function bindMoreActionsLayoutListeners() {
  if (moreActionsLayoutBound.value) return;
  window.addEventListener("resize", onMoreActionsWindowLayout);
  window.addEventListener("scroll", onMoreActionsWindowLayout, true);
  moreActionsLayoutBound.value = true;
}

function unbindMoreActionsLayoutListeners() {
  if (!moreActionsLayoutBound.value) return;
  window.removeEventListener("resize", onMoreActionsWindowLayout);
  window.removeEventListener("scroll", onMoreActionsWindowLayout, true);
  moreActionsLayoutBound.value = false;
}

function closeMoreActionsMenu() {
  moreActionsOpen.value = false;
}

async function toggleMoreActionsMenu(ev) {
  ev?.stopPropagation?.();
  moreActionsOpen.value = !moreActionsOpen.value;
  if (moreActionsOpen.value) {
    await nextTick();
    layoutMoreActionsMenu();
  }
}

function onMoreActionsDocumentClick(ev) {
  if (!moreActionsOpen.value) return;
  const t = ev.target;
  if (moreActionsBtnRef.value?.contains(t) || moreActionsMenuRef.value?.contains(t)) {
    return;
  }
  moreActionsOpen.value = false;
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
  if (moreActionsOpen.value) moreActionsOpen.value = false;
}

watch([shippingModalOpen, addItemsModalOpen], ([s, a]) => {
  if (s || a) {
    document.addEventListener("keydown", modalEscHandler);
  } else {
    document.removeEventListener("keydown", modalEscHandler);
  }
});

watch(moreActionsOpen, (open) => {
  if (open) {
    setTimeout(() => {
      document.addEventListener("click", onMoreActionsDocumentClick);
    }, 0);
    void nextTick().then(() => {
      layoutMoreActionsMenu();
      bindMoreActionsLayoutListeners();
    });
  } else {
    document.removeEventListener("click", onMoreActionsDocumentClick);
    unbindMoreActionsLayoutListeners();
    moreActionsMenuStyle.value = { visibility: "hidden" };
  }
});

onUnmounted(() => {
  document.removeEventListener("keydown", modalEscHandler);
  document.removeEventListener("click", onMoreActionsDocumentClick);
  unbindMoreActionsLayoutListeners();
});

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Order Detail",
    description: "ShipHero order detail.",
  });
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
    <div v-if="!selectedAccountId" class="alert alert-light border mb-4" role="status">
      <span class="small"
        >This page needs a client account. Open the order from the Orders list, or add
        <code>?client_account_id=…</code> to the URL.</span>
    </div>
    <div v-else-if="loadError" class="alert alert-warning small mb-4" role="alert">
      {{ loadError }}
    </div>
    <div v-else-if="!order" class="alert alert-warning mb-4">
      No order data loaded. Check the order link and account, then try again.
    </div>
    <template v-else>
      <div class="staff-table-card staff-datatable-card staff-datatable-card--white order-detail-page__header-shell mb-4">
        <div class="p-4 pb-3">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div class="min-w-0">
              <div class="d-flex align-items-center flex-wrap gap-2">
                <h1 class="h4 mb-0 fw-bold text-body">Order {{ headingOrderNumber }}</h1>
                <span class="order-detail-page__status-pill" :class="orderHeaderBadgeClass">{{
                  orderHeaderBadgeLabel
                }}</span>
              </div>
              <button
                type="button"
                class="btn btn-link btn-sm text-secondary px-0 py-0 mt-1 text-decoration-none"
                @click="router.back()"
              >
                &lt; Orders
              </button>
            </div>
            <div
              v-if="canUseStaffOrderHeaderActions"
              class="d-flex flex-wrap gap-2 align-items-center flex-shrink-0"
            >
              <div class="dropdown order-detail-page__more-actions position-relative">
                <button
                  ref="moreActionsBtnRef"
                  id="order-detail-more-actions"
                  type="button"
                  class="btn btn-outline-secondary dropdown-toggle"
                  aria-haspopup="true"
                  :aria-expanded="moreActionsOpen ? 'true' : 'false'"
                  @click.stop="toggleMoreActionsMenu"
                >
                  More Actions
                </button>
              </div>
              <button
                v-if="showNotReadyToShipBanner"
                type="button"
                class="btn btn-danger"
                :disabled="!canRunShipHeroActions"
                :title="!canRunShipHeroActions ? 'You do not have permission to change this order in ShipHero.' : undefined"
                @click="confirmRemoveHoldsOpen = true"
              >
                Remove Hold
              </button>
            </div>
          </div>
        </div>
        <div
          v-if="showNotReadyToShipBanner"
          class="order-detail-page__nrts-banner px-4 py-3 border-top border-warning border-2"
        >
          <div class="d-flex gap-3 align-items-start">
            <span class="order-detail-page__nrts-bell text-warning flex-shrink-0" aria-hidden="true">
              <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
                <path
                  d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"
                />
              </svg>
            </span>
            <div class="min-w-0 flex-grow-1">
              <div class="fw-semibold text-body">This order is not ready to ship</div>
              <ul class="small mb-0 ps-3 mt-2 text-secondary order-detail-page__nrts-list">
                <li v-for="(line, idx) in notReadyBannerBullets" :key="'nrts-' + idx">{{ line }}</li>
              </ul>
            </div>
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
              <button
                type="button"
                class="btn btn-outline-secondary btn-sm"
                :disabled="loading || !canRunShipHeroActions"
                :title="!canRunShipHeroActions ? 'Requires inventory update permission' : undefined"
                @click="openAddItemsModal"
              >
                Add Items
              </button>
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
                    <th class="staff-table-head__th text-end">
                      <button class="order-detail-page__sort-btn order-detail-page__sort-btn--right" type="button" @click="toggleItemSort('price')">
                        Price <span class="order-detail-page__sort-icon">{{ sortIndicator("price") }}</span>
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
                    <td class="text-end">{{ item.quantity_pending_fulfillment ?? 0 }}</td>
                    <td class="text-end">
                      <div>{{ fmtMoney(item.price) }}</div>
                      <div
                        v-if="Number(item.backorder_quantity || 0) > 0"
                        class="order-detail-page__backorder"
                      >
                        {{ Number(item.backorder_quantity) }} on backorder
                      </div>
                    </td>
                  </tr>
                  <tr v-if="!sortedItems.length">
                    <td colspan="5" class="text-center text-secondary py-4">No items</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="order-detail-page__order-summary border-top px-4 py-3">
              <div class="order-detail-page__order-summary-inner ms-auto">
                <div class="d-flex justify-content-between gap-4 mb-2">
                  <span class="text-secondary">Subtotal</span>
                  <span class="text-end text-nowrap">
                    <span class="text-secondary me-2">{{ orderLineItemQtySum }} items</span>
                    <span class="fw-semibold text-body">{{ fmtMoney(order.subtotal) }}</span>
                  </span>
                </div>
                <div class="d-flex justify-content-between gap-4 mb-2">
                  <span class="text-secondary">Shipping</span>
                  <span class="fw-semibold text-body text-nowrap">{{ fmtMoney(order.shipping_cost) }}</span>
                </div>
                <div class="d-flex justify-content-between gap-4 mb-2">
                  <span class="text-secondary">Discount</span>
                  <span class="fw-semibold text-body text-nowrap">{{ fmtMoney(order.total_discounts) }}</span>
                </div>
                <div class="d-flex justify-content-between gap-4 mb-2">
                  <span class="text-secondary">Tax</span>
                  <span class="text-end text-nowrap">
                    <span class="text-secondary me-2">{{ taxPercentLabel }}</span>
                    <span class="fw-semibold text-body">{{ fmtMoney(order.total_tax) }}</span>
                  </span>
                </div>
                <div class="d-flex justify-content-between gap-4 pt-2 border-top">
                  <span class="fw-semibold text-body">Total</span>
                  <span class="h5 mb-0 fw-bold text-body text-nowrap">{{ fmtMoney(order.total_price) }}</span>
                </div>
              </div>
            </div>
            <p class="staff-table-mobile-scroll-cue d-md-none" aria-hidden="true">
              Scroll sideways or swipe to see all columns.
            </p>
          </div>
        </div>

        <div class="col-lg-4 d-flex flex-column gap-4 order-detail-page__side-column">
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel">
            <h3 class="h6 fw-semibold mb-3">Order details</h3>
            <dl class="small mb-0">
              <dt class="text-secondary">Customer</dt>
              <dd class="fw-semibold text-body">{{ customerDisplayName }}</dd>
              <dt class="text-secondary">Email</dt>
              <dd>{{ order.email || "—" }}</dd>
              <dt class="text-secondary">Phone</dt>
              <dd>{{ order.shipping_address?.phone || "—" }}</dd>
              <dt class="text-secondary mt-2">Creation date</dt>
              <dd>{{ fmtCreationDate(order.order_date) }}</dd>
              <dt class="text-secondary">Store</dt>
              <dd class="text-break">{{ order.account || "—" }}</dd>
            </dl>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel">
            <h3 class="h6 fw-semibold mb-3">Shipping Detail</h3>
            <dl class="small mb-3 pb-3 border-bottom">
              <dt class="text-secondary">Shipping address</dt>
              <dd class="mb-0">
                <button
                  v-if="canUseStaffOrderHeaderActions"
                  type="button"
                  class="btn btn-link text-start p-0 text-decoration-none order-detail-page__address-link order-detail-page__address-link--caps"
                  @click="openShippingModal"
                >
                  {{ shippingAddressDisplayCaps }}
                </button>
                <span v-else class="order-detail-page__address-link--caps text-body">{{ shippingAddressDisplayCaps }}</span>
              </dd>
            </dl>
            <div class="mb-3">
              <label class="form-label small text-secondary mb-1" for="order-detail-carrier">Shipping Carrier</label>
              <select
                id="order-detail-carrier"
                v-model="carrierField"
                class="form-select form-select-sm"
                :disabled="!canRunShipHeroActions"
              >
                <option v-for="c in carrierSelectOptions" :key="'c-' + (c || 'empty')" :value="c">
                  {{ c === "" ? "—" : c }}
                </option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label small text-secondary mb-1" for="order-detail-method">Method</label>
              <select
                id="order-detail-method"
                v-model="methodField"
                class="form-select form-select-sm"
                :disabled="!canRunShipHeroActions"
              >
                <option v-for="m in methodSelectOptions" :key="'m-' + (m || 'empty')" :value="m">
                  {{ m === "" ? "—" : m }}
                </option>
              </select>
            </div>
            <button
              type="button"
              class="btn btn-primary btn-sm"
              :disabled="!canRunShipHeroActions || shippingLinesSaveBusy"
              @click="saveShippingLines"
            >
              {{ shippingLinesSaveBusy ? "Saving…" : "Save Carrier & Method" }}
            </button>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel">
            <h3 class="h6 fw-semibold mb-3">Options</h3>
            <div class="form-check mb-2">
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
            <div class="form-check mb-3">
              <input
                id="order-detail-req-sig"
                v-model="requireSignatureLocal"
                class="form-check-input"
                type="checkbox"
                :disabled="!canRunShipHeroActions || optionsSaveBusy"
              />
              <label class="form-check-label small" for="order-detail-req-sig">Require Signature</label>
            </div>
            <label class="form-label small text-secondary mb-1" for="order-gift-note">Gift note</label>
            <textarea
              id="order-gift-note"
              v-model="giftNoteLocal"
              class="form-control form-control-sm mb-3"
              rows="3"
              :disabled="!canRunShipHeroActions || optionsSaveBusy"
            ></textarea>
            <button
              type="button"
              class="btn btn-primary btn-sm"
              :disabled="!canRunShipHeroActions || optionsSaveBusy"
              @click="saveSignatureGiftNote"
            >
              {{ optionsSaveBusy ? "Saving…" : "Save Options" }}
            </button>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel">
            <h3 class="h6 fw-semibold mb-3">Order tags</h3>
            <label class="form-label small text-secondary mb-1" for="order-tags-input">Tags</label>
            <input
              id="order-tags-input"
              v-model="tagInputValue"
              type="text"
              class="form-control form-control-sm mb-2"
              placeholder="Press enter or comma to add a tag."
              autocomplete="off"
              :disabled="!canRunShipHeroActions"
              @keydown="onTagInputKeydown"
            />
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
            <h3 class="h6 fw-semibold mb-3">Fraud analysis</h3>
            <dl class="small mb-0">
              <dt class="text-secondary">Fraud score</dt>
              <dd class="fw-semibold">None</dd>
              <dt class="text-secondary">Fraud address</dt>
              <dd class="fw-semibold">None</dd>
              <dt class="text-secondary">Fraud zip</dt>
              <dd class="fw-semibold">None</dd>
              <dt class="text-secondary">Details</dt>
              <dd class="fw-semibold">None</dd>
            </dl>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h3 class="h6 fw-semibold mb-0">Attachments</h3>
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary text-primary"
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
                    <label class="form-label small text-secondary" for="ship-fn">First Name</label>
                    <input
                      id="ship-fn"
                      v-model="shippingForm.first_name"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small text-secondary" for="ship-ln">Last Name</label>
                    <input
                      id="ship-ln"
                      v-model="shippingForm.last_name"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-12">
                    <label class="form-label small text-secondary" for="ship-co">Company</label>
                    <input
                      id="ship-co"
                      v-model="shippingForm.company"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-12">
                    <label class="form-label small text-secondary" for="ship-a1">Address</label>
                    <input
                      id="ship-a1"
                      v-model="shippingForm.address1"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-12">
                    <label class="form-label small text-secondary" for="ship-a2">Address 2</label>
                    <input
                      id="ship-a2"
                      v-model="shippingForm.address2"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-12">
                    <label class="form-label small text-secondary" for="ship-ph">Phone</label>
                    <input
                      id="ship-ph"
                      v-model="shippingForm.phone"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small text-secondary" for="ship-city">City</label>
                    <input
                      id="ship-city"
                      v-model="shippingForm.city"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small text-secondary" for="ship-st">State</label>
                    <input
                      id="ship-st"
                      v-model="shippingForm.state"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small text-secondary" for="ship-ct">Country</label>
                    <input
                      id="ship-ct"
                      v-model="shippingForm.country"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small text-secondary" for="ship-zip">ZIP Code</label>
                    <input
                      id="ship-zip"
                      v-model="shippingForm.zip"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-12">
                    <label class="form-label small text-secondary" for="ship-em">Email</label>
                    <input
                      id="ship-em"
                      v-model="shippingForm.email"
                      type="email"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                </div>
                <p v-if="!canRunShipHeroActions" class="small text-secondary mb-0 mt-2">
                  This address is read-only. Ask an administrator to grant <strong>Update inventory quantities</strong> to
                  edit.
                </p>
              </div>
              <footer class="crm-vx-modal__footer d-flex flex-column gap-2">
                <button
                  type="button"
                  class="crm-vx-modal-btn crm-vx-modal-btn--primary w-100"
                  :disabled="shippingSaveBusy || !canRunShipHeroActions"
                  :title="!canRunShipHeroActions ? 'Requires Update inventory quantities permission' : undefined"
                  @click="saveShippingAddress"
                >
                  {{ shippingSaveBusy ? "Updating…" : "Update" }}
                </button>
                <button
                  type="button"
                  class="crm-vx-modal-btn crm-vx-modal-btn--secondary w-100"
                  :disabled="shippingSaveBusy"
                  @click="closeShippingModal"
                >
                  Cancel
                </button>
              </footer>
            </div>
          </Transition>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <ul
        v-show="moreActionsOpen"
        ref="moreActionsMenuRef"
        class="dropdown-menu dropdown-menu-end show shadow-sm border bg-body order-detail-page__more-actions-menu"
        :style="moreActionsMenuStyle"
        role="menu"
        aria-labelledby="order-detail-more-actions"
      >
        <li>
          <button
            type="button"
            class="dropdown-item"
            role="menuitem"
            @click="
              closeMoreActionsMenu();
              loadOrder();
            "
          >
            Refresh
          </button>
        </li>
        <li>
          <button
            type="button"
            class="dropdown-item"
            role="menuitem"
            :disabled="!canRunShipHeroActions"
            @click="
              closeMoreActionsMenu();
              if (canRunShipHeroActions) confirmFulfilledOpen = true;
            "
          >
            Mark As Fulfilled
          </button>
        </li>
        <li>
          <button
            type="button"
            class="dropdown-item text-danger"
            role="menuitem"
            :disabled="!canRunShipHeroActions"
            @click="
              closeMoreActionsMenu();
              if (canRunShipHeroActions) confirmCancelOpen = true;
            "
          >
            Cancel Order
          </button>
        </li>
      </ul>
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
    <ConfirmModal
      :open="confirmRemoveHoldsOpen"
      title="Remove Hold"
      message="This clears client, payment, operator, fraud, and address holds in ShipHero (where supported). Shipping method hold may need to be cleared in ShipHero if it still applies. Continue?"
      confirm-label="Remove Hold"
      cancel-label="Cancel"
      danger
      :busy="removeHoldsBusy"
      @close="confirmRemoveHoldsOpen = false"
      @confirm="runRemoveHolds"
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
  width: 38%;
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

.order-detail-page__side-column {
  position: sticky;
  top: 1rem;
  align-self: flex-start;
  max-height: calc(100vh - 2rem);
  overflow-y: auto;
}

.order-detail-page__header-shell {
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-radius: 0.5rem;
  overflow: visible;
  background: var(--bs-body-bg, #fff);
}

.order-detail-page__status-pill {
  font-size: 0.8125rem;
  padding: 0.35rem 0.75rem;
  line-height: 1.25;
}

.order-detail-page__more-actions .dropdown-menu {
  z-index: 1085;
  min-width: 11rem;
}

.order-detail-page__order-summary-inner {
  max-width: 22rem;
}

.order-detail-page__nrts-banner {
  background: #fffbeb;
}

[data-bs-theme="dark"] .order-detail-page__nrts-banner {
  background: rgba(250, 204, 21, 0.14);
}

.order-detail-page__nrts-list li {
  margin-bottom: 0.15rem;
}

.order-detail-page__side-panel {
  position: relative;
}

.order-detail-page__address-link {
  color: #2563eb;
  font-weight: 500;
}

.order-detail-page__address-link--caps {
  white-space: pre-line;
  line-height: 1.4;
  text-transform: uppercase;
}

button.order-detail-page__address-link--caps {
  font-weight: 700;
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
