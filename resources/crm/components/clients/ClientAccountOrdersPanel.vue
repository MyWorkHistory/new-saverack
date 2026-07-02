<script setup>
import { computed, ref } from "vue";
import { useRouter } from "vue-router";
import OrdersListPage from "../../pages/orders/OrdersListPage.vue";
import { usePortalDashboardCounts } from "../../composables/usePortalDashboardCounts.js";

const props = defineProps({
  accountId: { type: [String, Number], required: true },
});

const router = useRouter();
const accountIdNum = computed(() => Number(props.accountId || 0));

const { counts, loading, refreshing, countsReady, loadCounts, markRefreshed, lastRefreshedLabel } =
  usePortalDashboardCounts(() => accountIdNum.value);

const activeQueueTab = ref("awaiting");

const QUEUE_TAB_BY_CARD = {
  ready_to_ship: "awaiting",
  on_hold: "on_hold",
  backorder: "backorder",
  shipped: "shipped",
};

const QUEUE_ROUTE_BY_TAB = {
  awaiting: "orders-awaiting",
  on_hold: "orders-on-hold",
  backorder: "orders-out-of-stock",
  shipped: "orders-shipped",
};

const activeTabTitle = computed(() => {
  if (activeQueueTab.value === "awaiting") return "Ready to Ship";
  if (activeQueueTab.value === "on_hold") return "On-Hold";
  if (activeQueueTab.value === "backorder") return "Backorder";
  if (activeQueueTab.value === "shipped") return "Shipped";
  return "Orders";
});

const DASHBOARD_ICON = {
  readyBox:
    "M5 22q-.825 0-1.412-.587T3 20V8.725q-.45-.275-.725-.712T2 7V4q0-.825.588-1.412T4 2h16q.825 0 1.413.588T22 4v3q0 .575-.275 1.013T21 8.724V20q0 .825-.587 1.413T19 22zM4 7h16V4H4zm5 7h6v-2H9z",
  readyCheck: "M10.6 16.6l7.05-7.05l-1.4-1.4l-5.65 5.65l-2.85-2.85l-1.4 1.4z",
  hourglass:
    "M8 20h8v-3q0-1.65-1.175-2.825T12 13t-2.825 1.175T8 17zm6.825-10.175Q16 8.65 16 7V4H8v3q0 1.65 1.175 2.825T12 11t2.825-1.175M4 22v-2h2v-3q0-1.525.713-2.863T8.7 12q-1.275-.8-1.987-2.137T6 7V4H4V2h16v2h-2v3q0 1.525-.712 2.863T15.3 12q1.275.8 1.988 2.138T18 17v3h2v2z",
  shelves: "M3 23V1h2v2h14V1h2v22h-2v-2H5v2zm2-12h2V7h6v4h6V5H5zm0 8h6v-4h6v4h2v-6H5z",
  truck:
    "M3.875 19.125Q3 18.25 3 17H1V6q0-.825.588-1.412T3 4h14v4h3l3 4v5h-2q0 1.25-.875 2.125T18 20t-2.125-.875T15 17H9q0 1.25-.875 2.125T6 20t-2.125-.875m2.838-1.412Q7 17.425 7 17t-.288-.712T6 16t-.712.288T5 17t.288.713T6 18t.713-.288m12 0Q19 17.426 19 17t-.288-.712T18 16t-.712.288T17 17t.288.713T18 18t.713-.288M17 13h4.25L19 10h-2z",
};

const statCards = computed(() => [
  {
    key: "ready_to_ship",
    label: "Ready To Ship",
    sub: "Orders awaiting shipment",
    value: counts.value.ready_to_ship,
    iconStyle: { background: "#dbeafe", color: "#1e3a8a" },
  },
  {
    key: "on_hold",
    label: "On-Hold",
    sub: "On-hold orders needing attention",
    value: counts.value.on_hold,
    iconStyle: { background: "#fef3c7", color: "#b45309" },
  },
  {
    key: "backorder",
    label: "Backorder",
    sub: "Orders with items out of stock",
    value: counts.value.backorder,
    iconStyle: { background: "#ffe4e6", color: "#be123c" },
  },
  {
    key: "shipped",
    label: "Shipped",
    sub: "Orders shipped today",
    value: counts.value.shipped,
    iconStyle: { background: "#dcfce7", color: "#166534" },
  },
]);

function setQueueTabFromCard(cardKey) {
  const tab = QUEUE_TAB_BY_CARD[cardKey];
  if (!tab) return;
  activeQueueTab.value = tab;
}

function viewAllOrdersHref() {
  const routeName = QUEUE_ROUTE_BY_TAB[activeQueueTab.value] || "orders-awaiting";
  return router.resolve({
    name: routeName,
    query: { client_account_id: String(accountIdNum.value) },
  }).href;
}

function onOrdersQueueRefreshed(syncedAt) {
  markRefreshed(syncedAt);
}

loadCounts();
</script>

<template>
  <div>
    <div class="row g-3 mb-4 client-account-orders-summary">
      <div v-for="c in statCards" :key="c.key" class="col-12 col-sm-6 col-xl-3">
        <button
          type="button"
          class="staff-stat-card billing-inv-summary-card h-100 text-start w-100"
          :class="{
            'client-account-orders-summary-card--active': activeQueueTab === QUEUE_TAB_BY_CARD[c.key],
          }"
          @click="setQueueTabFromCard(c.key)"
        >
          <p class="staff-stat-card__label">{{ c.label }}</p>
          <p class="staff-stat-card__value">
            <span
              v-if="!countsReady && loading"
              class="client-account-orders-summary__dots text-secondary"
              aria-label="Loading counts"
            >
              <span class="client-account-orders-summary__dot" />
              <span class="client-account-orders-summary__dot" />
              <span class="client-account-orders-summary__dot" />
            </span>
            <span
              v-else
              class="client-account-orders-summary__value"
              :class="{ 'client-account-orders-summary__value--refreshing': refreshing }"
            >
              {{ Number(c.value || 0).toLocaleString() }}
            </span>
          </p>
          <p class="staff-stat-card__sub">{{ c.sub }}</p>
          <div
            class="staff-stat-card__icon client-account-orders-summary__icon"
            :style="c.iconStyle"
            aria-hidden="true"
          >
            <svg
              v-if="c.key === 'ready_to_ship'"
              class="client-account-orders-summary__svg"
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
              class="client-account-orders-summary__svg"
              fill="currentColor"
              viewBox="0 0 24 24"
            >
              <path :d="DASHBOARD_ICON.hourglass" />
            </svg>
            <svg
              v-else-if="c.key === 'backorder'"
              class="client-account-orders-summary__svg"
              fill="currentColor"
              viewBox="0 0 24 24"
            >
              <path :d="DASHBOARD_ICON.shelves" />
            </svg>
            <svg
              v-else-if="c.key === 'shipped'"
              class="client-account-orders-summary__svg"
              fill="currentColor"
              viewBox="0 0 24 24"
            >
              <path :d="DASHBOARD_ICON.truck" />
            </svg>
          </div>
        </button>
      </div>
    </div>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
      <h3 class="staff-user-section-title mb-0">{{ activeTabTitle }}</h3>
      <div class="d-flex flex-wrap align-items-center gap-2 gap-md-3">
        <p v-if="lastRefreshedLabel" class="small text-secondary mb-0">
          Last updated: {{ lastRefreshedLabel }}
        </p>
        <a
          :href="viewAllOrdersHref()"
          class="btn btn-sm btn-outline-primary"
          target="_blank"
          rel="noopener noreferrer"
        >
          View All
        </a>
      </div>
    </div>

    <OrdersListPage
      :fixed-client-account-id="accountId"
      :embedded-queue-tab="activeQueueTab"
      embedded
      @queue-refreshed="onOrdersQueueRefreshed"
    />
  </div>
</template>

<style scoped>
.client-account-orders-summary :deep(.billing-inv-summary-card .staff-stat-card__icon) {
  top: 50%;
  right: 1.125rem;
  transform: translateY(-50%);
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.875rem;
  height: 2.875rem;
  border-radius: 0.4375rem;
}

.client-account-orders-summary__svg {
  width: 1.4375rem;
  height: 1.4375rem;
  flex-shrink: 0;
  display: block;
  overflow: visible;
}

button.billing-inv-summary-card.client-account-orders-summary-card--active {
  border-color: var(--bs-primary);
  box-shadow: 0 0 0 1px var(--bs-primary);
}

[data-bs-theme="dark"] button.billing-inv-summary-card.client-account-orders-summary-card--active {
  border-color: var(--bs-primary);
  box-shadow: 0 0 0 1px var(--bs-primary);
}

.client-account-orders-summary__dots {
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  min-height: 1.5rem;
}

.client-account-orders-summary__dot {
  width: 0.35rem;
  height: 0.35rem;
  border-radius: 50%;
  background: currentColor;
  opacity: 0.35;
  animation: client-account-orders-summary-dot 1.1s ease-in-out infinite;
}

.client-account-orders-summary__dot:nth-child(2) {
  animation-delay: 0.15s;
}

.client-account-orders-summary__dot:nth-child(3) {
  animation-delay: 0.3s;
}

@keyframes client-account-orders-summary-dot {
  0%,
  80%,
  100% {
    opacity: 0.35;
    transform: translateY(0);
  }
  40% {
    opacity: 1;
    transform: translateY(-0.12rem);
  }
}

.client-account-orders-summary__value--refreshing {
  opacity: 0.72;
}
</style>
