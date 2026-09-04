<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ShopifyOrderCancelConfirmModal from "../../components/shopify/ShopifyOrderCancelConfirmModal.vue";
import ShopifyOrderEditAddressModal from "../../components/shopify/ShopifyOrderEditAddressModal.vue";
import ShopifyOrderEditItemsModal from "../../components/shopify/ShopifyOrderEditItemsModal.vue";
import ShopifyOrderEditShippingModal from "../../components/shopify/ShopifyOrderEditShippingModal.vue";
import ShopifyOrderFulfillModal from "../../components/shopify/ShopifyOrderFulfillModal.vue";
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
import { formatDateTimeUs } from "../../utils/formatUserDates";

const route = useRoute();
const router = useRouter();
const toast = useToast();
const loading = ref(true);
const order = ref(null);
const actionsMenuOpen = ref(false);
const holdModalOpen = ref(false);
const cancelModalOpen = ref(false);
const fulfillModalOpen = ref(false);
const reshipModalOpen = ref(false);
const reprocessModalOpen = ref(false);
const statusPickerOpen = ref(false);
const editItemsOpen = ref(false);
const editAddressOpen = ref(false);
const editShippingOpen = ref(false);

const orderId = computed(() => Number(route.params.id || 0));
const lineItems = computed(() => (Array.isArray(order.value?.line_items) ? order.value.line_items : []));
const timeline = computed(() => (Array.isArray(order.value?.timeline) ? order.value.timeline : []));
const recipient = computed(() => order.value?.recipient || null);
const shipping = computed(() => order.value?.shipping || null);

const createdLabel = computed(() => {
  const raw = order.value?.shopify_created_at;
  if (!raw) return "";
  return formatDateTimeUs(raw);
});

function canChangeOrderActions(status) {
  return !isFulfilledStatus(status) && !isCancelledStatus(status);
}

const actions = useShopifyOrderActions({
  onUpdated: (updated) => {
    if (updated?.id) {
      order.value = { ...order.value, ...updated };
    }
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
}

function openItem(line) {
  if (line?.crm_variant_id) {
    router.push({ name: "shopify-inventory-detail", params: { id: line.crm_variant_id } });
    return;
  }
  toast.error("Product is not in CRM inventory.");
}

function lineStatusClass(status) {
  if (status === "cancelled") return "so-line-status so-line-status--cancelled";
  if (status === "fulfilled") return "so-line-status so-line-status--fulfilled";
  return "so-line-status so-line-status--pending";
}

function lineStatusLabel(status) {
  if (status === "cancelled") return "Cancelled";
  if (status === "fulfilled") return "Fulfilled";
  return "Pending";
}

function timelineIconClass(type) {
  if (type === "order_hold") return "so-timeline__icon so-timeline__icon--hold";
  if (type === "order_edited" || type === "address_updated" || type === "shipping_updated" || type === "items_updated") {
    return "so-timeline__icon so-timeline__icon--edit";
  }
  if (type === "order_fulfill" || type === "ready_to_ship") return "so-timeline__icon so-timeline__icon--ok";
  return "so-timeline__icon so-timeline__icon--create";
}

function formatAddress(r) {
  if (!r) return "";
  const parts = [r.address1, r.address2, [r.city, r.province, r.zip].filter(Boolean).join(", "), r.country]
    .map((p) => String(p || "").trim())
    .filter(Boolean);
  return parts.join(", ");
}

function carrierLabel(code) {
  const c = String(code || "").toUpperCase();
  if (c === "FEDEX") return "FedEx";
  return c || "—";
}

async function confirmHold(reasons) {
  if (!orderId.value) return;
  const result = await actions.holdOrder([orderId.value], reasons);
  if (result) {
    holdModalOpen.value = false;
    await load();
  }
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
            <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
              <h1 class="h4 mb-0 fw-bold text-body">
                Order #{{ formatShopifyOrderName(order.name || order.display_name) || "—" }}
              </h1>
              <button
                type="button"
                class="badge rounded-pill fw-medium shopify-order-status shopify-order-status--clickable border-0"
                :class="displayStatusClass(order.display_status)"
                @click="statusPickerOpen = true"
              >
                {{ displayStatusLabel(order.display_status) }}
              </button>
            </div>
            <p class="small text-secondary mb-0 so-detail-meta">
              <span v-if="createdLabel">{{ createdLabel }}</span>
              <span v-if="createdLabel" class="so-detail-meta__sep">|</span>
              <span>Sales Channel: Shopify</span>
            </p>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <button
              type="button"
              class="btn btn-outline-secondary orders-toolbar-outline-btn fw-semibold d-inline-flex align-items-center gap-2"
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
                class="btn btn-outline-secondary orders-toolbar-outline-btn fw-semibold d-inline-flex align-items-center gap-2"
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
                  v-if="canChangeOrderActions(order.display_status)"
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
          <section class="so-card mb-3">
            <h2 class="so-card__title">Items ({{ lineItems.length }})</h2>
            <div class="table-responsive">
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
                    <td>
                      <span :class="lineStatusClass(line.line_status)">{{ lineStatusLabel(line.line_status) }}</span>
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
            <h2 class="so-card__title">Timeline</h2>
            <ul v-if="timeline.length" class="so-timeline list-unstyled mb-0">
              <li v-for="ev in timeline" :key="ev.id" class="so-timeline__item">
                <span :class="timelineIconClass(ev.type)" aria-hidden="true" />
                <div class="so-timeline__body">
                  <div class="d-flex justify-content-between gap-3">
                    <div>
                      <div class="fw-semibold">{{ ev.title }}</div>
                      <div class="small text-secondary">
                        {{ formatDateTimeUs(ev.created_at) }}
                        <template v-if="ev.detail"> · {{ ev.detail }}</template>
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
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h2 class="so-card__title mb-0">Recipient</h2>
              <button type="button" class="btn btn-sm btn-outline-secondary orders-toolbar-outline-btn fw-semibold" @click="editAddressOpen = true">
                Edit
              </button>
            </div>
            <div class="fw-semibold mb-1">{{ recipient?.name || order.recipient_name || "—" }}</div>
            <p class="mb-2 text-body">{{ formatAddress(recipient) || "—" }}</p>
            <div v-if="recipient?.email || order.email" class="small text-secondary mb-1">{{ recipient?.email || order.email }}</div>
            <div v-if="recipient?.phone" class="small text-secondary">{{ recipient.phone }}</div>
          </section>

          <section class="so-card">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h2 class="so-card__title mb-0">Shipping Method</h2>
              <button type="button" class="btn btn-sm btn-outline-secondary orders-toolbar-outline-btn fw-semibold" @click="editShippingOpen = true">
                Edit
              </button>
            </div>
            <dl class="so-ship-dl mb-0">
              <div><dt>Requested</dt><dd>{{ shipping?.requested || order.shipping_method || "—" }}</dd></div>
              <div><dt>Carrier</dt><dd>{{ carrierLabel(shipping?.carrier) }}</dd></div>
              <div><dt>Service</dt><dd>{{ shipping?.service || "—" }}</dd></div>
              <div>
                <dt>Price</dt>
                <dd class="fw-semibold">
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

    <ShopifyOrderHoldModal :open="holdModalOpen" :busy="actions.busy.value" @close="holdModalOpen = false" @confirm="confirmHold" />
    <ShopifyOrderCancelConfirmModal :open="cancelModalOpen" :busy="actions.busy.value" @close="cancelModalOpen = false" @confirm="confirmCancel" />
    <ShopifyOrderFulfillModal
      :open="fulfillModalOpen"
      :busy="actions.busy.value"
      :order="order"
      :line-items="lineItems"
      @close="fulfillModalOpen = false"
      @confirm="confirmFulfill"
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
.so-detail-back { color: #2563eb !important; }
.so-detail-meta { display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center; }
.so-detail-meta__sep { color: #cbd5e1; }
.so-detail-actions-menu { position: absolute; right: 0; top: calc(100% + 4px); z-index: 20; min-width: 12rem; }
.so-detail-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 20rem;
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
  padding: 1.1rem 1.2rem;
}
.so-card__title { font-size: 1.05rem; font-weight: 700; margin: 0 0 0.85rem; }
.so-items-table thead th {
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #9ca3af;
  border-bottom-color: #e5e7eb;
  font-weight: 600;
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
  width: 42px;
  height: 42px;
  border-radius: 0.4rem;
  object-fit: cover;
  background: #f3f4f6;
  flex-shrink: 0;
}
.so-item-thumb--empty { border: 1px solid #e5e7eb; }
.so-line-status {
  display: inline-block;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
}
.so-line-status--pending { background: #fff7ed; color: #c2410c; border: 1px solid #fdba74; }
.so-line-status--cancelled { background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; }
.so-line-status--fulfilled { background: #ecfdf5; color: #047857; border: 1px solid #6ee7b7; }
.so-ship-dl > div { display: grid; grid-template-columns: 6.5rem 1fr; gap: 0.35rem; margin-bottom: 0.45rem; }
.so-ship-dl dt { margin: 0; color: #6b7280; font-weight: 500; }
.so-ship-dl dd { margin: 0; }
.so-timeline__item {
  display: grid;
  grid-template-columns: 1.5rem 1fr;
  gap: 0.75rem;
  position: relative;
  padding-bottom: 1.1rem;
}
.so-timeline__item:not(:last-child)::before {
  content: "";
  position: absolute;
  left: 0.65rem;
  top: 1.4rem;
  bottom: 0;
  width: 1px;
  background: #e5e7eb;
}
.so-timeline__icon {
  width: 1.35rem;
  height: 1.35rem;
  border-radius: 999px;
  display: inline-block;
  margin-top: 0.15rem;
}
.so-timeline__icon--create { background: #3b82f6; }
.so-timeline__icon--hold { background: #f59e0b; }
.so-timeline__icon--edit { background: #8b5cf6; }
.so-timeline__icon--ok { background: #10b981; }
</style>
