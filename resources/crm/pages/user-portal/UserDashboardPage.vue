<script setup>
import { computed, inject, onMounted, ref, watch } from "vue";
import { RouterLink } from "vue-router";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { usePortalDashboardCounts } from "../../composables/usePortalDashboardCounts.js";
import { useToast } from "../../composables/useToast.js";

const toast = useToast();
const crmUser = inject("crmUser", ref(null));

const clientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));

const { counts, loading, refreshing, loadCounts, refreshCounts } = usePortalDashboardCounts(
  () => clientAccountId.value,
  {
    onError: (e) => toast.errorFrom(e, "Could not load order counts."),
  },
);

/**
 * Material Symbols paths (24×24 viewBox), sourced from Iconify’s Material Symbols set
 * (same glyphs as Google Fonts Material Symbols).
 */
const DASHBOARD_ICON = {
  /** Inventory-style box + check mark */
  readyBox:
    "M5 22q-.825 0-1.412-.587T3 20V8.725q-.45-.275-.725-.712T2 7V4q0-.825.588-1.412T4 2h16q.825 0 1.413.588T22 4v3q0 .575-.275 1.013T21 8.724V20q0 .825-.587 1.413T19 22zM4 7h16V4H4zm5 7h6v-2H9z",
  readyCheck: "M10.6 16.6l7.05-7.05l-1.4-1.4l-5.65 5.65l-2.85-2.85l-1.4 1.4z",
  hourglass:
    "M8 20h8v-3q0-1.65-1.175-2.825T12 13t-2.825 1.175T8 17zm6.825-10.175Q16 8.65 16 7V4H8v3q0 1.65 1.175 2.825T12 11t2.825-1.175M4 22v-2h2v-3q0-1.525.713-2.863T8.7 12q-1.275-.8-1.987-2.137T6 7V4H4V2h16v2h-2v3q0 1.525-.712 2.863T15.3 12q1.275.8 1.988 2.138T18 17v3h2v2z",
  shelves: "M3 23V1h2v2h14V1h2v22h-2v-2H5v2zm2-12h2V7h6v4h6V5H5zm0 8h6v-4h6v4h2v-6H5z",
  truck:
    "M3.875 19.125Q3 18.25 3 17H1V6q0-.825.588-1.412T3 4h14v4h3l3 4v5h-2q0 1.25-.875 2.125T18 20t-2.125-.875T15 17H9q0 1.25-.875 2.125T6 20t-2.125-.875m2.838-1.412Q7 17.425 7 17t-.288-.712T6 16t-.712.288T5 17t.288.713T6 18t.713-.288m12 0Q19 17.426 19 17t-.288-.712T18 16t-.712.288T17 17t.288.713T18 18t.713-.288M17 13h4.25L19 10h-2z",
};

const accountDisplayName = computed(() => {
  const u = crmUser.value;
  if (!u) {
    return "";
  }
  const name = String(u.client_account_company_name || "").trim();
  if (name) {
    return name;
  }
  const id = Number(u.client_account_id || 0);
  if (id > 0) {
    return `Account #${id}`;
  }
  return "";
});

/**
 * Counts from GET /api/orders/queue-counts (OrderController::queueCounts):
 * — Ready to Ship: awaiting tab, order_date last 7 calendar days through today.
 * — On-Hold / Backorder: respective tabs, order_date today (local day bounds).
 * — Shipped: fulfilled in today’s window (optional order_date_from/to override on API).
 * Defaults align with the portal orders list for each tab.
 */
const cards = computed(() => [
  {
    key: "ready_to_ship",
    label: "Ready To Ship",
    sub: "Orders awaiting shipment",
    value: counts.value.ready_to_ship,
    to: "/users/orders/ready-to-ship",
    iconStyle: { background: "#dbeafe", color: "#1e3a8a" },
  },
  {
    key: "on_hold",
    label: "On-Hold",
    sub: "On-Hold Orders needing your attention!",
    value: counts.value.on_hold,
    to: "/users/orders/on-hold",
    iconStyle: { background: "#fef3c7", color: "#b45309" },
  },
  {
    key: "backorder",
    label: "Backorder",
    sub: "Orders with items out of stock.",
    value: counts.value.backorder,
    to: "/users/orders/backorder",
    iconStyle: { background: "#ffe4e6", color: "#be123c" },
  },
  {
    key: "shipped",
    label: "Shipped",
    sub: "Orders shipped today!",
    value: counts.value.shipped,
    to: "/users/orders/shipped",
    iconStyle: { background: "#dcfce7", color: "#166534" },
  },
]);

function syncPageMeta() {
  const name = accountDisplayName.value;
  setCrmPageMeta({
    title: name ? `Save Rack | ${name}` : "Save Rack",
    description: "",
  });
}

watch(accountDisplayName, syncPageMeta, { immediate: true });

onMounted(() => {
  loadCounts();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="mb-4 d-flex align-items-center justify-content-between gap-2 flex-wrap">
      <h1 class="h4 mb-0 fw-semibold text-body">{{ accountDisplayName || "Home" }}</h1>
      <div class="d-flex align-items-center gap-2 flex-shrink-0">
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
          :disabled="loading || refreshing"
          title="Refresh"
          aria-label="Refresh dashboard counts"
          @click="refreshCounts"
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
          {{ refreshing ? "Refreshing…" : "Refresh" }}
        </button>
        <span v-if="refreshing" class="small text-secondary">Updating…</span>
      </div>
    </div>

    <div class="user-dashboard__content position-relative">
      <div
        v-if="loading"
        class="user-dashboard__loading-overlay d-flex align-items-center justify-content-center"
        aria-busy="true"
        aria-live="polite"
      >
        <CrmLoadingSpinner message="Loading counts…" :center="true" />
      </div>

      <template v-if="!loading">
        <p v-if="counts.truncated" class="small text-secondary mb-3">
          One or more totals may be capped at the maximum page scan; open the order list for the full queue.
        </p>

        <div class="row g-3 mb-4">
          <div v-for="c in cards" :key="c.key" class="col-12 col-sm-6 col-xl-3">
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
                  v-else-if="c.key === 'backorder'"
                  class="user-dashboard-stat-svg"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path :d="DASHBOARD_ICON.shelves" />
                </svg>
                <svg
                  v-else-if="c.key === 'shipped'"
                  class="user-dashboard-stat-svg"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path :d="DASHBOARD_ICON.truck" />
                </svg>
              </div>
            </RouterLink>
          </div>
        </div>

        <section class="staff-surface p-4 user-dashboard__analytics">
          <h2 class="h6 fw-semibold mb-1">Analytics</h2>
          <p class="small text-secondary mb-0">
            Order trends and charts will appear here in a future update.
          </p>
          <div
            class="user-dashboard__chart-placeholder d-flex align-items-center justify-content-center text-secondary small rounded mt-3"
          >
            Chart coming soon
          </div>
        </section>
      </template>
    </div>
  </div>
</template>

<style scoped>
.user-dashboard-summary-link {
  width: 100%;
  cursor: pointer;
  text-align: left;
  font: inherit;
  color: inherit;
  border: 1px solid rgba(47, 43, 61, 0.14) !important;
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease,
    transform 0.15s ease;
}

.user-dashboard-summary-link:hover {
  border-color: rgba(115, 103, 240, 0.35) !important;
  box-shadow: 0 0.45rem 1rem rgba(47, 43, 61, 0.12);
  transform: translateY(-1px);
}

.user-dashboard-summary-link .user-dashboard-stat-icon {
  top: 50%;
  bottom: auto;
  transform: translateY(-50%);
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.875rem;
  height: 2.875rem;
  border-radius: 0.4375rem;
}

.user-dashboard-stat-svg {
  width: 1.4375rem;
  height: 1.4375rem;
  flex-shrink: 0;
  display: block;
  overflow: visible;
}

.user-dashboard__chart-placeholder {
  min-height: 220px;
  border: 1px dashed rgba(47, 43, 61, 0.18);
  background: var(--bs-body-bg, #fff);
}

[data-bs-theme="dark"] .user-dashboard__chart-placeholder {
  border-color: rgba(255, 255, 255, 0.12);
}

.user-dashboard__content {
  min-height: 22rem;
}

.user-dashboard__loading-overlay {
  position: absolute;
  inset: 0;
  z-index: 5;
  background: rgba(248, 247, 250, 0.88);
  backdrop-filter: blur(2px);
  border-radius: 0.5rem;
}

[data-bs-theme="dark"] .user-dashboard__loading-overlay {
  background: rgba(22, 22, 26, 0.88);
}
</style>
