<script setup>
import { onMounted } from "vue";
import { RouterLink } from "vue-router";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmRefreshToolbarButton from "../../components/common/CrmRefreshToolbarButton.vue";
import ClientAccountShippingStatusIcon from "../../components/clients/ClientAccountShippingStatusIcon.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useAdminHomeDashboard } from "../../composables/useAdminHomeDashboard.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateTimeUs } from "../../utils/formatUserDates.js";

const toast = useToast();

const { loading, refreshing, sections, load, refreshSection } = useAdminHomeDashboard({
  onError: (e) => toast.errorFrom(e, "Could not load fulfillment dashboard."),
});

const SECTION_READY = "ready_to_ship";
const SECTION_SHIPPED = "shipped";

function sectionData(key) {
  return (
    sections.value?.[key] || {
      accounts: [],
      total_count: 0,
      status: "idle",
      refreshed_at: null,
      truncated: false,
    }
  );
}

function lastUpdatedLabel(key) {
  const at = sectionData(key).refreshed_at;
  if (!at) return "Not refreshed yet";
  return formatDateTimeUs(at);
}

function isSectionRefreshing(key) {
  const s = sectionData(key);
  return s.status === "running" || refreshing.value;
}

function ordersAwaitingRoute(accountId) {
  return {
    name: "orders-awaiting",
    query: { client_account_id: String(accountId) },
  };
}

function ordersShippedRoute(accountId) {
  return {
    name: "orders-shipped",
    query: { client_account_id: String(accountId) },
  };
}

function refreshToastMessage(data, fallbackQueued) {
  if (data?.refresh_index_only) {
    return "Counts updated from database. Full sync queued if index was empty.";
  }
  if (data?.refresh_synced) {
    return "Section refreshed.";
  }
  if (data?.refresh_enqueued || fallbackQueued) {
    return "Refresh queued — counts will update shortly.";
  }
  return "Refresh started.";
}

async function onRefreshAll() {
  try {
    await refreshSection(SECTION_READY, { sync: true });
    const data = await refreshSection(SECTION_SHIPPED, { sync: true });
    toast.success(refreshToastMessage(data, false));
  } catch {
    /* toast handled */
  }
}

async function onRefreshSection(key) {
  try {
    const data = await refreshSection(key, { sync: true });
    toast.success(refreshToastMessage(data, false));
  } catch {
    /* toast handled */
  }
}

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Orders | Fulfillment",
    description: "Ready to ship and shipped orders by account.",
  });
  try {
    await load();
  } catch {
    /* toast handled */
  }
});
</script>

<template>
  <div class="staff-page staff-page--wide admin-fulfillment-page">
    <div class="mb-4 d-flex align-items-center justify-content-between gap-2 flex-wrap">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Fulfillment</h1>
        <p class="text-secondary small mb-0">Ready to ship and shipped orders by account.</p>
      </div>
      <CrmRefreshToolbarButton
        :disabled="loading || refreshing"
        :loading="refreshing"
        label="Refresh All"
        title="Refresh ready to ship and shipped"
        @click="onRefreshAll"
      />
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading fulfillment…" :center="true" />
    </div>

    <div v-else class="row g-3">
      <div class="col-12 col-lg-6">
        <section
          class="staff-table-card staff-datatable-card staff-datatable-card--white w-100 h-100"
        >
          <div class="staff-table-toolbar">
            <div
              class="staff-table-toolbar--row d-flex align-items-start justify-content-between gap-2"
            >
              <div>
                <h2 class="staff-user-section-title mb-1">Ready to Ship</h2>
                <p class="small text-secondary mb-0">
                  Last updated: {{ lastUpdatedLabel(SECTION_READY) }}
                </p>
              </div>
              <CrmRefreshToolbarButton
                :disabled="isSectionRefreshing(SECTION_READY)"
                :loading="isSectionRefreshing(SECTION_READY)"
                @click="onRefreshSection(SECTION_READY)"
              />
            </div>
          </div>
          <div class="table-responsive staff-table-wrap">
            <table class="table table-hover align-middle mb-0 staff-data-table">
              <thead class="table-light staff-table-head">
                <tr>
                  <th class="staff-table-head__th" scope="col">Account</th>
                  <th class="staff-table-head__th text-end" scope="col" style="width: 6rem">
                    Orders
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in sectionData(SECTION_READY).accounts"
                  :key="`rts-${row.account_id}`"
                >
                  <td>
                    <div class="d-flex align-items-center gap-2 min-w-0">
                      <ClientAccountShippingStatusIcon
                        :status="row.account_status"
                        :size="18"
                      />
                      <RouterLink
                        :to="ordersAwaitingRoute(row.account_id)"
                        class="text-truncate text-decoration-none fw-semibold"
                      >
                        {{ row.account_name }}
                      </RouterLink>
                    </div>
                  </td>
                  <td class="text-end fw-semibold tabular-nums">
                    {{ Number(row.orders_count || 0).toLocaleString() }}
                  </td>
                </tr>
                <tr v-if="!sectionData(SECTION_READY).accounts.length">
                  <td colspan="2" class="text-secondary small py-4 text-center">
                    No ready-to-ship orders in snapshot.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <div class="col-12 col-lg-6">
        <section
          class="staff-table-card staff-datatable-card staff-datatable-card--white w-100 h-100"
        >
          <div class="staff-table-toolbar">
            <div
              class="staff-table-toolbar--row d-flex align-items-start justify-content-between gap-2"
            >
              <div>
                <h2 class="staff-user-section-title mb-1">Shipped</h2>
                <p class="small text-secondary mb-0">
                  Last updated: {{ lastUpdatedLabel(SECTION_SHIPPED) }}
                  <span class="text-body-secondary">(today)</span>
                </p>
              </div>
              <CrmRefreshToolbarButton
                :disabled="isSectionRefreshing(SECTION_SHIPPED)"
                :loading="isSectionRefreshing(SECTION_SHIPPED)"
                @click="onRefreshSection(SECTION_SHIPPED)"
              />
            </div>
          </div>
          <div class="table-responsive staff-table-wrap">
            <table class="table table-hover align-middle mb-0 staff-data-table">
              <thead class="table-light staff-table-head">
                <tr>
                  <th class="staff-table-head__th" scope="col">Account</th>
                  <th class="staff-table-head__th text-end" scope="col" style="width: 6rem">
                    Orders
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in sectionData(SECTION_SHIPPED).accounts"
                  :key="`shp-${row.account_id}`"
                >
                  <td>
                    <div class="d-flex align-items-center gap-2 min-w-0">
                      <ClientAccountShippingStatusIcon
                        :status="row.account_status"
                        :size="18"
                      />
                      <RouterLink
                        :to="ordersShippedRoute(row.account_id)"
                        class="text-truncate text-decoration-none fw-semibold"
                      >
                        {{ row.account_name }}
                      </RouterLink>
                    </div>
                  </td>
                  <td class="text-end fw-semibold tabular-nums">
                    {{ Number(row.orders_count || 0).toLocaleString() }}
                  </td>
                </tr>
                <tr v-if="!sectionData(SECTION_SHIPPED).accounts.length">
                  <td colspan="2" class="text-secondary small py-4 text-center">
                    No shipments in snapshot for today.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>

<style scoped>
.tabular-nums {
  font-variant-numeric: tabular-nums;
}
</style>
