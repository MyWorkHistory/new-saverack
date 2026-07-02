<script setup>
import { computed, inject, onMounted, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import OrderCreateDrawer from "../../components/orders/OrderCreateDrawer.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { canWriteShipHeroOrders } from "../../utils/crmShipHeroOrders";
import { crmIsPortalUser } from "../../utils/crmUser";

const route = useRoute();
const router = useRouter();
const toast = useToast();
const crmUser = inject("crmUser", ref(null));

const loading = ref(false);
const accountsLoading = ref(false);
const accounts = ref([]);
const rows = ref([]);
const selectedAccountId = ref(String(route.query.client_account_id || ""));
const createOrderModalOpen = ref(false);

const isPortalMode = computed(
  () => route.meta?.userPortal === true || crmIsPortalUser(crmUser.value),
);
const portalAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));
const canWriteOrders = computed(() => canWriteShipHeroOrders(crmUser.value));

const detailRouteName = computed(() =>
  isPortalMode.value ? "user-order-detail" : "order-detail",
);

const ordersListTo = computed(() => {
  if (isPortalMode.value) {
    return { name: "user-orders" };
  }
  return { path: "/admin/orders/search" };
});

const tableColspan = computed(() => (isPortalMode.value ? 6 : 7));

const accountOptions = computed(() =>
  accounts.value
    .filter((a) => a.has_shiphero_customer)
    .map((a) => ({
      id: a.id,
      name: a.company_name || `Account #${a.id}`,
      email: a.email ? String(a.email) : "",
    })),
);

function formatDate(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
}

function draftDetailRoute(row) {
  const accountId = Number(row?.client_account_id || 0);
  const routeId = String(row?.draft_route_id || "");
  if (!routeId || accountId <= 0) return null;
  return {
    name: detailRouteName.value,
    params: { shipheroOrderId: routeId },
    query: { client_account_id: String(accountId) },
  };
}

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

async function loadDrafts() {
  loading.value = true;
  try {
    const params = { per_page: 50 };
    if (!isPortalMode.value && selectedAccountId.value) {
      params.client_account_id = Number(selectedAccountId.value);
    }
    const { data } = await api.get("/order-drafts", { params });
    rows.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    rows.value = [];
    toast.errorFrom(e, "Could not load draft orders.");
  } finally {
    loading.value = false;
  }
}

async function onDraftOrderCreated(data) {
  createOrderModalOpen.value = false;
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

watch(selectedAccountId, () => {
  if (!isPortalMode.value) {
    loadDrafts();
  }
});

onMounted(() => {
  setCrmPageMeta({
    title: isPortalMode.value
      ? "Save Rack | Orders | Drafts"
      : "Save Rack | Orders | Draft Orders",
    description: "Local order drafts not yet sent to ShipHero.",
  });
  loadAccounts();
  loadDrafts();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
      <div class="min-w-0">
        <h1 class="h4 mb-1 fw-semibold text-body">Orders - Drafts</h1>
        <p class="text-secondary small mb-0">
          Open a draft to add line items and shipping, then mark it Ready to Ship when you are done.
        </p>
      </div>
      <div class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0 align-self-start">
        <button
          v-if="canWriteOrders"
          type="button"
          class="btn btn-primary btn-sm staff-page-primary"
          @click="createOrderModalOpen = true"
        >
          Create Order
        </button>
        <RouterLink
          :to="ordersListTo"
          class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn fw-semibold"
        >
          Back To Orders
        </RouterLink>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100 orders-page-toolbar">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row orders-toolbar-row">
          <div v-if="!isPortalMode" class="orders-toolbar-account flex-shrink-0">
            <CrmSearchableSelect
              v-model="selectedAccountId"
              class="staff-toolbar-search staff-toolbar-search--inline"
              appearance="staff"
              aria-label="Client account"
              :options="accountOptions"
              :disabled="accountsLoading || loading"
              placeholder="All accounts"
              search-placeholder="Search accounts…"
              :allow-empty="true"
              empty-label="All accounts"
              button-id="order-drafts-account-trigger"
            />
          </div>
          <div
            class="staff-toolbar-row-actions d-flex flex-wrap align-items-center gap-2 gap-md-3 ms-md-auto flex-shrink-0"
          >
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
              :disabled="loading"
              title="Refresh"
              aria-label="Refresh drafts"
              @click="loadDrafts"
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
              Refresh
            </button>
          </div>
        </div>
        <p v-if="!isPortalMode" class="small text-secondary mb-0 mt-2 px-1">
          Only accounts with a ShipHero customer ID appear here.
        </p>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th" scope="col">Status</th>
              <th class="staff-table-head__th" scope="col">Order #</th>
              <th class="staff-table-head__th" scope="col">Recipient</th>
              <th class="staff-table-head__th" scope="col">Date</th>
              <th v-if="!isPortalMode" class="staff-table-head__th" scope="col">Account</th>
              <th class="staff-table-head__th" scope="col">Country</th>
              <th class="staff-table-head__th text-end" scope="col">Items</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading drafts…" />
                </div>
              </td>
            </tr>
            <tr v-else-if="!rows.length">
              <td :colspan="tableColspan" class="text-center text-secondary py-5">
                No draft orders yet. Click Create Order above to start one.
              </td>
            </tr>
            <tr v-for="row in rows" v-else :key="row.id" class="align-middle">
              <td>
                <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis fw-medium">
                  Draft
                </span>
              </td>
              <td class="fw-semibold">
                <RouterLink
                  v-if="draftDetailRoute(row)"
                  :to="draftDetailRoute(row)"
                  class="text-decoration-none"
                >
                  {{ row.order_number || "—" }}
                </RouterLink>
                <span v-else>{{ row.order_number || "—" }}</span>
              </td>
              <td>{{ row.recipient_name || "—" }}</td>
              <td>{{ formatDate(row.created_at) }}</td>
              <td v-if="!isPortalMode">{{ row.client_account_company_name || "—" }}</td>
              <td>{{ row.country || "—" }}</td>
              <td class="text-end">{{ row.line_items_count ?? 0 }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <OrderCreateDrawer
      v-if="canWriteOrders"
      v-model:open="createOrderModalOpen"
      :portal-mode="isPortalMode"
      :portal-account-id="portalAccountId"
      :accounts="accounts"
      :accounts-loading="accountsLoading"
      :initial-account-id="String(selectedAccountId || route.query.client_account_id || '')"
      @created="onDraftOrderCreated"
    />
  </div>
</template>

<style scoped>
.orders-page-toolbar .staff-table-toolbar--row.orders-toolbar-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

@media (max-width: 767.98px) {
  .orders-page-toolbar .staff-table-toolbar--row.orders-toolbar-row {
    display: flex;
  }
}

.orders-toolbar-account {
  flex: 0 0 auto;
  width: min(280px, 100%);
}
</style>
