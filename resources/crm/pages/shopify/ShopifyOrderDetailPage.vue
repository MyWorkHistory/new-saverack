<script setup>
import { computed, onMounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";
import { formatDateTimeUs } from "../../utils/formatUserDates";

const route = useRoute();
const router = useRouter();
const toast = useToast();
const loading = ref(true);
const order = ref(null);
const shipModalOpen = ref(false);
const shipBusy = ref(false);
const shipForm = reactive({
  tracking_company: "UPS",
  tracking_number: "TEST123456789",
});
const shipRows = ref([]);

const hasShipable = computed(() =>
  (order.value?.fulfillment_orders || []).some((fo) =>
    (fo.line_items || []).some((li) => Number(li.remaining_quantity) > 0),
  ),
);

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

function fulfillmentBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "fulfilled") return "bg-success-subtle text-success-emphasis";
  if (s === "partial" || s === "partially_fulfilled") return "bg-warning-subtle text-warning-emphasis";
  if (s === "unfulfilled" || !s) return "bg-secondary-subtle text-secondary-emphasis";
  return "bg-body-secondary text-body-secondary";
}

function fulfillmentLabel(status) {
  const s = String(status || "").trim();
  if (!s) return "Unfulfilled";
  return s.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

function rebuildShipRows() {
  const rows = [];
  for (const fo of order.value?.fulfillment_orders || []) {
    for (const li of fo.line_items || []) {
      const max = Number(li.remaining_quantity || 0);
      if (max <= 0) continue;
      rows.push({
        fo_line_item_id: li.shopify_fo_line_item_id,
        max,
        quantity: max,
      });
    }
  }
  shipRows.value = rows;
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/shopify/orders/${route.params.id}`);
    order.value = data?.order || null;
    rebuildShipRows();
  } catch (e) {
    toast.errorFrom(e, "Could not load order.");
  } finally {
    loading.value = false;
  }
}

async function submitShip() {
  const items = shipRows.value
    .filter((r) => Number(r.quantity) > 0)
    .map((r) => ({
      fo_line_item_id: String(r.fo_line_item_id),
      quantity: Number(r.quantity),
    }));
  if (!items.length) {
    toast.error("Enter at least one quantity.");
    return;
  }
  shipBusy.value = true;
  try {
    const { data } = await api.post(`/shopify/orders/${route.params.id}/fulfill`, {
      items,
      tracking_company: shipForm.tracking_company,
      tracking_number: shipForm.tracking_number,
    });
    order.value = data?.order || order.value;
    rebuildShipRows();
    shipModalOpen.value = false;
    toast.success(data?.message || "Fulfillment Created.");
  } catch (e) {
    toast.errorFrom(e, "Could not create fulfillment.");
  } finally {
    shipBusy.value = false;
  }
}

watch(shipModalOpen, (open) => {
  if (open) rebuildShipRows();
});

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Shopify Order",
    description: "Shopify order detail and fulfillment.",
  });
  void load();
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
          &lt; Back to Shopify Orders
        </button>
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div class="min-w-0">
            <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
              <h1 class="h4 mb-0 fw-bold text-body">{{ order.name || "Shopify Order" }}</h1>
              <span
                class="badge rounded-pill fw-medium"
                :class="fulfillmentBadgeClass(order.fulfillment_status)"
              >
                {{ fulfillmentLabel(order.fulfillment_status) }}
              </span>
            </div>
            <p
              v-if="orderMetaLine"
              class="small text-secondary mb-0 order-detail-page__hero-meta text-capitalize"
            >
              {{ orderMetaLine }}
            </p>
          </div>
          <div class="d-flex flex-wrap gap-2 align-items-center flex-shrink-0">
            <button
              type="button"
              class="btn btn-primary staff-page-primary fw-semibold"
              :disabled="!hasShipable"
              @click="shipModalOpen = true"
            >
              Mark Shipped
            </button>
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

    <div
      v-if="shipModalOpen"
      class="modal fade show d-block"
      tabindex="-1"
      style="background: rgba(0, 0, 0, 0.35)"
    >
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="modal-title h5 mb-0">Mark Shipped</h2>
            <button
              type="button"
              class="btn-close"
              aria-label="Close"
              @click="shipModalOpen = false"
            />
          </div>
          <div class="modal-body">
            <div class="row g-3 mb-3">
              <div class="col-6">
                <label class="form-label">Carrier</label>
                <input
                  v-model="shipForm.tracking_company"
                  class="form-control"
                />
              </div>
              <div class="col-6">
                <label class="form-label">Tracking</label>
                <input
                  v-model="shipForm.tracking_number"
                  class="form-control"
                />
              </div>
            </div>
            <div
              v-for="row in shipRows"
              :key="row.fo_line_item_id"
              class="d-flex align-items-center justify-content-between gap-2 mb-2"
            >
              <span class="small">FO Line {{ row.fo_line_item_id }} (max {{ row.max }})</span>
              <input
                v-model.number="row.quantity"
                type="number"
                min="0"
                :max="row.max"
                class="form-control form-control-sm"
                style="max-width: 5.5rem"
              />
            </div>
          </div>
          <div class="modal-footer">
            <button
              type="button"
              class="btn btn-outline-secondary orders-toolbar-outline-btn fw-semibold"
              :disabled="shipBusy"
              @click="shipModalOpen = false"
            >
              Cancel
            </button>
            <button
              type="button"
              class="btn btn-primary staff-page-primary fw-semibold"
              :disabled="shipBusy"
              @click="submitShip"
            >
              {{ shipBusy ? "Creating…" : "Create Fulfillment" }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
