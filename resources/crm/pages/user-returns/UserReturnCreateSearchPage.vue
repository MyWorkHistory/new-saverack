<script setup>
import { computed, inject, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";

const toast = useToast();
const router = useRouter();
const route = useRoute();
const crmUser = inject("crmUser", ref(null));

const orderNumber = ref("");
const searching = ref(false);
const hasSearched = ref(false);
const results = ref([]);

const clientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));
const tableColspan = 4;

function normalizeOrderNumber(raw) {
  return String(raw || "").trim().replace(/^#+/, "").toLowerCase();
}

function orderMatchesQuery(row, query) {
  const needle = normalizeOrderNumber(query);
  if (!needle) return false;
  const fields = [
    row?.order_number,
    row?.partner_order_id,
    row?.legacy_id != null && row?.legacy_id !== "" ? String(row.legacy_id) : "",
  ];
  return fields.some((f) => normalizeOrderNumber(f) === needle);
}

async function search() {
  const q = orderNumber.value.trim();
  if (!q || !clientAccountId.value) return;
  searching.value = true;
  hasSearched.value = true;
  results.value = [];
  try {
    const { data } = await api.get("/orders", {
      params: {
        client_account_id: clientAccountId.value,
        tab: "manage",
        order_number: q.replace(/^#+/, ""),
        first: 25,
      },
    });
    const rows = Array.isArray(data?.rows) ? data.rows : [];
    let matched = rows.filter((row) => orderMatchesQuery(row, q));
    if (!matched.length && rows.length === 1 && rows[0]?.id) {
      matched = rows;
    }
    results.value = matched;
  } catch (e) {
    toast.errorFrom(e, "Could not search orders.");
  } finally {
    searching.value = false;
  }
}

function customerDisplay(row) {
  const recipient = String(row?.recipient_name || "").trim();
  if (recipient && recipient !== "—") return recipient;
  const ship = row?.shipping_address || row?.ship_to || {};
  const name = [ship.first_name, ship.last_name].filter(Boolean).join(" ").trim();
  if (name) return name;
  if (ship.company) return String(ship.company);
  return row?.customer_name || row?.email || "—";
}

function returnCreateOrderHref(row) {
  const id = row?.id || row?.shiphero_order_id;
  if (!id || !clientAccountId.value) return "";
  const baseQuery = route?.query && typeof route.query === "object" ? route.query : {};
  return router.resolve({
    name: "user-return-create",
    params: { shipheroOrderId: String(id) },
    query: {
      ...baseQuery,
      client_account_id: String(clientAccountId.value),
    },
  }).href;
}

function openOrderInNewTab(row) {
  const href = returnCreateOrderHref(row);
  if (!href) return;
  window.open(href, "_blank", "noopener,noreferrer");
}

function goToManualReturn() {
  const num = orderNumber.value.trim().replace(/^#+/, "");
  if (!num || !clientAccountId.value) return;
  router.push({
    name: "user-return-create-manual",
    query: {
      order_number: num,
      client_account_id: String(clientAccountId.value),
    },
  });
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Create Return",
    description: "Search for an order to start a return.",
  });
});
</script>

<template>
  <div class="staff-page staff-page--wide user-return-page">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Create Return</h1>
        <p class="text-secondary small mb-0">
          Search by order number, then open the order to start your return.
        </p>
      </div>
      <button
        type="button"
        class="btn btn-outline-secondary btn-sm fw-semibold orders-toolbar-outline-btn"
        @click="router.push({ name: 'user-return-orders' })"
      >
        Return Orders
      </button>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
          <input
            id="return-order-search"
            v-model="orderNumber"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search by order number"
            autocomplete="off"
            aria-label="Order number"
            @keydown.enter.prevent="search"
          />
          <button
            type="button"
            class="btn btn-primary staff-page-primary fw-semibold"
            :disabled="searching || !orderNumber.trim()"
            @click="search"
          >
            Search
          </button>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">Order #</th>
              <th class="staff-table-head__th text-center" scope="col">Name</th>
              <th class="staff-table-head__th text-center" scope="col">Status</th>
              <th class="staff-table-head__th text-center staff-actions-col" scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="searching">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Searching orders…" />
                </div>
              </td>
            </tr>
            <tr v-else-if="!results.length">
              <td :colspan="tableColspan" class="text-center text-secondary py-5">
                <template v-if="hasSearched">
                  <p class="mb-2">No order found for that order number.</p>
                  <button
                    type="button"
                    class="btn btn-link btn-sm px-0"
                    @click="goToManualReturn"
                  >
                    Add Manual Return
                  </button>
                </template>
                <template v-else>Enter an order number and select Search.</template>
              </td>
            </tr>
            <tr v-for="row in results" v-else :key="row.id" class="align-middle">
              <td class="text-center">
                <a
                  v-if="returnCreateOrderHref(row)"
                  :href="returnCreateOrderHref(row)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="user-return-page__order-link"
                >
                  {{ row.order_number || "—" }}
                </a>
                <span v-else>—</span>
              </td>
              <td class="text-center">{{ customerDisplay(row) }}</td>
              <td class="text-center small text-secondary">{{ row.status || row.fulfillment_status || "—" }}</td>
              <td class="text-center staff-actions-cell">
                <div class="user-return-page__action-inner">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary orders-toolbar-outline-btn fw-semibold"
                    @click="openOrderInNewTab(row)"
                  >
                    View
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="staff-table-mobile-scroll-cue d-md-none px-3 pb-2 mb-0" aria-hidden="true">
        Scroll sideways or swipe to see all columns.
      </p>
    </div>
  </div>
</template>
