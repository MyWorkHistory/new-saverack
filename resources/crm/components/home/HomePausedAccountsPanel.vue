<script setup>
import { RouterLink } from "vue-router";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";
import ClientAccountAvatar from "../clients/ClientAccountAvatar.vue";
import { formatDateUs } from "../../utils/formatUserDates.js";

defineProps({
  accounts: { type: Array, default: () => [] },
});

function clientAccountDetailTo(accountId) {
  const id = Number(accountId || 0);
  if (id <= 0) return null;
  return { name: "client-account-detail", params: { id: String(id) } };
}
</script>

<template>
  <section class="home-list-panel">
    <div class="home-list-panel__header">
      <div class="home-list-panel__header-left">
        <div
          class="home-list-panel__header-icon"
          style="background: #fee2e2; color: #dc2626"
          aria-hidden="true"
        >
          <CrmMaterialIcon name="localShipping" :size="22" />
        </div>
        <h2 class="home-list-panel__title">Paused Accounts</h2>
      </div>
    </div>

    <div class="home-list-panel__body">
      <div
        v-for="row in accounts"
        :key="`paused-${row.id}`"
        class="home-list-panel__row"
      >
        <ClientAccountAvatar :account="row" size="sm" brand-only />
        <div class="home-list-panel__row-main min-w-0">
          <RouterLink
            v-if="clientAccountDetailTo(row.id)"
            :to="clientAccountDetailTo(row.id)"
            class="home-list-panel__row-title text-truncate"
          >
            {{ row.company_name }}
          </RouterLink>
          <p class="home-list-panel__row-sub mb-0">
            Paused on {{ formatDateUs(row.paused_at) || "—" }}
          </p>
        </div>
        <div class="home-list-panel__row-end">
          <span class="home-paused-badge">{{ row.pause_reason || "Past Due" }}</span>
        </div>
      </div>
      <p v-if="!accounts.length" class="home-list-panel__empty mb-0">No paused accounts.</p>
    </div>

    <div class="home-list-panel__footer">
      <RouterLink
        :to="{ name: 'client-accounts', query: { status: 'paused' } }"
        class="home-list-panel__footer-link"
      >
        View All Paused
      </RouterLink>
    </div>
  </section>
</template>
