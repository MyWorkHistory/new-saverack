<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ShopifyCarrierLogo from "../../components/shopify/ShopifyCarrierLogo.vue";
import ShopifyOrderCancelConfirmModal from "../../components/shopify/ShopifyOrderCancelConfirmModal.vue";
import ShopifyOrderEditAddressModal from "../../components/shopify/ShopifyOrderEditAddressModal.vue";
import ShopifyOrderEditItemsModal from "../../components/shopify/ShopifyOrderEditItemsModal.vue";
import ShopifyOrderEditShippingModal from "../../components/shopify/ShopifyOrderEditShippingModal.vue";
import ShopifyOrderFulfillModal from "../../components/shopify/ShopifyOrderFulfillModal.vue";
import ShopifyOrderLineFulfillModal from "../../components/shopify/ShopifyOrderLineFulfillModal.vue";
import ShopifyOrderHoldModal from "../../components/shopify/ShopifyOrderHoldModal.vue";
import ShopifyOrderReprocessModal from "../../components/shopify/ShopifyOrderReprocessModal.vue";
import ShopifyOrderReshipModal from "../../components/shopify/ShopifyOrderReshipModal.vue";
import ShopifyOrderStatusPickerModal from "../../components/shopify/ShopifyOrderStatusPickerModal.vue";
import {
  displayStatusClass,
  displayStatusLabel,
  formatShopifyOrderName,
  isCancelledStatus,
  isFulfilledStatus,
  useShopifyOrderActions,
} from "../../composables/useShopifyOrderActions.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";

const route = useRoute();
const router = useRouter();
const toast = useToast();
const loading = ref(true);
const order = ref(null);
const actionsMenuOpen = ref(false);
const holdModalOpen = ref(false);
const cancelModalOpen = ref(false);
const fulfillModalOpen = ref(false);
const lineStatusMenuId = ref(null);
const lineFulfillOpen = ref(false);
const lineFulfillTarget = ref(null);
const reshipModalOpen = ref(false);
const reprocessModalOpen = ref(false);
const statusPickerOpen = ref(false);
const editItemsOpen = ref(false);
const editAddressOpen = ref(false);
const editShippingOpen = ref(false);

const orderId = computed(() => Number(route.params.id || 0));
const lineItems = computed(() => (Array.isArray(order.value?.line_items) ? order.value.line_items : []));
const timeline = computed(() => visibleTimeline(order.value?.timeline));
const recipient = computed(() => order.value?.recipient || null);
const shipping = computed(() => order.value?.shipping || null);

/** Screenshot format: August 18, 2026 at 10:24 AM */
function formatDetailDateTime(val) {
  if (val == null || val === "") return "";
  const d = val instanceof Date ? val : new Date(val);
  if (Number.isNaN(d.getTime())) return "";
  const datePart = new Intl.DateTimeFormat("en-US", {
    month: "long",
    day: "numeric",
    year: "numeric",
  }).format(d);
  const timePart = new Intl.DateTimeFormat("en-US", {
    hour: "numeric",
    minute: "2-digit",
  }).format(d);
  return `${datePart} at ${timePart}`;
}

const createdLabel = computed(() => formatDetailDateTime(order.value?.shopify_created_at));

function canChangeOrderActions(status) {
  return !isFulfilledStatus(status) && !isCancelledStatus(status);
}

const isOnHold = computed(() => String(order.value?.display_status || "").toLowerCase() === "on_hold");

const activeHoldReasons = computed(() => {
  const raw = order.value?.crm_hold_reasons;
  return Array.isArray(raw) ? raw.map((r) => String(r || "").trim()).filter(Boolean) : [];
});

const actions = useShopifyOrderActions({
  onUpdated: (updated) => {
    if (!updated?.id) return;
    // Cancel (and other detail payloads) include line_items — replace fully.
    if (Array.isArray(updated.line_items)) {
      order.value = updated;
      return;
    }
    // List-row patches must not wipe detail line statuses.
    order.value = {
      ...order.value,
      ...updated,
      line_items: order.value?.line_items,
      fulfillment_orders: order.value?.fulfillment_orders,
      fulfillments: order.value?.fulfillments,
      timeline: order.value?.timeline,
    };
  },
});

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/shopify/orders/${route.params.id}`);
    order.value = data?.order || null;
  } catch (e) {
    toast.errorFrom(e, "Could not load order.");
  } finally {
    loading.value = false;
  }
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-shopify-order-detail-actions]")) {
    actionsMenuOpen.value = false;
  }
  if (!e.target?.closest?.("[data-line-status-menu]")) {
    lineStatusMenuId.value = null;
  }
}

function openItem(line) {
  if (line?.crm_variant_id) {
    const href = router.resolve({
      name: "shopify-inventory-detail",
      params: { id: String(line.crm_variant_id) },
    }).href;
    window.open(href, "_blank", "noopener,noreferrer");
    return;
  }
  toast.error("Product is not in CRM inventory.");
}

function lineStatusClass(status) {
  if (status === "cancelled") return "so-line-status--cancelled";
  if (status === "fulfilled") return "so-line-status--fulfilled";
  if (status === "backorder") return "so-line-status--backorder";
  return "so-line-status--pending";
}

function lineStatusLabel(status) {
  if (status === "cancelled") return "Cancelled";
  if (status === "fulfilled") return "Fulfilled";
  if (status === "backorder") return "Backorder";
  return "Pending";
}

function toggleLineStatusMenu(line) {
  lineStatusMenuId.value = lineStatusMenuId.value === line.id ? null : line.id;
}

async function setLineStatus(line, status) {
  lineStatusMenuId.value = null;
  if (!line?.id || !orderId.value) return;
  if (status === "fulfilled") {
    if (line.line_status === "fulfilled") {
      toast.error("This item is already fulfilled.");
      return;
    }
    lineFulfillTarget.value = line;
    lineFulfillOpen.value = true;
    return;
  }
  if (line.line_status === status) return;
  const result = await actions.applyLineStatus(orderId.value, line.id, status);
  if (result) await load();
}

async function confirmLineFulfill({ trackingNumber } = {}) {
  const line = lineFulfillTarget.value;
  if (!line?.id || !orderId.value) return;
  const result = await actions.applyLineStatus(orderId.value, line.id, "fulfilled", trackingNumber || "");
  if (result) {
    lineFulfillOpen.value = false;
    lineFulfillTarget.value = null;
    await load();
  }
}

function timelineIconClass(type) {
  if (type === "order_hold") return "so-timeline__icon--hold";
  if (type === "order_cancel") return "so-timeline__icon--hold";
  if (type === "order_edited" || type === "address_updated" || type === "shipping_updated" || type === "items_updated" || type === "shopify_edit" || type === "order_status") {
    return "so-timeline__icon--edit";
  }
  if (type === "order_fulfill" || type === "ready_to_ship") return "so-timeline__icon--ok";
  return "so-timeline__icon--create";
}

function visibleTimeline(rows) {
  const list = Array.isArray(rows) ? rows : [];
  return list.filter((ev) => !isShopifyEchoOfUserItemEdit(ev, list));
}

function isShopifyEchoOfUserItemEdit(ev, rows) {
  if (!ev) return false;
  const actor = String(ev.actor_label || "").toLowerCase();
  const isEcho = ev.type === "shopify_edit" || (actor === "shopify" && ev.title === "Order Edited");
  if (!isEcho) return false;
  const at = Date.parse(ev.created_at || "");
  return rows.some((other) => {
    if (!other || other.id === ev.id || other.actor_user_id == null) return false;
    if (other.type !== "items_updated" && other.title !== "Order Edited") return false;
    const otherAt = Date.parse(other.created_at || "");
    return Number.isFinite(at) && Number.isFinite(otherAt) && Math.abs(otherAt - at) <= 30 * 60 * 1000;
  });
}

function timelineDetailLines(detail) {
  const text = String(detail || "").trim();
  if (!text) return [];
  const parts = text.includes("\n") ? text.split("\n") : text.split("; ");
  return parts.map((line) => line.trim()).filter(Boolean);
}

function timelineGlyph(type) {
  if (type === "order_hold") return "pause";
  if (type === "order_cancel") return "pause";
  if (type === "order_fulfill" || type === "ready_to_ship") return "check";
  if (type === "order_imported" || type === "order_created") return "plus";
  return "edit";
}

function formatAddressLines(r) {
  if (!r) return [];
  const lines = [];
  const company = String(r.company || "").trim();
  if (company) lines.push(company);
  const street1 = String(r.address1 || "").trim();
  if (street1) lines.push(street1);
  const street2 = String(r.address2 || "").trim();
  if (street2) lines.push(street2);
  const locality = [r.city, r.province, r.zip].map((part) => String(part || "").trim()).filter(Boolean).join(", ");
  if (locality) lines.push(locality);
  const country = String(r.country || "").trim();
  if (country) lines.push(country);
  const email = String(r.email || order.value?.email || "").trim();
  if (email) lines.push(email);
  const phone = String(r.phone || order.value?.phone || "").trim();
  if (phone) lines.push(phone);
  return lines;
}

function carrierLabel(code) {
  const c = String(code || "").toUpperCase();
  if (c === "FEDEX") return "FedEx";
  return c || "—";
}

async function syncHoldReasons(id, reasons, current) {
  const next = Array.isArray(reasons) ? reasons : [];
  const existing = Array.isArray(current) ? current : [];
  const toRemove = existing.filter((r) => !next.includes(r));
  const toAdd = next.filter((r) => !existing.includes(r));
  if (!toRemove.length && !toAdd.length) return true;
  if (!next.length) return actions.removeHolds([id], existing);
  if (toRemove.length && !toAdd.length) return actions.removeHolds([id], toRemove);
  return actions.holdOrder([id], next);
}

async function confirmHold(reasons) {
  if (!orderId.value) return;
  const result = await syncHoldReasons(orderId.value, reasons, activeHoldReasons.value);
  if (result) {
    holdModalOpen.value = false;
    await load();
  }
}

function onRemoveHold() {
  statusPickerOpen.value = false;
  holdModalOpen.value = true;
}

async function confirmCancel({ cancelInShopify } = {}) {
  if (!orderId.value) return;
  const result = await actions.cancelOrder([orderId.value], Boolean(cancelInShopify));
  if (result) {
    cancelModalOpen.value = false;
    await load();
  }
}

async function confirmFulfill({ trackingNumber, deductLineIds } = {}) {
  if (!orderId.value) return;
  const result = await actions.fulfillOrder([orderId.value], { trackingNumber, deductLineIds });
  if (result) {
    fulfillModalOpen.value = false;
    await load();
  }
}

async function confirmReship(lineItemIds) {
  if (!orderId.value) return;
  const result = await actions.reshipOrder(orderId.value, lineItemIds);
  if (result) {
    reshipModalOpen.value = false;
    await load();
  }
}

async function confirmReprocess() {
  if (!orderId.value) return;
  const result = await actions.reprocessOrder([orderId.value]);
  if (result) {
    reprocessModalOpen.value = false;
    await load();
  }
}

async function onStatusPicked(status) {
  if (!orderId.value) return;
  if (status === "on_hold") {
    statusPickerOpen.value = false;
    holdModalOpen.value = true;
    return;
  }
  if (status === "fulfilled") {
    statusPickerOpen.value = false;
    fulfillModalOpen.value = true;
    return;
  }
  const result = await actions.applyDisplayStatus([orderId.value], status);
  if (result) {
    statusPickerOpen.value = false;
    await load();
  }
}

async function confirmEditItems(payload) {
  if (!orderId.value) return;
  const result = await actions.updateOrderItems(orderId.value, payload);
  if (result) {
    editItemsOpen.value = false;
    await load();
  }
}

async function confirmEditAddress(payload) {
  if (!orderId.value) return;
  if (isFulfilledStatus(order.value?.display_status)) {
    toast.error("You can't update the address on a fulfilled order.");
    editAddressOpen.value = false;
    return;
  }
  const result = await actions.updateShippingAddress(orderId.value, payload);
  if (result) {
    editAddressOpen.value = false;
    await load();
  }
}

async function confirmEditShipping(payload) {
  if (!orderId.value) return;
  const result = await actions.updateShippingMethod(orderId.value, payload);
  if (result) {
    editShippingOpen.value = false;
    await load();
  }
}

watch(
  () => route.params.id,
  () => {
    void load();
  },
);

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Shopify Order",
    description: "Shopify order detail and actions.",
  });
  document.addEventListener("click", onDocClick);
  void load();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div v-if="loading" class="p-5 d-flex justify-content-center">
      <CrmLoadingSpinner message="Loading Order…" />
    </div>

    <div v-else-if="!order" class="alert alert-warning mb-4">
      No order data loaded. Check the link and try again.
    </div>

    <template v-else>
      <header class="so-detail-hero mb-4">
        <button
          type="button"
          class="btn btn-link btn-sm px-0 py-0 mb-2 text-decoration-none so-detail-back"
          @click="router.push({ name: 'shopify-orders' })"
        >
          ← Back to Orders
        </button>
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div class="min-w-0">
            <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
              <h1 class="so-detail-title mb-0">
                Order #{{ formatShopifyOrderName(order.name || order.display_name) || "—" }}
              </h1>
              <button
                type="button"
                class="so-status-pill"
                :class="displayStatusClass(order.display_status)"
                @click="statusPickerOpen = true"
              >
                {{ displayStatusLabel(order.display_status) }}
              </button>
            </div>
            <p class="so-detail-meta mb-0">
              <span v-if="createdLabel" class="so-detail-meta__item">
                <svg class="so-detail-meta__cal" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <rect x="3" y="5" width="18" height="16" rx="2" />
                  <path d="M16 3v4M8 3v4M3 11h18" />
                </svg>
                {{ createdLabel }}
              </span>
              <span v-if="createdLabel" class="so-detail-meta__sep">|</span>
              <span class="so-detail-meta__item">
                Account:
                <RouterLink
                  v-if="order.client_account_id"
                  class="so-detail-meta__account"
                  :to="{ name: 'client-account-detail', params: { id: String(order.client_account_id) } }"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  {{ order.account_name || "—" }}
                </RouterLink>
                <template v-else>{{ order.account_name || "—" }}</template>
              </span>
            </p>
          </div>
          <div class="d-flex flex-wrap align-items-center gap-2">
            <button
              type="button"
              class="btn so-btn-outline fw-semibold d-inline-flex align-items-center gap-2"
              @click="editItemsOpen = true"
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z" />
              </svg>
              Edit Order
            </button>
            <div class="position-relative" data-shopify-order-detail-actions>
              <button
                type="button"
                class="btn so-btn-outline fw-semibold d-inline-flex align-items-center gap-2"
                @click.stop="actionsMenuOpen = !actionsMenuOpen"
              >
                More Actions
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
              </button>
              <div v-if="actionsMenuOpen" class="staff-row-menu so-detail-actions-menu" role="menu" @click.stop>
                <button type="button" class="staff-row-menu__item" role="menuitem" @click="actions.viewInShopify(order); actionsMenuOpen = false">
                  View in Shopify
                </button>
                <button
                  type="button"
                  class="staff-row-menu__item"
                  role="menuitem"
                  @click="actions.syncOrder(order).then(() => load()); actionsMenuOpen = false"
                >
                  Sync From Shopify
                </button>
                <button
                  v-if="canChangeOrderActions(order.display_status) && !isOnHold"
                  type="button"
                  class="staff-row-menu__item"
                  role="menuitem"
                  @click="holdModalOpen = true; actionsMenuOpen = false"
                >
                  Hold Order
                </button>
                <button
                  v-if="canChangeOrderActions(order.display_status)"
                  type="button"
                  class="staff-row-menu__item staff-row-menu__item--danger"
                  role="menuitem"
                  @click="cancelModalOpen = true; actionsMenuOpen = false"
                >
                  Cancel Order
                </button>
                <button
                  v-if="canChangeOrderActions(order.display_status)"
                  type="button"
                  class="staff-row-menu__item"
                  role="menuitem"
                  @click="fulfillModalOpen = true; actionsMenuOpen = false"
                >
                  Mark Fulfilled
                </button>
                <button
                  v-if="isFulfilledStatus(order.display_status)"
                  type="button"
                  class="staff-row-menu__item"
                  role="menuitem"
                  @click="reshipModalOpen = true; actionsMenuOpen = false"
                >
                  Re-Ship Order
                </button>
                <button
                  v-if="canChangeOrderActions(order.display_status)"
                  type="button"
                  class="staff-row-menu__item"
                  role="menuitem"
                  @click="reprocessModalOpen = true; actionsMenuOpen = false"
                >
                  Reprocess Order
                </button>
                <button
                  type="button"
                  class="staff-row-menu__item"
                  role="menuitem"
                  @click="actions.viewPackingSlip(order); actionsMenuOpen = false"
                >
                  View Packing Slip
                </button>
              </div>
            </div>
          </div>
        </div>
      </header>

      <div class="so-detail-grid">
        <div class="so-detail-main">
          <section class="so-card so-card--items mb-3">
            <h2 class="so-card__title so-card__title--with-icon">
              <span class="so-section-icon so-section-icon--items" aria-hidden="true">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg>
              </span>
              <span>Items ({{ lineItems.length }})</span>
            </h2>
            <div class="table-responsive so-items-wrap">
              <table class="table align-middle mb-0 so-items-table">
                <thead>
                  <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Location</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="line in lineItems" :key="line.id">
                    <td>
                      <button type="button" class="so-item-link" @click="openItem(line)">
                        <img v-if="line.image_url" :src="line.image_url" alt="" class="so-item-thumb">
                        <div v-else class="so-item-thumb so-item-thumb--empty" />
                        <span class="text-start">
                          <span class="d-block fw-semibold so-item-link__title">{{ line.title || "Item" }}</span>
                          <span class="d-block small text-secondary">SKU: {{ line.sku || "—" }}</span>
                        </span>
                      </button>
                    </td>
                    <td>{{ line.quantity }}</td>
                    <td>{{ line.location || "—" }}</td>
                    <td class="so-line-status-cell">
                      <div class="so-line-status-menu" data-line-status-menu>
                        <button
                          type="button"
                          class="so-line-status so-line-status-btn"
                          :class="lineStatusClass(line.line_status)"
                          :aria-expanded="lineStatusMenuId === line.id ? 'true' : 'false'"
                          @click.stop="toggleLineStatusMenu(line)"
                        >
                          {{ lineStatusLabel(line.line_status) }}
                          <svg class="so-line-status-btn__chevron" width="10" height="10" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M5.25 7.5L10 12.25 14.75 7.5" />
                          </svg>
                        </button>
                        <div
                          v-if="lineStatusMenuId === line.id"
                          class="so-line-status-menu__panel"
                          role="menu"
                          @click.stop
                        >
                          <button type="button" class="so-line-status-menu__item" role="menuitem" @click="setLineStatus(line, 'cancelled')">Cancel</button>
                          <button type="button" class="so-line-status-menu__item" role="menuitem" @click="setLineStatus(line, 'backorder')">Backorder</button>
                          <button type="button" class="so-line-status-menu__item" role="menuitem" @click="setLineStatus(line, 'fulfilled')">Fulfilled</button>
                        </div>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="!lineItems.length">
                    <td colspan="4" class="text-secondary">No line items.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section class="so-card">
            <h2 class="so-card__title so-card__title--with-icon">
              <span class="so-section-icon so-section-icon--timeline" aria-hidden="true">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </span>
              <span>Timeline</span>
            </h2>
            <ul v-if="timeline.length" class="so-timeline list-unstyled mb-0">
              <li v-for="ev in timeline" :key="ev.id" class="so-timeline__item">
                <span class="so-timeline__icon" :class="timelineIconClass(ev.type)" aria-hidden="true">
                  <svg v-if="timelineGlyph(ev.type) === 'plus'" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3">
                    <path d="M12 5v14M5 12h14" />
                  </svg>
                  <svg v-else-if="timelineGlyph(ev.type) === 'pause'" width="12" height="12" viewBox="0 0 24 24" fill="#fff">
                    <rect x="6" y="5" width="4" height="14" rx="1" />
                    <rect x="14" y="5" width="4" height="14" rx="1" />
                  </svg>
                  <svg v-else-if="timelineGlyph(ev.type) === 'check'" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                  </svg>
                  <svg v-else width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z" />
                  </svg>
                </span>
                <div class="so-timeline__body">
                  <div class="d-flex justify-content-between gap-3">
                    <div class="min-w-0">
                      <div class="fw-semibold text-body">{{ ev.title }}</div>
                      <div class="small text-secondary">{{ formatDetailDateTime(ev.created_at) }}</div>
                      <div v-if="timelineDetailLines(ev.detail).length" class="so-timeline__detail small text-secondary">
                        <div v-for="(line, idx) in timelineDetailLines(ev.detail)" :key="`${ev.id}-d-${idx}`">{{ line }}</div>
                      </div>
                    </div>
                    <div class="small text-secondary text-nowrap">{{ ev.actor_label || "System" }}</div>
                  </div>
                </div>
              </li>
            </ul>
            <p v-else class="text-secondary mb-0 small">No timeline events yet.</p>
          </section>
        </div>

        <aside class="so-detail-side">
          <section class="so-card mb-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h2 class="so-card__title so-card__title--with-icon mb-0">
                <span class="so-section-icon so-section-icon--recipient" aria-hidden="true">
                  <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                  </svg>
                </span>
                <span>Recipient</span>
              </h2>
              <button
                v-if="!isFulfilledStatus(order.display_status)"
                type="button"
                class="btn btn-sm so-btn-outline fw-semibold"
                @click="editAddressOpen = true"
              >
                Edit
              </button>
            </div>
            <div class="fw-bold mb-1 text-body">{{ recipient?.name || order.recipient_name || "—" }}</div>
            <div v-if="formatAddressLines(recipient).length" class="text-body so-recipient-addr">
              <p
                v-for="(line, idx) in formatAddressLines(recipient)"
                :key="'addr-' + idx"
                class="mb-0 so-recipient-addr__line"
              >
                {{ line }}
              </p>
            </div>
            <p v-else class="mb-0 text-body so-recipient-addr">—</p>
          </section>

          <section class="so-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h2 class="so-card__title so-card__title--with-icon mb-0">
                <span class="so-section-icon so-section-icon--shipping" aria-hidden="true">
                  <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m6 0a2 2 0 104 0" />
                  </svg>
                </span>
                <span>Shipping Method</span>
              </h2>
              <button type="button" class="btn btn-sm so-btn-outline fw-semibold" @click="editShippingOpen = true">
                Edit
              </button>
            </div>
            <dl class="so-ship-dl mb-0">
              <div>
                <dt>Requested</dt>
                <dd>{{ shipping?.requested || order.shipping_method || "—" }}</dd>
              </div>
              <div>
                <dt>Carrier</dt>
                <dd class="d-inline-flex align-items-center gap-2">
                  <ShopifyCarrierLogo v-if="shipping?.carrier" :carrier="shipping.carrier" :size="26" />
                  <span>{{ carrierLabel(shipping?.carrier) }}</span>
                </dd>
              </div>
              <div>
                <dt>Service</dt>
                <dd>{{ shipping?.service || "—" }}</dd>
              </div>
              <div>
                <dt>Price</dt>
                <dd class="fw-bold">
                  <template v-if="shipping?.price != null">
                    ${{ Number(shipping.price).toFixed(2) }}
                  </template>
                  <template v-else>—</template>
                </dd>
              </div>
            </dl>
          </section>
        </aside>
      </div>
    </template>

    <ShopifyOrderHoldModal
      :open="holdModalOpen"
      :busy="actions.busy.value"
      :initial-reasons="activeHoldReasons"
      @close="holdModalOpen = false"
      @confirm="confirmHold"
    />
    <ShopifyOrderCancelConfirmModal :open="cancelModalOpen" :busy="actions.busy.value" @close="cancelModalOpen = false" @confirm="confirmCancel" />
    <ShopifyOrderFulfillModal
      :open="fulfillModalOpen"
      :busy="actions.busy.value"
      :order="order"
      :line-items="lineItems"
      @close="fulfillModalOpen = false"
      @confirm="confirmFulfill"
    />
    <ShopifyOrderLineFulfillModal
      :open="lineFulfillOpen"
      :busy="actions.busy.value"
      :line="lineFulfillTarget"
      @close="lineFulfillOpen = false"
      @confirm="confirmLineFulfill"
    />
    <ShopifyOrderReshipModal
      :open="reshipModalOpen"
      :busy="actions.busy.value"
      :order="order"
      :line-items="lineItems"
      @close="reshipModalOpen = false"
      @confirm="confirmReship"
    />
    <ShopifyOrderReprocessModal
      :open="reprocessModalOpen"
      :busy="actions.busy.value"
      :order="order"
      @close="reprocessModalOpen = false"
      @confirm="confirmReprocess"
    />
    <ShopifyOrderStatusPickerModal
      :open="statusPickerOpen"
      :busy="actions.busy.value"
      :order="order"
      @close="statusPickerOpen = false"
      @pick="onStatusPicked"
      @remove-hold="onRemoveHold"
    />
    <ShopifyOrderEditItemsModal
      :open="editItemsOpen"
      :busy="actions.busy.value"
      :order="order"
      :client-account-id="order?.client_account_id"
      @close="editItemsOpen = false"
      @confirm="confirmEditItems"
    />
    <ShopifyOrderEditAddressModal
      :open="editAddressOpen"
      :busy="actions.busy.value"
      :recipient="recipient"
      @close="editAddressOpen = false"
      @confirm="confirmEditAddress"
    />
    <ShopifyOrderEditShippingModal
      :open="editShippingOpen"
      :busy="actions.busy.value"
      :order="order"
      :shipping="shipping"
      @close="editShippingOpen = false"
      @confirm="confirmEditShipping"
    />
  </div>
</template>

<style scoped>
.so-detail-back { color: #2563eb !important; font-weight: 500; }
.so-detail-title { font-size: 1.5rem; font-weight: 700; color: #111827; line-height: 1.2; }
.so-status-pill {
  border: 0;
  border-radius: 999px;
  padding: 0.3rem 0.7rem;
  font-size: 0.78rem;
  font-weight: 600;
  cursor: pointer;
}
.so-status-pill.shopify-order-status--ready {
  background: #dcfce7;
  color: #166534;
}
.so-status-pill.shopify-order-status--draft {
  background: #f1f5f9;
  color: #475569;
}
.so-status-pill.shopify-order-status--hold {
  background: #ffedd5;
  color: #c2410c;
}
.so-status-pill.shopify-order-status--backorder {
  background: #eef2ff;
  color: #3730a3;
}
.so-status-pill.shopify-order-status--shipped {
  background: #ecfdf5;
  color: #047857;
}
.so-status-pill.shopify-order-status--cancelled {
  background: #fee2e2;
  color: #b91c1c;
}
.so-detail-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  align-items: center;
  color: #6b7280;
  font-size: 0.875rem;
}
.so-detail-meta__item { display: inline-flex; align-items: center; gap: 0.35rem; }
.so-detail-meta__cal { color: #9ca3af; }
.so-detail-meta__sep { color: #d1d5db; }
.so-detail-meta__account {
  color: #2563eb;
  font-weight: 600;
  text-decoration: none;
}
.so-detail-meta__account:hover { text-decoration: underline; }
.so-btn-outline {
  border: 1px solid #3b82f6;
  color: #2563eb;
  background: #fff;
}
.so-btn-outline:hover {
  background: #eff6ff;
  color: #1d4ed8;
  border-color: #2563eb;
}
.so-detail-actions-menu { position: absolute; right: 0; top: calc(100% + 4px); z-index: 20; min-width: 12rem; }
.so-detail-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 22rem;
  gap: 1.25rem;
  align-items: start;
}
@media (max-width: 992px) {
  .so-detail-grid { grid-template-columns: 1fr; }
}
.so-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 0.75rem;
  padding: 1.15rem 1.25rem;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}
.so-card__title { font-size: 1.05rem; font-weight: 700; margin: 0 0 0.85rem; color: #111827; }
.so-card__title--with-icon {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
}
.so-section-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border-radius: 0.5rem;
  flex-shrink: 0;
}
.so-section-icon--items,
.so-section-icon--shipping,
.so-section-icon--recipient,
.so-section-icon--timeline {
  color: #2563eb;
  background: rgba(37, 99, 235, 0.1);
}
/* Items: white card, light-gray header band matching mockup */
.so-card--items {
  background: #fff;
  padding: 1.15rem 0 0;
  overflow: visible;
}
.so-card--items .so-card__title {
  padding: 0 1.25rem;
  margin-bottom: 0.75rem;
}
.so-items-wrap {
  background: #fff;
  overflow: visible !important;
}
.so-items-table {
  --bs-table-bg: #fff;
  --bs-table-striped-bg: #fff;
  --bs-table-hover-bg: #fff;
  background: #fff;
}
.so-items-table > :not(caption) > * > * {
  background-color: #fff;
}
.so-items-table thead th {
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #9ca3af;
  border-bottom: 1px solid #e5e7eb;
  font-weight: 600;
  background: #f4f5f6 !important;
  padding: 0.65rem 1.25rem;
}
.so-items-table tbody td {
  background: #fff !important;
  border-bottom-color: #f1f2f4;
  padding: 0.85rem 1.25rem;
  vertical-align: middle;
}
.so-items-table tbody tr:last-child td {
  border-bottom: 0;
}
.so-item-link {
  display: inline-flex;
  align-items: center;
  gap: 0.65rem;
  border: 0;
  background: transparent;
  padding: 0;
  text-align: left;
}
.so-item-link__title { color: #111827; }
.so-item-link:hover .so-item-link__title { color: #2563eb; text-decoration: underline; }
.so-item-thumb {
  width: 44px;
  height: 44px;
  border-radius: 0.45rem;
  object-fit: cover;
  background: #f3f4f6;
  flex-shrink: 0;
}
.so-item-thumb--empty { border: 1px solid #e5e7eb; }
.so-line-status {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.22rem 0.55rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
}
.so-line-status-cell {
  position: relative;
  overflow: visible;
}
.so-line-status-btn {
  border: 0;
  cursor: pointer;
}
.so-line-status-btn__chevron {
  opacity: 0.75;
  flex-shrink: 0;
}
.so-line-status-btn:hover { filter: brightness(0.97); }
.so-line-status--pending { background: #fff7ed; color: #c2410c; border: 1px solid #fdba74; }
.so-line-status--cancelled { background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; }
.so-line-status--fulfilled { background: #ecfdf5; color: #047857; border: 1px solid #6ee7b7; }
.so-line-status--backorder { background: #eef2ff; color: #3730a3; border: 1px solid #c7d2fe; }
.so-line-status-menu { position: relative; display: inline-block; z-index: 5; }
.so-line-status-menu__panel {
  position: absolute;
  z-index: 40;
  top: calc(100% + 0.25rem);
  left: 0;
  min-width: 9.5rem;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 0.55rem;
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12);
  padding: 0.25rem;
}
.so-line-status-menu__item {
  display: block;
  width: 100%;
  text-align: left;
  border: 0;
  background: transparent;
  border-radius: 0.4rem;
  padding: 0.4rem 0.6rem;
  font-size: 0.85rem;
  font-weight: 600;
  color: #111827;
}
.so-line-status-menu__item:hover { background: #f3f4f6; }
.so-recipient-addr { color: #374151; line-height: 1.45; }
.so-recipient-addr__line { margin: 0; }
.so-ship-dl > div {
  display: grid;
  grid-template-columns: 6.5rem 1fr;
  gap: 0.35rem;
  margin-bottom: 0.55rem;
  align-items: center;
}
.so-ship-dl dt { margin: 0; color: #6b7280; font-weight: 500; }
.so-ship-dl dd { margin: 0; color: #111827; }
.so-timeline__item {
  display: grid;
  grid-template-columns: 1.6rem 1fr;
  gap: 0.85rem;
  position: relative;
  padding-bottom: 1.15rem;
}
.so-timeline__item:not(:last-child)::before {
  content: "";
  position: absolute;
  left: 0.7rem;
  top: 1.55rem;
  bottom: 0;
  width: 2px;
  background: #e5e7eb;
}
.so-timeline__icon {
  width: 1.45rem;
  height: 1.45rem;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-top: 0.1rem;
  flex-shrink: 0;
}
.so-timeline__icon--create { background: #3b82f6; }
.so-timeline__icon--hold { background: #f59e0b; }
.so-timeline__icon--edit { background: #8b5cf6; }
.so-timeline__icon--ok { background: #10b981; }
.so-timeline__detail { margin-top: 0.2rem; white-space: normal; }
.so-timeline__detail > div + div { margin-top: 0.12rem; }
</style>
