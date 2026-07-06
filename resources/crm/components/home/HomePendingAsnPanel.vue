<script setup>
import { RouterLink } from "vue-router";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";

defineProps({
  items: { type: Array, default: () => [] },
});

const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

function asnPendingRoute(accountId) {
  return {
    name: "admin-asn-hub",
    query: {
      client_account_id: String(accountId),
      status: "pending",
    },
  };
}
</script>

<template>
  <section class="home-list-panel">
    <div class="home-list-panel__header">
      <div class="home-list-panel__header-left">
        <div
          class="home-list-panel__header-icon"
          style="background: #e0e7ff; color: #3730a3"
          aria-hidden="true"
        >
          <CrmMaterialIcon name="asnDoc" :size="22" />
        </div>
        <h2 class="home-list-panel__title">Pending ASN</h2>
      </div>
      <RouterLink
        :to="{ name: 'admin-asn-hub', query: { status: 'pending' } }"
        class="home-list-panel__header-link"
      >
        View All
      </RouterLink>
    </div>

    <div class="home-list-panel__body">
      <div
        v-for="row in items"
        :key="`asn-${row.account_id}`"
        class="home-list-panel__row"
      >
        <div
          class="home-list-panel__row-icon"
          style="background: #e0e7ff; color: #3730a3"
          aria-hidden="true"
        >
          <CrmMaterialIcon name="asnDoc" :size="18" />
        </div>
        <div class="home-list-panel__row-main min-w-0">
          <RouterLink
            :to="asnPendingRoute(row.account_id)"
            class="home-list-panel__row-title text-truncate"
          >
            {{ row.account_name }}
          </RouterLink>
        </div>
        <div class="home-list-panel__row-end d-flex align-items-center gap-3">
          <span class="home-asn-unit">{{ row.unit_label || "—" }}</span>
          <span class="home-asn-total">{{ nf.format(Number(row.total_items || 0)) }}</span>
        </div>
      </div>
      <p v-if="!items.length" class="home-list-panel__empty mb-0">No pending ASNs.</p>
    </div>
  </section>
</template>
