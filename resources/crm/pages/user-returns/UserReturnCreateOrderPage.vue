<script setup>
import { computed, inject, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import OrderDetailPage from "../orders/OrderDetailPage.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { onMounted } from "vue";

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
  <div class="user-return-create-order-page">
    <div class="staff-page staff-page--wide mb-3">
      <div
        class="staff-table-card staff-datatable-card staff-datatable-card--white d-flex flex-wrap justify-content-between align-items-center gap-3 p-3 px-4"
      >
        <p class="mb-0 small text-secondary">Review this order, then create a return for the items you are sending back.</p>
        <button type="button" class="btn btn-primary staff-page-primary fw-semibold" @click="startCreateReturn">
          Create a Return
        </button>
      </div>
    </div>
    <OrderDetailPage portal-return-preview />
  </div>
</template>
