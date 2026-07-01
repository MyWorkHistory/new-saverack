<script setup>
import { computed, onMounted } from "vue";
import { RouterLink } from "vue-router";
import CrmLoadingSpinner from "../components/common/CrmLoadingSpinner.vue";
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
    value: totals.value.ready_to_ship,
    to: { name: "orders-awaiting" },
    iconStyle: { background: "#dbeafe", color: "#1e3a8a" },
  },
  {
    key: "on_hold",
    label: "On-Hold",
    value: totals.value.on_hold,
    to: { name: "orders-on-hold" },
    iconStyle: { background: "#fef3c7", color: "#b45309" },
  },
  {
    key: "shipped",
    label: "Shipped",
    value: totals.value.shipped,
    to: { name: "orders-shipped" },
    iconStyle: { background: "#dcfce7", color: "#166534" },
  },
  {
    key: "asn_pending",
    label: "ASN",
    value: totals.value.asn_pending,
    to: { name: "admin-asn-hub", query: { status: "pending" } },
    iconStyle: { background: "#e0e7ff", color: "#3730a3" },
  },
]);

function sectionData(key) {
  return sections.value?.[key] || {
    accounts: [],
    total_count: 0,
    status: "idle",
    refreshed_at: null,
    truncated: false,
  };
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

async function onRefreshAll() {
  try {
    await refreshSection("all");
    toast.success("Refresh queued for all sections.");
  } catch {
    /* toast handled */
  }
}

async function onRefreshSection(key) {
  try {
    await refreshSection(key);
    toast.success("Refresh queued.");
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
      <button
        type="button"
        class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
        :disabled="loading || refreshing"
        title="Refresh all sections"
        aria-label="Refresh all sections"
        @click="onRefreshAll"
      >
        <svg
          width="18"
          height="18"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
          aria-hidden="true"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
          />
        </svg>
        {{ refreshing ? "Refreshing…" : "Refresh All" }}
      </button>
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading Home…" :center="true" />
    </div>

    <template v-else>
      <div class="row g-3 mb-4">
        <div v-for="c in statCards" :key="c.key" class="col-12 col-sm-6 col-xl-3">
          <RouterLink
            :to="c.to"
            class="staff-stat-card admin-home-dashboard__stat-link h-100 text-start text-decoration-none text-body d-block"
          >
            <p class="staff-stat-card__label">{{ c.label }}</p>
            <p class="staff-stat-card__value">{{ c.value.toLocaleString() }}</p>
            <div
              class="staff-stat-card__icon admin-home-dashboard__stat-icon"
              :style="c.iconStyle"
              aria-hidden="true"
            />
          </RouterLink>
        </div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-12 col-lg-6">
          <section class="staff-surface p-3 p-md-4 h-100 admin-home-dashboard__panel">
            <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
              <div>
                <h2 class="staff-user-section-title mb-1">Ready to Ship</h2>
                <p class="small text-secondary mb-0">
                  Last updated: {{ lastUpdatedLabel(SECTION_READY) }}
                </p>
              </div>
              <button
                type="button"
                class="btn btn-outline-secondary btn-sm"
                :disabled="isSectionRefreshing(SECTION_READY)"
                @click="onRefreshSection(SECTION_READY)"
              >
                Refresh
              </button>
            </div>
            <div class="table-responsive staff-table-wrap">
              <table class="table table-sm table-hover align-middle mb-0 staff-data-table">
                <thead class="table-light">
                  <tr>
                    <th>Account</th>
                    <th class="text-end" style="width: 6rem">Orders</th>
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
                    <td colspan="2" class="text-secondary small py-3 text-center">
                      No ready-to-ship orders in snapshot.
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>

        <div class="col-12 col-lg-6">
          <section class="staff-surface p-3 p-md-4 h-100 admin-home-dashboard__panel">
            <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
              <div>
                <h2 class="staff-user-section-title mb-1">Shipped</h2>
                <p class="small text-secondary mb-0">
                  Last updated: {{ lastUpdatedLabel(SECTION_SHIPPED) }}
                  <span class="text-body-secondary">(today)</span>
                </p>
              </div>
              <button
                type="button"
                class="btn btn-outline-secondary btn-sm"
                :disabled="isSectionRefreshing(SECTION_SHIPPED)"
                @click="onRefreshSection(SECTION_SHIPPED)"
              >
                Refresh
              </button>
            </div>
            <div class="table-responsive staff-table-wrap">
              <table class="table table-sm table-hover align-middle mb-0 staff-data-table">
                <thead class="table-light">
                  <tr>
                    <th>Account</th>
                    <th class="text-end" style="width: 6rem">Orders</th>
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
                    <td colspan="2" class="text-secondary small py-3 text-center">
                      No shipments in snapshot for today.
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </div>

      <section class="staff-surface p-3 p-md-4 mb-4">
        <h2 class="staff-user-section-title mb-3">Holds</h2>
        <div class="row g-3">
          <div
            v-for="hold in HOLD_SECTIONS"
            :key="hold.key"
            class="col-12 col-md-6 col-xl-4"
          >
            <div class="border rounded p-3 h-100 admin-home-dashboard__hold-panel">
              <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                <div>
                  <h3 class="h6 fw-semibold mb-1">{{ hold.label }}</h3>
                  <p class="small text-secondary mb-0">
                    Last updated: {{ lastUpdatedLabel(hold.key) }}
                  </p>
                </div>
                <button
                  type="button"
                  class="btn btn-outline-secondary btn-sm flex-shrink-0"
                  :disabled="isSectionRefreshing(hold.key)"
                  @click="onRefreshSection(hold.key)"
                >
                  Refresh
                </button>
              </div>
              <div class="table-responsive staff-table-wrap">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr>
                      <th class="small">Account</th>
                      <th class="text-end small" style="width: 5rem">Orders</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="row in sectionData(hold.key).accounts"
                      :key="`${hold.key}-${row.account_id}`"
                    >
                      <td class="small">
                        <div class="d-flex align-items-center gap-2 min-w-0">
                          <ClientAccountShippingStatusIcon
                            :status="row.account_status"
                            :size="16"
                          />
                          <RouterLink
                            :to="ordersHoldRoute(row.account_id, hold.holdReason)"
                            class="text-truncate text-decoration-none"
                          >
                            {{ row.account_name }}
                          </RouterLink>
                        </div>
                      </td>
                      <td class="text-end small fw-semibold tabular-nums">
                        {{ Number(row.orders_count || 0).toLocaleString() }}
                      </td>
                    </tr>
                    <tr v-if="!sectionData(hold.key).accounts.length">
                      <td colspan="2" class="text-secondary small py-2 text-center">
                        No accounts with {{ hold.label.toLowerCase() }}.
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section class="staff-surface p-3 p-md-4">
        <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
          <div>
            <h2 class="staff-user-section-title mb-1">ASN</h2>
            <p class="small text-secondary mb-0">
              Pending ASNs by account · Last updated: {{ lastUpdatedLabel(SECTION_ASN) }}
            </p>
          </div>
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm"
            :disabled="isSectionRefreshing(SECTION_ASN)"
            @click="onRefreshSection(SECTION_ASN)"
          >
            Refresh
          </button>
        </div>
        <div class="table-responsive staff-table-wrap">
          <table class="table table-sm table-hover align-middle mb-0 staff-data-table">
            <thead class="table-light">
              <tr>
                <th>Account</th>
                <th class="text-end" style="width: 8rem">Pending ASN</th>
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
                <td colspan="2" class="text-secondary small py-3 text-center">
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
.admin-home-dashboard__stat-link {
  position: relative;
  padding-right: 3.5rem;
}

.admin-home-dashboard__stat-icon {
  position: absolute;
  right: 1rem;
  top: 50%;
  transform: translateY(-50%);
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.5rem;
}

.admin-home-dashboard__panel {
  min-height: 12rem;
}

.admin-home-dashboard__hold-panel {
  background: var(--bs-body-bg);
}

.tabular-nums {
  font-variant-numeric: tabular-nums;
}
</style>
