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
  onError: (e) => toast.errorFrom(e, "Could not load on-hold overview."),
});

const HOLD_SECTIONS = [
  { key: "hold_operator", label: "Operator Hold", holdReason: "operator" },
  { key: "hold_address", label: "Address Hold", holdReason: "address" },
  { key: "hold_fraud", label: "Fraud Hold", holdReason: "fraud" },
  { key: "hold_payment", label: "Payment Hold", holdReason: "payment" },
  { key: "hold_user", label: "User Hold", holdReason: "user" },
  { key: "hold_backorder", label: "Backorder", holdReason: null },
];

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

function ordersHoldRoute(accountId, holdReason) {
  if (holdReason === null) {
    return {
      name: "orders-out-of-stock",
      query: { client_account_id: String(accountId) },
    };
  }
  return {
    name: "orders-on-hold-old",
    query: {
      client_account_id: String(accountId),
      hold_reason: holdReason,
    },
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
    for (const hold of HOLD_SECTIONS) {
      await refreshSection(hold.key, { sync: true });
    }
    toast.success("All hold sections refreshed.");
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
    title: "Save Rack | Orders | On-Hold",
    description: "On-hold orders by account and hold type.",
  });
  try {
    await load();
  } catch {
    /* toast handled */
  }
});
</script>

<template>
  <div class="staff-page staff-page--wide orders-on-hold-overview">
    <div class="mb-4 d-flex align-items-center justify-content-between gap-2 flex-wrap">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body staff-page__heading">On-Hold</h1>
        <p class="staff-page__intro mb-0">Orders on hold by account and hold type.</p>
      </div>
      <CrmRefreshToolbarButton
        :disabled="loading || refreshing"
        :loading="refreshing"
        label="Refresh All"
        title="Refresh all hold sections"
        @click="onRefreshAll"
      />
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading on-hold overview…" :center="true" />
    </div>

    <div v-else class="row g-3">
      <div
        v-for="hold in HOLD_SECTIONS"
        :key="hold.key"
        class="col-12 col-md-6 col-xl-4"
      >
        <section
          class="staff-table-card staff-datatable-card staff-datatable-card--white w-100 h-100"
        >
          <div class="staff-table-toolbar">
            <div
              class="staff-table-toolbar--row d-flex align-items-start justify-content-between gap-2"
            >
              <div>
                <h2 class="h6 fw-semibold mb-1">{{ hold.label }}</h2>
                <p class="small text-secondary mb-0">
                  Last updated: {{ lastUpdatedLabel(hold.key) }}
                </p>
              </div>
              <CrmRefreshToolbarButton
                :disabled="isSectionRefreshing(hold.key)"
                :loading="isSectionRefreshing(hold.key)"
                @click="onRefreshSection(hold.key)"
              />
            </div>
          </div>
          <div class="table-responsive staff-table-wrap">
            <table class="table table-hover align-middle mb-0 staff-data-table">
              <thead class="table-light staff-table-head">
                <tr>
                  <th class="staff-table-head__th" scope="col">Account</th>
                  <th class="staff-table-head__th text-end" scope="col" style="width: 5rem">
                    Orders
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in sectionData(hold.key).accounts"
                  :key="`${hold.key}-${row.account_id}`"
                >
                  <td>
                    <div class="d-flex align-items-center gap-2 min-w-0">
                      <ClientAccountShippingStatusIcon
                        :status="row.account_status"
                        :size="18"
                      />
                      <RouterLink
                        :to="ordersHoldRoute(row.account_id, hold.holdReason)"
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
                <tr v-if="!sectionData(hold.key).accounts.length">
                  <td colspan="2" class="text-secondary small py-4 text-center">
                    No accounts with {{ hold.label.toLowerCase() }}.
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
