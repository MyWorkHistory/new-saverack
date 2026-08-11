<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
      <div>
        <h1 class="h4 mb-1">Shopify Orders</h1>
        <p class="text-secondary small mb-0">
          Orders from connected Shopify test store(s). Webhooks update in near real-time; backup sync runs every 5 minutes.
        </p>
      </div>
      <button
        type="button"
        class="btn btn-outline-secondary btn-sm"
        :disabled="loading"
        @click="load"
      >
        Refresh
      </button>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div class="staff-table-toolbar d-flex flex-wrap gap-2 align-items-center p-3 border-bottom">
        <input
          v-model="q"
          type="search"
          class="form-control form-control-sm"
          style="max-width: 16rem"
          placeholder="Search name, email…"
          @keydown.enter.prevent="load"
        />
        <select
          v-model="fulfillmentStatus"
          class="form-select form-select-sm"
          style="max-width: 12rem"
          @change="load"
        >
          <option value="all">All Fulfillment</option>
          <option value="unfulfilled">Unfulfilled</option>
          <option value="partial">Partial</option>
          <option value="fulfilled">Fulfilled</option>
        </select>
        <button
          type="button"
          class="btn btn-sm btn-primary"
          @click="load"
        >
          Search
        </button>
      </div>

      <div
        v-if="loading"
        class="p-4"
      >
        <CrmLoadingSpinner label="Loading Orders…" />
      </div>
      <div
        v-else-if="!rows.length"
        class="p-4 text-secondary small"
      >
        No Shopify orders yet. Connect a store under Account → Settings and import, or create an order in Shopify.
      </div>
      <div
        v-else
        class="table-responsive"
      >
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Order</th>
              <th>Account</th>
              <th>Financial</th>
              <th>Fulfillment</th>
              <th>Total</th>
              <th>Created</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in rows"
              :key="row.id"
            >
              <td class="fw-semibold">{{ row.name }}</td>
              <td>{{ row.account_name || "—" }}</td>
              <td class="text-capitalize">{{ row.financial_status || "—" }}</td>
              <td class="text-capitalize">{{ row.fulfillment_status || "—" }}</td>
              <td>{{ row.total_price != null ? `${row.currency || ""} ${row.total_price}` : "—" }}</td>
              <td class="small text-secondary">{{ formatDateTimeUs(row.shopify_created_at) }}</td>
              <td class="text-end">
                <RouterLink
                  class="btn btn-sm btn-outline-primary"
                  :to="{ name: 'webmaster-shopify-order-detail', params: { id: String(row.id) } }"
                >
                  Open
                </RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from "vue";
import { RouterLink } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";
import { formatDateTimeUs } from "../../utils/formatUserDates";

const toast = useToast();
const loading = ref(false);
const rows = ref([]);
const q = ref("");
const fulfillmentStatus = ref("all");

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get("/shopify/orders", {
      params: {
        q: q.value || undefined,
        fulfillment_status: fulfillmentStatus.value,
        per_page: 50,
      },
    });
    rows.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load Shopify orders.");
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Shopify Orders",
    description: "Shopify orders from connected stores.",
  });
  void load();
});
</script>
