<template>
  <div class="staff-page staff-page--wide">
    <div class="mb-3">
      <RouterLink
        class="small text-decoration-none"
        :to="{ name: 'webmaster-shopify-orders' }"
      >
        ← Shopify Orders
      </RouterLink>
      <h1 class="h4 mb-1 mt-2">{{ order?.name || "Shopify Order" }}</h1>
      <p class="small text-secondary mb-0 text-capitalize">
        {{ order?.fulfillment_status || "—" }} · {{ order?.financial_status || "—" }}
        <span v-if="order?.account_name"> · {{ order.account_name }}</span>
      </p>
    </div>

    <div
      v-if="loading"
      class="p-4"
    >
      <CrmLoadingSpinner label="Loading Order…" />
    </div>

    <template v-else-if="order">
      <div class="staff-table-card staff-datatable-card staff-datatable-card--white mb-3">
        <div class="p-3 border-bottom d-flex flex-wrap justify-content-between gap-2">
          <strong>Line Items</strong>
          <button
            type="button"
            class="btn btn-sm btn-primary"
            :disabled="!hasShipable"
            @click="shipModalOpen = true"
          >
            Mark Shipped
          </button>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>SKU</th>
                <th>Title</th>
                <th>Qty</th>
                <th>Fulfilled</th>
                <th>Fulfillable</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="line in order.line_items || []"
                :key="line.id"
              >
                <td>{{ line.sku || "—" }}</td>
                <td>{{ line.title }} {{ line.variant_title ? ` / ${line.variant_title}` : "" }}</td>
                <td>{{ line.quantity }}</td>
                <td>{{ line.fulfilled_quantity }}</td>
                <td>{{ line.fulfillable_quantity }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="staff-table-card staff-datatable-card staff-datatable-card--white mb-3">
        <div class="p-3 border-bottom"><strong>Fulfillment Orders</strong></div>
        <div
          v-for="fo in order.fulfillment_orders || []"
          :key="fo.id"
          class="p-3 border-bottom"
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
        <div class="p-3 border-bottom"><strong>Fulfillments</strong></div>
        <div
          v-if="!(order.fulfillments || []).length"
          class="p-3 small text-secondary"
        >
          No CRM fulfillments yet.
        </div>
        <ul
          v-else
          class="list-unstyled mb-0 p-3 small"
        >
          <li
            v-for="f in order.fulfillments"
            :key="f.id"
            class="mb-2"
          >
            {{ f.tracking_company }} {{ f.tracking_number }}
            <span class="text-secondary">· {{ f.status }} · {{ formatDateTimeUs(f.created_at) }}</span>
          </li>
        </ul>
      </div>
    </template>

    <div
      v-if="shipModalOpen"
      class="modal fade show d-block"
      tabindex="-1"
      style="background: rgba(0, 0, 0, 0.35)"
    >
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="modal-title h5">Mark Shipped</h2>
            <button
              type="button"
              class="btn-close"
              @click="shipModalOpen = false"
            />
          </div>
          <div class="modal-body">
            <div class="row g-2 mb-3">
              <div class="col-6">
                <label class="form-label small">Carrier</label>
                <input
                  v-model="shipForm.tracking_company"
                  class="form-control form-control-sm"
                />
              </div>
              <div class="col-6">
                <label class="form-label small">Tracking</label>
                <input
                  v-model="shipForm.tracking_number"
                  class="form-control form-control-sm"
                />
              </div>
            </div>
            <div
              v-for="row in shipRows"
              :key="row.fo_line_item_id"
              class="d-flex align-items-center justify-content-between gap-2 mb-2 small"
            >
              <span>FO line {{ row.fo_line_item_id }} (max {{ row.max }})</span>
              <input
                v-model.number="row.quantity"
                type="number"
                min="0"
                :max="row.max"
                class="form-control form-control-sm"
                style="max-width: 5rem"
              />
            </div>
          </div>
          <div class="modal-footer">
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm"
              @click="shipModalOpen = false"
            >
              Cancel
            </button>
            <button
              type="button"
              class="btn btn-primary btn-sm"
              :disabled="shipBusy"
              @click="submitShip"
            >
              Create Fulfillment
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";
import { formatDateTimeUs } from "../../utils/formatUserDates";

const route = useRoute();
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
