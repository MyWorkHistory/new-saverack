<script setup>
import { computed, inject, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import OrderDetailPage from "../orders/OrderDetailPage.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";

const route = useRoute();
const router = useRouter();
const crmUser = inject("crmUser", ref(null));

const shipheroOrderId = computed(() => String(route.params.shipheroOrderId || ""));
const clientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));

function startCreateReturn() {
  router.push({
    name: "user-return-create",
    params: { shipheroOrderId: shipheroOrderId.value },
    query: { client_account_id: String(clientAccountId.value) },
  });
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Order",
    description: "Review order before creating a return.",
  });
});
</script>

<template>
  <div class="staff-page staff-page--wide user-return-page user-return-create-order-page order-detail-page">
    <div class="staff-table-card staff-datatable-card staff-datatable-card--white user-return-page__header-shell mb-4">
      <div class="p-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
        <p class="mb-0 small text-secondary">
          Review this order, then create a return for the items you are sending back.
        </p>
        <div class="d-flex flex-wrap gap-2 flex-shrink-0">
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm fw-semibold orders-toolbar-outline-btn"
            @click="router.push({ name: 'user-return-create-search' })"
          >
            Back to Search
          </button>
          <button type="button" class="btn btn-primary staff-page-primary btn-sm fw-semibold" @click="startCreateReturn">
            Create a Return
          </button>
        </div>
      </div>
    </div>
    <OrderDetailPage portal-return-preview embedded-in-parent />
  </div>
</template>
