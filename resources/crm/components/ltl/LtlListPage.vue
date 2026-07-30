<script setup>
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import LtlCreateDrawer from "./LtlCreateDrawer.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import {
  formatLtlMoney,
  ltlStatusBadgeClass,
  LTL_STATUSES,
} from "../../constants/ltlSections.js";

const props = defineProps({
  /** admin | portal */
  mode: { type: String, default: "admin" },
});

const toast = useToast();
const router = useRouter();
const isPortal = computed(() => props.mode === "portal");
const apiBase = computed(() => (isPortal.value ? "/ltl-shipments" : "/admin/ltl-shipments"));
const detailRoute = computed(() => (isPortal.value ? "user-ltl-detail" : "admin-ltl-detail"));

const loading = ref(true);
const rows = ref([]);
const statusFilter = ref("");
const search = ref("");
const createOpen = ref(false);
const createBusy = ref(false);
const accountOptions = ref([]);

async function loadAccounts() {
  if (isPortal.value) return;
  try {
    const { data } = await api.get("/inventory/client-account-options");
    const list = Array.isArray(data?.accounts)
      ? data.accounts
      : Array.isArray(data?.data)
        ? data.data
        : Array.isArray(data)
          ? data
          : [];
    accountOptions.value = list.map((a) => ({
      id: a.id,
      name: a.company_name || a.label || `Account #${a.id}`,
    }));
  } catch {
    accountOptions.value = [];
  }
}

async function load() {
  loading.value = true;
  try {
    const params = { per_page: 50 };
    if (statusFilter.value) params.status = statusFilter.value;
    if (search.value.trim()) params.q = search.value.trim();
    const { data } = await api.get(apiBase.value, { params });
    rows.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load LTL shipments.");
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

async function onCreate(payload) {
  createBusy.value = true;
  try {
    const body = { ...payload };
    if (isPortal.value) delete body.client_account_id;
    const { data } = await api.post(apiBase.value, body);
    createOpen.value = false;
    toast.success("LTL created.");
    const id = data?.shipment?.id;
    if (id) {
      router.push({ name: detailRoute.value, params: { id: String(id) } });
    } else {
      await load();
    }
  } catch (e) {
    toast.errorFrom(e, "Could not create LTL.");
  } finally {
    createBusy.value = false;
  }
}

function openDetail(row) {
  router.push({ name: detailRoute.value, params: { id: String(row.id) } });
}

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | LTL",
    description: "LTL freight quotes and shipments.",
  });
  await Promise.all([loadAccounts(), load()]);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold">LTL</h1>
        <p class="text-secondary small mb-0">Less-than-truckload freight quotes.</p>
      </div>
      <button type="button" class="btn btn-primary btn-sm" @click="createOpen = true">
        Create LTL
      </button>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3">
      <input
        v-model="search"
        type="search"
        class="form-control form-control-sm"
        style="max-width: 220px"
        placeholder="Search…"
        @keyup.enter="load"
      />
      <select v-model="statusFilter" class="form-select form-select-sm" style="max-width: 160px" @change="load">
        <option value="">All statuses</option>
        <option v-for="(meta, key) in LTL_STATUSES" :key="key" :value="key">{{ meta.label }}</option>
      </select>
      <button type="button" class="btn btn-outline-secondary btn-sm" @click="load">Apply</button>
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner />
    </div>
    <div v-else class="staff-datatable-card staff-datatable-card--white">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr>
              <th>Status</th>
              <th>LTL #</th>
              <th v-if="!isPortal">Account</th>
              <th>Destination</th>
              <th>Carrier</th>
              <th>Price</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!rows.length">
              <td :colspan="isPortal ? 6 : 7" class="text-center text-secondary py-4">No LTL shipments yet.</td>
            </tr>
            <tr v-for="row in rows" :key="row.id" class="cursor-pointer" @click="openDetail(row)">
              <td>
                <span :class="ltlStatusBadgeClass(row.status)">{{ row.status_label || row.status }}</span>
              </td>
              <td class="fw-semibold">{{ row.number }}</td>
              <td v-if="!isPortal">{{ row.account_name || "—" }}</td>
              <td>{{ row.destination_label || "—" }}</td>
              <td>{{ row.quote_carrier || "—" }}</td>
              <td>{{ formatLtlMoney(row.quote_amount_cents) }}</td>
              <td class="text-end">
                <button type="button" class="btn btn-sm btn-outline-primary" @click.stop="openDetail(row)">
                  Open
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <LtlCreateDrawer
      v-model:open="createOpen"
      :portal="isPortal"
      :busy="createBusy"
      :account-options="accountOptions"
      @submit="onCreate"
    />
  </div>
</template>

<style scoped>
.cursor-pointer {
  cursor: pointer;
}
</style>
