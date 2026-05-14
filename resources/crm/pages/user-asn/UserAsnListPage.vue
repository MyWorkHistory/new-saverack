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

const sortBy = ref("created_at");
const sortDir = ref("desc");

const selected = ref(new Set());
const bulkDeleteOpen = ref(false);
const bulkDeleteBusy = ref(false);

const clientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));

const tableColspan = 9;

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

function sortIndicator(column) {
  if (sortBy.value !== column) return "";
  return sortDir.value === "asc" ? "↑" : "↓";
}

function toggleSort(column) {
  if (sortBy.value !== column) {
    sortBy.value = column;
    sortDir.value = "asc";
  } else {
    sortDir.value = sortDir.value === "asc" ? "desc" : "asc";
  }
  meta.value.current_page = 1;
  load();
}

function statusLabel(s) {
  if (s === "in_progress") return "In Progress";
  if (s === "completed") return "Completed";
  return "Pending";
}

function statusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "pending") return "bg-secondary-subtle text-secondary-emphasis";
  if (s === "in_progress") return "bg-primary-subtle text-primary-emphasis";
  if (s === "completed") return "bg-success-subtle text-success-emphasis";
  return "bg-body-secondary text-body-secondary";
}

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
        sort_by: sortBy.value,
        sort_dir: sortDir.value,
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

function formatCreated(iso) {
  try {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return "—";
    return d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
  } catch {
    return "—";
  }
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
      <button type="button" class="btn btn-primary staff-page-primary" @click="createAsn">Create ASN</button>
    </div>

    <div class="user-asn-list staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
          <input
            id="asn-list-search"
            v-model="search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search ASN # or tracking #"
            autocomplete="off"
            aria-label="Search ASN"
            @keydown.enter.prevent="load"
          />
          <div class="staff-toolbar-row-actions d-flex flex-wrap align-items-center gap-2 ms-md-auto flex-shrink-0">
            <button
              type="button"
              class="btn btn-outline-danger staff-toolbar-btn"
              :disabled="!anyPendingSelected || loading"
              @click="bulkDeleteOpen = true"
            >
              Delete Selected
            </button>
          </div>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th staff-table-head__th--select text-center" scope="col" style="width: 2.75rem">
                <input
                  type="checkbox"
                  class="form-check-input staff-table-head__check m-0"
                  :checked="allSelected"
                  :disabled="loading || !rows.length"
                  aria-label="Select all on page"
                  @change="toggleAll"
                />
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('status')">
                  Status
                  <span v-if="sortIndicator('status')" class="staff-sort-ind">{{ sortIndicator("status") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('asn_number')">
                  ASN #
                  <span v-if="sortIndicator('asn_number')" class="staff-sort-ind">{{ sortIndicator("asn_number") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('created_at')">
                  Date Created
                  <span v-if="sortIndicator('created_at')" class="staff-sort-ind">{{ sortIndicator("created_at") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('expected_qty')">
                  Expected QTY
                  <span v-if="sortIndicator('expected_qty')" class="staff-sort-ind">{{ sortIndicator("expected_qty") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('accepted_qty')">
                  Accepted QTY
                  <span v-if="sortIndicator('accepted_qty')" class="staff-sort-ind">{{ sortIndicator("accepted_qty") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('rejected_qty')">
                  Rejected QTY
                  <span v-if="sortIndicator('rejected_qty')" class="staff-sort-ind">{{ sortIndicator("rejected_qty") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('total_boxes')">
                  Total Boxes
                  <span v-if="sortIndicator('total_boxes')" class="staff-sort-ind">{{ sortIndicator("total_boxes") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th text-center" scope="col">Tracking</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading ASNs…" />
                </div>
              </td>
            </tr>
            <template v-else>
              <tr v-for="r in rows" :key="r.id" class="align-middle cursor-pointer" @click="openRow(r)">
                <td class="text-center staff-table-cell--tight-check" @click.stop>
                  <input
                    type="checkbox"
                    class="form-check-input staff-table-head__check m-0"
                    :checked="selected.has(r.id)"
                    :aria-label="`Select ASN ${r.asn_number}`"
                    @change="toggleOne(r.id)"
                  />
                </td>
                <td class="text-center">
                  <span class="badge rounded-pill fw-medium" :class="statusBadgeClass(r.status)">
                    {{ statusLabel(r.status) }}
                  </span>
                </td>
                <td class="text-center fw-semibold">{{ r.asn_number }}</td>
                <td class="text-center small text-secondary">{{ formatCreated(r.created_at) }}</td>
                <td class="text-center">{{ Number(r.expected_qty ?? 0).toLocaleString() }}</td>
                <td class="text-center">{{ Number(r.accepted_qty ?? 0).toLocaleString() }}</td>
                <td class="text-center">{{ Number(r.rejected_qty ?? 0).toLocaleString() }}</td>
                <td class="text-center">{{ Number(r.total_boxes ?? 0).toLocaleString() }}</td>
                <td class="text-center small text-secondary text-truncate" style="max-width: 14rem">
                  {{ r.tracking_display || "—" }}
                </td>
              </tr>
              <tr v-if="rows.length === 0">
                <td :colspan="tableColspan" class="text-center text-secondary py-5">
                  No ASNs yet. Use Create ASN to get started.
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
      <p class="staff-table-mobile-scroll-cue d-md-none" aria-hidden="true">
        Scroll sideways or swipe to see all columns.
      </p>

      <div
        v-if="!loading && meta.last_page > 1"
        class="staff-table-footer card-footer d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between gap-2"
      >
        <span class="small text-secondary">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
        <div class="btn-group btn-group-sm ms-sm-auto">
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="meta.current_page <= 1"
            @click="
              meta.current_page--;
              load();
            "
          >
            Previous
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="meta.current_page >= meta.last_page"
            @click="
              meta.current_page++;
              load();
            "
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

.user-asn-list :deep(.staff-table-head__th--sort .staff-sort-btn) {
  justify-content: center;
  width: 100%;
  text-align: center;
}

.user-asn-list :deep(.staff-table-footer .btn-outline-secondary:hover:not(:disabled)),
.user-asn-list :deep(.staff-table-footer .btn-outline-secondary:focus-visible) {
  background-color: rgba(115, 103, 240, 0.06);
  border-color: rgba(115, 103, 240, 0.35);
  color: var(--bs-body-color);
}

[data-bs-theme="dark"] .user-asn-list :deep(.staff-table-footer .btn-outline-secondary:hover:not(:disabled)),
[data-bs-theme="dark"] .user-asn-list :deep(.staff-table-footer .btn-outline-secondary:focus-visible) {
  background-color: rgba(115, 103, 240, 0.12);
  border-color: rgba(186, 175, 255, 0.35);
  color: var(--bs-body-color);
}
</style>
