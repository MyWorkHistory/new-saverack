<script setup>
import { computed, inject, onMounted, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import OrderCreateDraftModal from "../../components/orders/OrderCreateDraftModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsPortalUser } from "../../utils/crmUser";

const route = useRoute();
const router = useRouter();
const toast = useToast();
const crmUser = inject("crmUser", ref(null));

const accountsLoading = ref(false);
const accounts = ref([]);
const modalOpen = ref(false);

const isPortalMode = computed(
  () => route.meta?.userPortal === true || crmIsPortalUser(crmUser.value),
);
const portalAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));

const ordersListTo = computed(() => {
  if (isPortalMode.value) {
    return { name: "user-orders" };
  }
  const q = {};
  const accountId = route.query.client_account_id;
  if (accountId) q.client_account_id = String(accountId);
  return { path: "/admin/orders/search", query: q };
});

const detailRouteName = computed(() =>
  isPortalMode.value ? "user-order-detail" : "order-detail",
);

async function loadAccounts() {
  if (isPortalMode.value) return;
  accountsLoading.value = true;
  try {
    const { data } = await api.get("/inventory/client-account-options");
    accounts.value = Array.isArray(data?.accounts) ? data.accounts : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load account list.");
  } finally {
    accountsLoading.value = false;
  }
}

function openModal() {
  if (isPortalMode.value && portalAccountId.value <= 0) {
    toast.error("Your account is not linked to a client account yet.");
    return;
  }
  modalOpen.value = true;
}

async function onDraftCreated(data) {
  modalOpen.value = false;
  const draftRouteId = String(data?.draft_route_id || "");
  const clientAccountId = Number(data?.client_account_id || 0);
  if (!draftRouteId || clientAccountId <= 0) {
    toast.error("Draft was created but the response was incomplete.");
    return;
  }
  toast.success("Order draft created.");
  await router.push({
    name: detailRouteName.value,
    params: { shipheroOrderId: draftRouteId },
    query: { client_account_id: String(clientAccountId) },
  });
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Orders | Create Order",
    description: "Create a new order draft with shipping details, then add items before sending to ShipHero.",
  });
  loadAccounts();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Create Order</h1>
        <p class="text-secondary small mb-0">
          Start with the order number and shipping address. Add line items and shipping method on the order detail
          page, then mark the order Ready to Ship when you are done.
        </p>
      </div>
      <RouterLink
        :to="ordersListTo"
        class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn fw-semibold"
      >
        Back To Orders
      </RouterLink>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 p-md-5 text-center">
      <p class="text-secondary mb-4 mx-auto" style="max-width: 36rem">
        Create a local draft first. You will be taken to the order detail page to add items, choose carrier and method,
        and send the order to ShipHero when it is ready.
      </p>
      <button type="button" class="btn btn-primary staff-page-primary" @click="openModal">
        Create Order
      </button>
    </div>

    <OrderCreateDraftModal
      :open="modalOpen"
      :portal-mode="isPortalMode"
      :portal-account-id="portalAccountId"
      :accounts="accounts"
      :accounts-loading="accountsLoading"
      :initial-account-id="String(route.query.client_account_id || '')"
      @close="modalOpen = false"
      @created="onDraftCreated"
    />
  </div>
</template>
