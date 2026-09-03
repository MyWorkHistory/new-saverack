<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ShopifyOrderCancelConfirmModal from "../../components/shopify/ShopifyOrderCancelConfirmModal.vue";
import ShopifyOrderFulfillModal from "../../components/shopify/ShopifyOrderFulfillModal.vue";
import ShopifyOrderHoldModal from "../../components/shopify/ShopifyOrderHoldModal.vue";
import ShopifyOrderReprocessModal from "../../components/shopify/ShopifyOrderReprocessModal.vue";
import ShopifyOrderReshipModal from "../../components/shopify/ShopifyOrderReshipModal.vue";
import ShopifyOrderStatusPickerModal from "../../components/shopify/ShopifyOrderStatusPickerModal.vue";
import {
  displayStatusClass,
  displayStatusLabel,
  formatShopifyOrderName,
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

const orderMetaLine = computed(() => {
  const parts = [];
  if (order.value?.financial_status) {
    parts.push(String(order.value.financial_status).replace(/_/g, " "));
  }
  if (order.value?.account_name) {
    parts.push(order.value.account_name);
  }
  return parts.join(" · ");
});

const orderId = computed(() => Number(route.params.id || 0));

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
    <div
      v-if="loading"
      class="p-5 d-flex justify-content-center"
    >
      <CrmLoadingSpinner message="Loading Order…" />
    </div>

    <div
      v-else-if="!order"
      class="alert alert-warning mb-4"
    >
      No order data loaded. Check the link and try again.
    </div>

    <template v-else>
      <header class="order-detail-page__hero mb-4">
        <button
          type="button"
          class="btn btn-link btn-sm text-secondary px-0 py-0 mb-2 text-decoration-none order-detail-page__back-link"
          @click="router.push({ name: 'shopify-orders' })"
        >
          &lt; Back to Orders
        </button>
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div class="min-w-0">
            <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
              <h1 class="h4 mb-0 fw-bold text-body">{{ formatShopifyOrderName(order.name) || "Shopify Order" }}</h1>
              <button
                type="button"
                class="badge rounded-pill fw-medium shopify-order-status shopify-order-status--clickable border-0"
                :class="displayStatusClass(order.display_status)"
                @click="statusPickerOpen = true"
              >
                {{ displayStatusLabel(order.display_status) }}
              </button>
            </div>
            <p
              v-if="orderMetaLine"
              class="small text-secondary mb-0 order-detail-page__hero-meta text-capitalize"
            >
              {{ orderMetaLine }}
            </p>
          </div>
          <div
            class="position-relative flex-shrink-0"
            data-shopify-order-detail-actions
          >
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn orders-toolbar-outline-btn d-inline-flex align-items-center gap-2 fw-semibold"
              @click.stop="actionsMenuOpen = !actionsMenuOpen"
            >
              Actions
              <svg
                width="14"
                height="14"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                viewBox="0 0 24 24"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M19 9l-7 7-7-7"
                />
              </svg>
            </button>
            <div
              v-if="actionsMenuOpen"
              class="dropdown-menu show shadow-sm"
              style="position: absolute; top: 100%; right: 0; min-width: 12rem; z-index: 20"
              @click.stop
            >
              <button
                type="button"
                class="dropdown-item"
                @click="actions.viewInShopify(order); actionsMenuOpen = false"
              >
                View in Shopify
              </button>
              <button
                type="button"
                class="dropdown-item"
                @click="actions.syncOrder(order); actionsMenuOpen = false"
              >
                Sync From Shopify
              </button>
              <button
                v-if="!isFulfilledStatus(order.display_status)"
                type="button"
                class="dropdown-item"
                @click="holdModalOpen = true; actionsMenuOpen = false"
              >
                Hold Order
              </button>
              <button
                v-if="!isFulfilledStatus(order.display_status)"
                type="button"
                class="dropdown-item"
                @click="cancelModalOpen = true; actionsMenuOpen = false"
              >
                Cancel Order
              </button>
              <button
                v-if="!isFulfilledStatus(order.display_status)"
                type="button"
                class="dropdown-item"
                @click="fulfillModalOpen = true; actionsMenuOpen = false"
              >
                Mark Fulfilled
              </button>
              <button
                v-if="isFulfilledStatus(order.display_status)"
                type="button"
                class="dropdown-item"
                @click="reshipModalOpen = true; actionsMenuOpen = false"
              >
                Re-Ship Order
              </button>
              <button
                v-if="!isFulfilledStatus(order.display_status)"
                type="button"
                class="dropdown-item"
                @click="reprocessModalOpen = true; actionsMenuOpen = false"
              >
                Reprocess Order
              </button>
              <button
                type="button"
                class="dropdown-item"
                @click="actions.viewPackingSlip(order); actionsMenuOpen = false"
              >
                View Packing Slip
              </button>
            </div>
          </div>
        </div>
      </header>

      <div class="staff-table-card staff-datatable-card staff-datatable-card--white mb-3">
        <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
          <strong class="text-body">Line Items</strong>
        </div>
        <div class="table-responsive staff-table-wrap">
          <table class="table table-hover align-middle mb-0 staff-data-table">
            <thead class="table-light staff-table-head">
              <tr>
                <th
                  class="staff-table-head__th"
                  scope="col"
                >SKU</th>
                <th
                  class="staff-table-head__th"
                  scope="col"
                >Title</th>
                <th
                  class="staff-table-head__th text-end"
                  scope="col"
                >Qty</th>
                <th
                  class="staff-table-head__th text-end"
                  scope="col"
                >Fulfilled</th>
                <th
                  class="staff-table-head__th text-end"
                  scope="col"
                >Fulfillable</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-if="!(order.line_items || []).length"
              >
                <td
                  colspan="5"
                  class="px-4 py-4 text-center text-secondary"
                >
                  No line items.
                </td>
              </tr>
              <tr
                v-for="line in order.line_items || []"
                :key="line.id"
                class="align-middle"
              >
                <td class="fw-semibold text-body">{{ line.sku || "—" }}</td>
                <td class="text-body">
                  {{ line.title }}{{ line.variant_title ? ` / ${line.variant_title}` : "" }}
                </td>
                <td class="text-end">{{ line.quantity }}</td>
                <td class="text-end">{{ line.fulfilled_quantity }}</td>
                <td class="text-end">{{ line.fulfillable_quantity }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="staff-table-card staff-datatable-card staff-datatable-card--white mb-3">
        <div class="px-4 py-3 border-bottom">
          <strong class="text-body">Fulfillment Orders</strong>
        </div>
        <div
          v-if="!(order.fulfillment_orders || []).length"
          class="px-4 py-4 small text-secondary"
        >
          No fulfillment orders.
        </div>
        <div
          v-for="fo in order.fulfillment_orders || []"
          :key="fo.id"
          class="px-4 py-3 border-bottom"
        >
          <div class="small text-secondary mb-2 text-capitalize">
            FO {{ fo.shopify_fulfillment_order_id }} · {{ fo.status || "—" }}
          </div>
          <ul class="mb-0 small">
            <li
              v-for="li in fo.line_items || []"
              :key="li.id"
            >
              Line {{ li.shopify_line_item_id }} — remaining {{ li.remaining_quantity }} /
              {{ li.total_quantity }}
            </li>
          </ul>
        </div>
      </div>

      <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
        <div class="px-4 py-3 border-bottom">
          <strong class="text-body">Fulfillments</strong>
        </div>
        <div
          v-if="!(order.fulfillments || []).length"
          class="px-4 py-4 small text-secondary"
        >
          No CRM fulfillments yet.
        </div>
        <div
          v-else
          class="table-responsive staff-table-wrap"
        >
          <table class="table table-hover align-middle mb-0 staff-data-table">
            <thead class="table-light staff-table-head">
              <tr>
                <th
                  class="staff-table-head__th"
                  scope="col"
                >Carrier</th>
                <th
                  class="staff-table-head__th"
                  scope="col"
                >Tracking</th>
                <th
                  class="staff-table-head__th text-center"
                  scope="col"
                >Status</th>
                <th
                  class="staff-table-head__th text-center"
                  scope="col"
                >Created</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="f in order.fulfillments"
                :key="f.id"
                class="align-middle"
              >
                <td>{{ f.tracking_company || "—" }}</td>
                <td class="fw-semibold">{{ f.tracking_number || "—" }}</td>
                <td class="text-center text-capitalize">{{ f.status || "—" }}</td>
                <td class="text-center text-body staff-table-cell__meta text-nowrap">
                  {{ formatDateTimeUs(f.created_at) || "—" }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <ShopifyOrderHoldModal
      :open="holdModalOpen"
      :busy="actions.busy.value"
      :order-count="1"
      @close="holdModalOpen = false"
      @confirm="confirmHold"
    />
    <ShopifyOrderCancelConfirmModal
      :open="cancelModalOpen"
      :busy="actions.busy.value"
      :order-count="1"
      @close="cancelModalOpen = false"
      @confirm="confirmCancel"
    />
    <ShopifyOrderFulfillModal
      :open="fulfillModalOpen"
      :busy="actions.busy.value"
      :order="order"
      :line-items="order?.line_items || []"
      @close="fulfillModalOpen = false"
      @confirm="confirmFulfill"
    />
    <ShopifyOrderReshipModal
      :open="reshipModalOpen"
      :busy="actions.busy.value"
      :order="order"
      :line-items="order?.line_items || []"
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
  </div>
</template>

<style scoped>
.shopify-order-status {
  font-size: 0.75rem;
}

.shopify-order-status--ready {
  background: #dcfce7;
  color: #166534;
}

.shopify-order-status--hold {
  background: #ffedd5;
  color: #c2410c;
}

.shopify-order-status--backorder {
  background: #ede9fe;
  color: #6d28d9;
}

.shopify-order-status--shipped {
  background: #dbeafe;
  color: #1d4ed8;
}

.shopify-order-status--clickable {
  cursor: pointer;
}
</style>
