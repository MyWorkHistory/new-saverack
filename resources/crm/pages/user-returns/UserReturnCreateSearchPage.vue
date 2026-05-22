<script setup>
import { computed, inject, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";

const toast = useToast();
const router = useRouter();
const crmUser = inject("crmUser", ref(null));

const orderNumber = ref("");
const searching = ref(false);
const results = ref([]);

const clientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));

async function search() {
  const q = orderNumber.value.trim();
  if (!q || !clientAccountId.value) return;
  searching.value = true;
  results.value = [];
  try {
    const { data } = await api.get("/orders", {
      params: {
        client_account_id: clientAccountId.value,
        order_number: q,
        per_page: 25,
        page: 1,
      },
    });
    results.value = data.data || [];
    if (!results.value.length) {
      toast.error("No order found for that order number.");
    }
  } catch (e) {
    toast.errorFrom(e, "Could not search orders.");
  } finally {
    searching.value = false;
  }
}

function customerDisplay(row) {
  const ship = row?.shipping_address || row?.ship_to || {};
  const name = [ship.first_name, ship.last_name].filter(Boolean).join(" ").trim();
  if (name) return name;
  if (ship.company) return String(ship.company);
  return row?.customer_name || row?.email || "—";
}

function openOrder(row) {
  const id = row?.id || row?.shiphero_order_id;
  if (!id) return;
  router.push({
    name: "user-return-create-order",
    params: { shipheroOrderId: String(id) },
    query: { client_account_id: String(clientAccountId.value) },
  });
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Create a Return",
    description: "Search for an order to start a return.",
  });
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="mb-4">
      <h1 class="h4 mb-1 fw-semibold text-body">Create a Return</h1>
      <p class="staff-page__intro mb-0">Search by order number, then open the order to start your return.</p>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 mb-4" style="max-width: 36rem">
      <label for="return-order-search" class="form-label fw-semibold small text-secondary">Order #</label>
      <div class="d-flex gap-2 flex-wrap">
        <input
          id="return-order-search"
          v-model="orderNumber"
          type="search"
          class="form-control"
          placeholder="Enter order number"
          autocomplete="off"
          @keydown.enter.prevent="search"
        />
        <button
          type="button"
          class="btn btn-primary staff-page-primary fw-semibold"
          :disabled="searching || !orderNumber.trim()"
          @click="search"
        >
          Search
        </button>
      </div>
    </div>

    <div v-if="searching" class="py-5">
      <CrmLoadingSpinner message="Searching orders…" />
    </div>

    <div v-else-if="results.length" class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th text-center" scope="col">Order #</th>
              <th class="staff-table-head__th text-center" scope="col">Name</th>
              <th class="staff-table-head__th text-center" scope="col">Status</th>
              <th class="staff-table-head__th text-center staff-actions-col" scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in results" :key="row.id">
              <td class="text-center fw-semibold">{{ row.order_number || "—" }}</td>
              <td class="text-center">{{ customerDisplay(row) }}</td>
              <td class="text-center small text-secondary">{{ row.status || row.fulfillment_status || "—" }}</td>
              <td class="text-center">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-secondary orders-toolbar-outline-btn fw-semibold me-1"
                  @click="openOrder(row)"
                >
                  View
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
