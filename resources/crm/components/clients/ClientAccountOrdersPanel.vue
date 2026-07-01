<script setup>
import { computed } from "vue";
import OrdersListPage from "../../pages/orders/OrdersListPage.vue";
import { usePortalDashboardCounts } from "../../composables/usePortalDashboardCounts.js";

const props = defineProps({
  accountId: { type: [String, Number], required: true },
});

const accountIdNum = computed(() => Number(props.accountId || 0));

const { counts, loading, loadCounts } = usePortalDashboardCounts(() => accountIdNum.value);

const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

const statCards = computed(() => [
  { key: "ready_to_ship", label: "Ready To Ship", value: counts.value.ready_to_ship },
  { key: "on_hold", label: "On-Hold", value: counts.value.on_hold },
  { key: "backorder", label: "Backorder", value: counts.value.backorder },
  { key: "shipped", label: "Shipped", value: counts.value.shipped },
]);

loadCounts();
</script>

<template>
  <div>
    <div class="row g-3 mb-4">
      <div v-for="c in statCards" :key="c.key" class="col-6 col-xl-3">
        <div class="staff-stat-card h-100">
          <p class="staff-stat-card__label">{{ c.label }}</p>
          <p class="staff-stat-card__value">
            <span v-if="loading" class="text-secondary">…</span>
            <span v-else>{{ nf.format(c.value) }}</span>
          </p>
        </div>
      </div>
    </div>
    <OrdersListPage :fixed-client-account-id="accountId" embedded />
  </div>
</template>
