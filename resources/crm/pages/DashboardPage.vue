<script setup>
import { computed, onMounted } from "vue";
import { RouterLink } from "vue-router";
import CrmLoadingSpinner from "../components/common/CrmLoadingSpinner.vue";
import CrmRefreshToolbarButton from "../components/common/CrmRefreshToolbarButton.vue";
import ClientAccountShippingStatusIcon from "../components/clients/ClientAccountShippingStatusIcon.vue";
import { setCrmPageMeta } from "../composables/useCrmPageMeta.js";
import { useAdminHomeDashboard } from "../composables/useAdminHomeDashboard.js";
import { useToast } from "../composables/useToast.js";
import { formatDateTimeUs } from "../utils/formatUserDates.js";

const toast = useToast();

const { loading, refreshing, totals, sections, load, refreshSection } = useAdminHomeDashboard({
  onError: (e) => toast.errorFrom(e, "Could not load Home dashboard."),
});

const SECTION_READY = "ready_to_ship";
const SECTION_SHIPPED = "shipped";
const SECTION_ASN = "asn_pending";

const DASHBOARD_ICON = {
  readyBox:
    "M5 22q-.825 0-1.412-.587T3 20V8.725q-.45-.275-.725-.712T2 7V4q0-.825.588-1.412T4 2h16q.825 0 1.413.588T22 4v3q0 .575-.275 1.013T21 8.724V20q0 .825-.587 1.413T19 22zM4 7h16V4H4zm5 7h6v-2H9z",
  readyCheck: "M10.6 16.6l7.05-7.05l-1.4-1.4l-5.65 5.65l-2.85-2.85l-1.4 1.4z",
  hourglass:
    "M8 20h8v-3q0-1.65-1.175-2.825T12 13t-2.825 1.175T8 17zm6.825-10.175Q16 8.65 16 7V4H8v3q0 1.65 1.175 2.825T12 11t2.825-1.175M4 22v-2h2v-3q0-1.525.713-2.863T8.7 12q-1.275-.8-1.987-2.137T6 7V4H4V2h16v2h-2v3q0 1.525-.712 2.863T15.3 12q1.275.8 1.988 2.138T18 17v3h2v2z",
  truck:
    "M3.875 19.125Q3 18.25 3 17H1V6q0-.825.588-1.412T3 4h14v4h3l3 4v5h-2q0 1.25-.875 2.125T18 20t-2.125-.875T15 17H9q0 1.25-.875 2.125T6 20t-2.125-.875m2.838-1.412Q7 17.425 7 17t-.288-.712T6 16t-.712.288T5 17t.288.713T6 18t.713-.288m12 0Q19 17.426 19 17t-.288-.712T18 16t-.712.288T17 17t.288.713T18 18t.713-.288M17 13h4.25L19 10h-2z",
  asn: "M4 4h16v14H4zM8 8h8v2H8zm0 4h6v2H8zm-2 9v-3h12v3z",
};

const HOLD_SECTIONS = [
  { key: "hold_operator", label: "Operator Hold", holdReason: "operator" },
  { key: "hold_address", label: "Address Hold", holdReason: "address" },
  { key: "hold_fraud", label: "Fraud Hold", holdReason: "fraud" },
  { key: "hold_payment", label: "Payment Hold", holdReason: "payment" },
  { key: "hold_user", label: "User Hold", holdReason: "user" },
  { key: "hold_backorder", label: "Backorder", holdReason: null },
];

const statCards = computed(() => [
  {
    key: "ready_to_ship",
    label: "Ready To Ship",
    sub: "Orders awaiting shipment",
    value: totals.value.ready_to_ship,
    to: { name: "orders-awaiting" },
    iconStyle: { background: "#dbeafe", color: "#1e3a8a" },
  },
  {
    key: "on_hold",
    label: "On-Hold",
    sub: "Orders on hold across all accounts",
    value: totals.value.on_hold,
    to: { name: "orders-on-hold" },
    iconStyle: { background: "#fef3c7", color: "#b45309" },
  },
  {
    key: "shipped",
    label: "Shipped",
    sub: "Orders shipped today",
    value: totals.value.shipped,
    to: { name: "orders-shipped" },
    iconStyle: { background: "#dcfce7", color: "#166534" },
  },
  {
    key: "asn_pending",
    label: "ASN",
    sub: "Pending ASNs",
    value: totals.value.asn_pending,
    to: { name: "admin-asn-hub", query: { status: "pending" } },
    iconStyle: { background: "#e0e7ff", color: "#3730a3" },
  },
]);

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

function ordersHoldRoute(accountId, holdReason) {
  if (holdReason === null) {
    return {
      name: "orders-out-of-stock",
      query: { client_account_id: String(accountId) },
    };
  }
  return {
    name: "orders-on-hold",
    query: {
      client_account_id: String(accountId),
      hold_reason: holdReason,
    },
  };
}

function asnPendingRoute(accountId) {
  return {
    name: "admin-asn-hub",
    query: {
      client_account_id: String(accountId),
      status: "pending",
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
    const data = await refreshSection("all", { sync: false });
    toast.success(refreshToastMessage(data, true));
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
    title: "Save Rack | Home",
    description: "Operations overview — ready to ship, holds, shipped, and ASN.",
  });
  try {
    await load();
  } catch {
    /* toast handled */
  }
});
</script>

<template>
  <div class="staff-page staff-page--wide admin-home-dashboard">
    <div class="mb-4 d-flex align-items-center justify-content-between gap-2 flex-wrap">
      <h1 class="h4 mb-0 fw-semibold text-body">Home</h1>
      <CrmRefreshToolbarButton
        :disabled="loading || refreshing"
        :loading="refreshing"
        label="Refresh All"
        title="Refresh all sections"
        @click="onRefreshAll"
      />
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading Home…" :center="true" />
    </div>

    <template v-else>
      <div class="row g-3 mb-4">
        <div v-for="c in statCards" :key="c.key" class="col-12 col-sm-6 col-xl-3">
          <RouterLink
            :to="c.to"
            class="staff-stat-card user-dashboard-summary-link h-100 text-start text-decoration-none text-body d-block"
          >
            <p class="staff-stat-card__label">{{ c.label }}</p>
            <p class="staff-stat-card__value">{{ c.value.toLocaleString() }}</p>
            <p class="staff-stat-card__sub">{{ c.sub }}</p>
            <div
              class="staff-stat-card__icon user-dashboard-stat-icon"
              :style="c.iconStyle"
              aria-hidden="true"
            >
              <svg
                v-if="c.key === 'ready_to_ship'"
                class="user-dashboard-stat-svg"
                fill="currentColor"
                viewBox="0 0 24 24"
              >
                <path :d="DASHBOARD_ICON.readyBox" />
                <path
                  transform="translate(10.25 9.25) scale(0.48)"
                  :d="DASHBOARD_ICON.readyCheck"
                />
              </svg>
              <svg
                v-else-if="c.key === 'on_hold'"
                class="user-dashboard-stat-svg"
                fill="currentColor"
                viewBox="0 0 24 24"
              >
                <path :d="DASHBOARD_ICON.hourglass" />
              </svg>
              <svg
                v-else-if="c.key === 'shipped'"
                class="user-dashboard-stat-svg"
                fill="currentColor"
                viewBox="0 0 24 24"
              >
                <path :d="DASHBOARD_ICON.truck" />
              </svg>
              <svg
                v-else-if="c.key === 'asn_pending'"
                class="user-dashboard-stat-svg"
                fill="currentColor"
                viewBox="0 0 24 24"
              >
                <path :d="DASHBOARD_ICON.asn" />
              </svg>
            </div>
          </RouterLink>
        </div>
      </div>

      <div class="row g-3 mb-4">
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

      <section class="mb-4">
        <h2 class="staff-user-section-title mb-3">Holds</h2>
        <div class="row g-3">
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
                    <h3 class="h6 fw-semibold mb-1">{{ hold.label }}</h3>
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
      </section>

      <section class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
        <div class="staff-table-toolbar">
          <div
            class="staff-table-toolbar--row d-flex align-items-start justify-content-between gap-2"
          >
            <div>
              <h2 class="staff-user-section-title mb-1">ASN</h2>
              <p class="small text-secondary mb-0">
                Pending ASNs by account · Last updated: {{ lastUpdatedLabel(SECTION_ASN) }}
              </p>
            </div>
            <CrmRefreshToolbarButton
              :disabled="isSectionRefreshing(SECTION_ASN)"
              :loading="isSectionRefreshing(SECTION_ASN)"
              @click="onRefreshSection(SECTION_ASN)"
            />
          </div>
        </div>
        <div class="table-responsive staff-table-wrap">
          <table class="table table-hover align-middle mb-0 staff-data-table">
            <thead class="table-light staff-table-head">
              <tr>
                <th class="staff-table-head__th" scope="col">Account</th>
                <th class="staff-table-head__th text-end" scope="col" style="width: 8rem">
                  Pending ASN
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in sectionData(SECTION_ASN).accounts"
                :key="`asn-${row.account_id}`"
              >
                <td>
                  <div class="d-flex align-items-center gap-2 min-w-0">
                    <ClientAccountShippingStatusIcon
                      :status="row.account_status"
                      :size="18"
                    />
                    <RouterLink
                      :to="asnPendingRoute(row.account_id)"
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
              <tr v-if="!sectionData(SECTION_ASN).accounts.length">
                <td colspan="2" class="text-secondary small py-4 text-center">
                  No pending ASNs.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.tabular-nums {
  font-variant-numeric: tabular-nums;
}
</style>
