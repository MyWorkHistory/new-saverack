<script setup>
import { computed, inject, onMounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";

const toast = useToast();
const router = useRouter();
const crmUser = inject("crmUser", ref(null));

const loading = ref(true);
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
const search = ref("");
const searchDebounced = ref("");
let searchTimer = null;

const selected = ref(new Set());
const bulkDeleteOpen = ref(false);
const bulkDeleteBusy = ref(false);

const clientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));

const allSelected = computed(() => {
  if (rows.value.length === 0) return false;
  return rows.value.every((r) => selected.value.has(r.id));
});

const anyPendingSelected = computed(() => {
  for (const r of rows.value) {
    if (selected.value.has(r.id) && r.status === "pending") return true;
  }
  return false;
});

watch(search, (v) => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    searchDebounced.value = v.trim();
    meta.value.current_page = 1;
    load();
  }, 300);
});

async function load() {
  if (!clientAccountId.value) {
    loading.value = false;
    return;
  }
  loading.value = true;
  try {
    const { data } = await api.get("/asns", {
      params: {
        client_account_id: clientAccountId.value,
        q: searchDebounced.value || undefined,
        page: meta.value.current_page,
        per_page: meta.value.per_page,
      },
    });
    rows.value = data.data || [];
    if (data.meta) {
      meta.value = { ...meta.value, ...data.meta };
    }
    selected.value = new Set();
  } catch (e) {
    toast.errorFrom(e, "Could not load ASNs.");
  } finally {
    loading.value = false;
  }
}

function toggleAll() {
  if (allSelected.value) {
    selected.value = new Set();
  } else {
    selected.value = new Set(rows.value.map((r) => r.id));
  }
}

function toggleOne(id) {
  const next = new Set(selected.value);
  if (next.has(id)) next.delete(id);
  else next.add(id);
  selected.value = next;
}

function statusLabel(s) {
  if (s === "in_progress") return "In Progress";
  if (s === "completed") return "Completed";
  return "Pending";
}

async function createAsn() {
  if (!clientAccountId.value) return;
  try {
    const { data } = await api.post("/asns", { client_account_id: clientAccountId.value });
    toast.success("ASN created.");
    await router.push({ name: "user-asn-detail", params: { id: String(data.id) } });
  } catch (e) {
    toast.errorFrom(e, "Could not create ASN.");
  }
}

async function confirmBulkDelete() {
  if (!clientAccountId.value || selected.value.size === 0) return;
  bulkDeleteBusy.value = true;
  try {
    await api.post("/asns/bulk-delete", {
      client_account_id: clientAccountId.value,
      ids: Array.from(selected.value),
    });
    toast.success("Deleted selected ASNs.");
    bulkDeleteOpen.value = false;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Bulk delete failed.");
  } finally {
    bulkDeleteBusy.value = false;
  }
}

function openRow(r) {
  router.push({ name: "user-asn-detail", params: { id: String(r.id) } });
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | ASN",
    description: "Advance shipping notices for your account.",
  });
  load();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">ASN</h1>
        <p class="staff-page__intro mb-0">Advance shipping notices. Search by ASN # or tracking #.</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-primary btn-sm fw-semibold" @click="createAsn">Create ASN</button>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white mb-3">
      <div class="staff-table-toolbar p-3 border-bottom">
        <div class="d-flex flex-wrap align-items-center gap-2">
          <input
            v-model="search"
            type="search"
            class="form-control form-control-sm"
            style="max-width: 280px"
            placeholder="Search ASN # or tracking #"
            aria-label="Search ASN"
          />
          <button
            type="button"
            class="btn btn-sm btn-outline-danger"
            :disabled="!anyPendingSelected || loading"
            @click="bulkDeleteOpen = true"
          >
            Delete Selected
          </button>
        </div>
      </div>

      <div v-if="loading" class="p-5">
        <CrmLoadingSpinner message="Loading ASNs…" />
      </div>
      <div v-else class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width: 2.5rem" class="border-0">
                <input
                  class="form-check-input"
                  type="checkbox"
                  :checked="allSelected"
                  aria-label="Select all"
                  @change="toggleAll"
                />
              </th>
              <th class="border-0">Status</th>
              <th class="border-0">ASN #</th>
              <th class="border-0">Date Created</th>
              <th class="border-0 text-end">Expected QTY</th>
              <th class="border-0 text-end">Accepted QTY</th>
              <th class="border-0 text-end">Rejected QTY</th>
              <th class="border-0 text-end">Total Boxes</th>
              <th class="border-0">Tracking</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in rows" :key="r.id" class="cursor-pointer" @click="openRow(r)">
              <td @click.stop>
                <input
                  class="form-check-input"
                  type="checkbox"
                  :checked="selected.has(r.id)"
                  @change="toggleOne(r.id)"
                />
              </td>
              <td>
                <span class="badge text-bg-secondary text-uppercase">{{ statusLabel(r.status) }}</span>
              </td>
              <td class="fw-semibold">{{ r.asn_number }}</td>
              <td class="text-secondary small">{{ new Date(r.created_at).toLocaleString() }}</td>
              <td class="text-end">{{ r.expected_qty?.toLocaleString?.() ?? r.expected_qty }}</td>
              <td class="text-end">{{ r.accepted_qty?.toLocaleString?.() ?? r.accepted_qty }}</td>
              <td class="text-end">{{ r.rejected_qty?.toLocaleString?.() ?? r.rejected_qty }}</td>
              <td class="text-end">{{ r.total_boxes }}</td>
              <td class="small text-secondary text-truncate" style="max-width: 220px">{{ r.tracking_display }}</td>
            </tr>
            <tr v-if="rows.length === 0">
              <td colspan="9" class="text-center text-secondary py-5">No ASNs yet. Create one to get started.</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="!loading && meta.last_page > 1" class="d-flex justify-content-between align-items-center p-3 border-top">
        <span class="small text-secondary">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
        <div class="btn-group btn-group-sm">
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="meta.current_page <= 1"
            @click="meta.current_page--; load()"
          >
            Previous
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="meta.current_page >= meta.last_page"
            @click="meta.current_page++; load()"
          >
            Next
          </button>
        </div>
      </div>
    </div>

    <ConfirmModal
      :open="bulkDeleteOpen"
      title="Delete Selected ASNs"
      message="Only pending ASNs will be removed. This cannot be undone."
      confirm-label="Delete"
      :busy="bulkDeleteBusy"
      danger
      @close="bulkDeleteOpen = false"
      @confirm="confirmBulkDelete"
    />
  </div>
</template>

<style scoped>
.cursor-pointer {
  cursor: pointer;
}
</style>
