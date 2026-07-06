<script setup>
import { RouterLink } from "vue-router";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";

defineProps({
  accounts: { type: Array, default: () => [] },
});

function clientAccountDetailTo(accountId) {
  const id = Number(accountId || 0);
  if (id <= 0) return null;
  return { name: "client-account-detail", params: { id: String(id) } };
}

function formatEstDate(val) {
  if (val == null || val === "") return "—";
  const d = new Date(val);
  if (Number.isNaN(d.getTime())) return "—";
  const label = new Intl.DateTimeFormat("en-US", {
    month: "short",
    day: "numeric",
  }).format(d);
  return `Est. ${label}`;
}
</script>

<template>
  <section class="home-list-panel">
    <div class="home-list-panel__header">
      <div class="home-list-panel__header-left">
        <div
          class="home-list-panel__header-icon"
          style="background: #f3e8ff; color: #7c3aed"
          aria-hidden="true"
        >
          <CrmMaterialIcon name="account" :size="22" />
        </div>
        <h2 class="home-list-panel__title">Pending New Accounts</h2>
      </div>
    </div>

    <div class="home-list-panel__body">
      <div
        v-for="row in accounts"
        :key="`pending-${row.id}`"
        class="home-list-panel__row"
      >
        <div
          class="home-list-panel__row-icon"
          style="background: #f3e8ff; color: #7c3aed"
          aria-hidden="true"
        >
          <CrmMaterialIcon name="account" :size="18" />
        </div>
        <div class="home-list-panel__row-main min-w-0">
          <RouterLink
            v-if="clientAccountDetailTo(row.id)"
            :to="clientAccountDetailTo(row.id)"
            class="home-list-panel__row-title text-truncate"
          >
            {{ row.company_name }}
          </RouterLink>
          <p class="home-list-panel__row-sub mb-0">New account</p>
        </div>
        <div class="home-list-panel__row-end">
          <span class="home-est-date">{{ formatEstDate(row.created_at) }}</span>
        </div>
      </div>
      <p v-if="!accounts.length" class="home-list-panel__empty mb-0">No pending accounts.</p>
    </div>

    <div class="home-list-panel__footer">
      <RouterLink
        :to="{ name: 'client-accounts', query: { status: 'pending' } }"
        class="home-list-panel__footer-link"
      >
        View All Pending
      </RouterLink>
    </div>
  </section>
</template>
