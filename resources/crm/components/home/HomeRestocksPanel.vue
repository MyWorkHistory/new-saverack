<script setup>
import { RouterLink, useRouter } from "vue-router";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";

defineProps({
  items: { type: Array, default: () => [] },
});

const router = useRouter();

function clientAccountDetailTo(accountId) {
  const id = Number(accountId || 0);
  if (id <= 0) return null;
  return { name: "client-account-detail", params: { id: String(id) } };
}

function inventoryDetailHref(row) {
  const sku = String(row?.sku || "").trim();
  if (!sku) return "#";
  const accountId = Number(row?.client_account_id || 0);
  const query = accountId > 0 ? { client_account_id: String(accountId) } : {};
  return router.resolve({ name: "inventory-detail", params: { sku }, query }).href;
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
          <CrmMaterialIcon name="sync" :size="22" />
        </div>
        <h2 class="home-list-panel__title">Restocks Needed</h2>
      </div>
      <RouterLink :to="{ name: 'inventory-restock' }" class="home-list-panel__header-link">
        View All
      </RouterLink>
    </div>

    <div class="home-list-panel__body home-restock-tiles">
      <div v-for="row in items" :key="`restock-${row.sku}`" class="home-restock-tile">
        <a
          :href="inventoryDetailHref(row)"
          target="_blank"
          rel="noopener noreferrer"
          class="home-restock-tile__thumb-link"
          :aria-label="`View ${row.sku || 'product'}`"
        >
          <img
            v-if="row.image_url"
            :src="row.image_url"
            alt=""
            class="home-restock-tile__thumb"
            loading="lazy"
          />
          <span v-else class="home-restock-tile__thumb home-restock-tile__thumb--empty" />
        </a>
        <div class="home-restock-tile__text min-w-0">
          <a
            :href="inventoryDetailHref(row)"
            target="_blank"
            rel="noopener noreferrer"
            class="home-restock-tile__sku"
          >
            {{ row.sku || "—" }}
          </a>
          <div class="home-restock-tile__name">{{ row.name || "—" }}</div>
        </div>
        <RouterLink
          v-if="clientAccountDetailTo(row.client_account_id)"
          :to="clientAccountDetailTo(row.client_account_id)"
          class="home-restock-tile__account"
        >
          {{ row.account_name || "—" }}
        </RouterLink>
        <span v-else class="home-restock-tile__account text-secondary">—</span>
      </div>
      <p v-if="!items.length" class="home-list-panel__empty mb-0">No active restock rows.</p>
    </div>
  </section>
</template>
