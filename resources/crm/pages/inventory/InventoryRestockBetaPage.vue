<script setup>
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateTimeUs, formatIsoDate } from "../../utils/formatUserDates.js";

const toast = useToast();
const router = useRouter();

const rows = ref([]);
const loading = ref(false);
const accountsLoading = ref(false);
const accounts = ref([]);
const selectedAccountId = ref("");
const searchQuery = ref("");
const uploadModalOpen = ref(false);
const uploadBusy = ref(false);
const uploadFile = ref(null);
const completingSku = ref("");
const meta = ref({
  original_filename: null,
  row_count: 0,
  active_row_count: 0,
  restock_needed_total: 0,
  uploaded_at: null,
});

const accountOptions = computed(() =>
  (accounts.value || [])
    .filter((a) => a?.has_shiphero_customer)
    .map((a) => ({
      id: a.id,
      name: a.company_name || `Account #${a.id}`,
      email: "",
    })),
);

const uploadedAtLabel = computed(() => {
  const raw = meta.value.uploaded_at;
  if (!raw) return null;
  const d = new Date(raw);
  if (Number.isNaN(d.getTime())) return null;
  return formatDateTimeUs(d);
});

const lastUploadDateLabel = computed(() => {
  const raw = meta.value.uploaded_at;
  if (!raw) return null;
  return formatIsoDate(raw);
});

const filteredRows = computed(() => {
  const accountId = Number(selectedAccountId.value || 0);
  const q = searchQuery.value.trim().toLowerCase();
  return rows.value.filter((row) => {
    if (accountId > 0 && Number(row?.client_account_id || 0) !== accountId) {
      return false;
    }
    if (!q) return true;
    const sku = String(row?.sku || "").toLowerCase();
    const name = String(row?.name || "").toLowerCase();
    const account = String(row?.account_name || "").toLowerCase();
    return sku.includes(q) || name.includes(q) || account.includes(q);
  });
});

const visibleRestockNeededTotal = computed(() =>
  filteredRows.value.reduce((sum, row) => {
    const n = Number(row?.restock_needed);
    return sum + (Number.isNaN(n) ? 0 : n);
  }, 0),
);

function inventoryDetailTo(row) {
  const sku = String(row?.sku || "").trim();
  if (!sku) {
    return { name: "inventory-detail", params: { sku: "" } };
  }
  const accountId = Number(row?.client_account_id || 0);
  const query = accountId > 0 ? { client_account_id: String(accountId) } : {};
  return {
    name: "inventory-detail",
    params: { sku },
    query,
  };
}

function inventoryDetailHref(row) {
  const sku = String(row?.sku || "").trim();
  if (!sku) return "#";
  return router.resolve(inventoryDetailTo(row)).href;
}

function splitBackstockLocations(text) {
  const raw = String(text || "").trim();
  if (!raw) return [];
  return raw
    .split(/\s*,\s*/)
    .map((part) => part.trim())
    .filter(Boolean);
}

function formatQty(value) {
  if (value === null || value === undefined || value === "") return "—";
  const n = Number(value);
  if (Number.isNaN(n)) return "—";
  return n.toLocaleString();
}

function applySnapshot(data) {
  rows.value = Array.isArray(data?.rows) ? data.rows : [];
  meta.value = {
    original_filename: data?.original_filename ?? null,
    row_count: Number(data?.row_count || 0),
    active_row_count: Number(data?.active_row_count ?? rows.value.length),
    restock_needed_total: Number(data?.restock_needed_total || 0),
    uploaded_at: data?.uploaded_at ?? null,
  };
}

async function loadAccounts() {
  accountsLoading.value = true;
  try {
    const { data } = await api.get("/inventory/client-account-options");
    accounts.value = Array.isArray(data?.accounts) ? data.accounts : [];
  } catch {
    accounts.value = [];
  } finally {
    accountsLoading.value = false;
  }
}

async function loadSnapshot() {
  loading.value = true;
  try {
    const { data } = await api.get("/inventory/restock-beta");
    applySnapshot(data);
  } catch (e) {
    toast.errorFrom(e, "Could not load restock data.");
  } finally {
    loading.value = false;
  }
}

function openUploadModal() {
  uploadFile.value = null;
  uploadModalOpen.value = true;
}

function closeUploadModal(force = false) {
  if (uploadBusy.value && !force) return;
  uploadModalOpen.value = false;
  uploadFile.value = null;
}

function onUploadFileChange(event) {
  const file = event.target.files?.[0] ?? null;
  uploadFile.value = file;
}

async function submitUpload() {
  if (!uploadFile.value) {
    toast.error("Choose a CSV file to upload.");
    return;
  }

  uploadBusy.value = true;
  try {
    const formData = new FormData();
    formData.append("file", uploadFile.value);
    const { data } = await api.post("/inventory/restock-beta/import", formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });
    applySnapshot(data);
    toast.success(`Uploaded ${Number(data?.active_row_count ?? data?.row_count ?? 0).toLocaleString()} rows.`);
    closeUploadModal(true);
  } catch (e) {
    toast.errorFrom(e, "Could not upload CSV.");
  } finally {
    uploadBusy.value = false;
  }
}

async function completeRow(row) {
  const sku = String(row?.sku || "").trim();
  if (!sku || completingSku.value) return;

  completingSku.value = sku;
  try {
    const { data } = await api.post("/inventory/restock-beta/complete", { sku });
    applySnapshot(data);
    toast.success(`${sku} marked complete.`);
  } catch (e) {
    toast.errorFrom(e, "Could not complete row.");
  } finally {
    completingSku.value = "";
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Inventory | Restock (Beta)",
    description: "Upload a restock CSV and review SKUs that need replenishment.",
  });
  loadAccounts();
  loadSnapshot();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div
      class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 fw-semibold text-body mb-1">Restock (Beta)</h1>
        <p class="text-secondary small mb-0">
          Upload a restock CSV to review SKUs with backstock and replenishment needs. Use Restock to open inventory detail and transfer stock.
        </p>
        <p v-if="uploadedAtLabel" class="text-secondary small mb-0 mt-1">
          Last upload: {{ uploadedAtLabel }}
          <span v-if="meta.original_filename"> ({{ meta.original_filename }})</span>
        </p>
      </div>
      <div class="d-flex flex-column align-items-md-end gap-2 flex-shrink-0 ms-md-auto">
        <p v-if="rows.length" class="small text-danger fw-semibold mb-0">
          Restock Needed: {{ visibleRestockNeededTotal.toLocaleString() }}
        </p>
        <p v-if="lastUploadDateLabel" class="small text-danger mb-0">
          Last Upload: {{ lastUploadDateLabel }}
        </p>
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn"
          @click="openUploadModal"
        >
          Upload CSV
        </button>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row">
          <div class="restock-beta-toolbar-account flex-shrink-0">
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
              button-id="restock-beta-account-trigger"
            />
          </div>
          <input
            v-model="searchQuery"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search SKU or name"
            autocomplete="off"
          />
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th" scope="col">Product</th>
              <th class="staff-table-head__th" scope="col">Account</th>
              <th class="staff-table-head__th text-center" scope="col">On Hand</th>
              <th class="staff-table-head__th text-center" scope="col">Allocated</th>
              <th class="staff-table-head__th text-center" scope="col">Pickable QTY</th>
              <th class="staff-table-head__th text-center" scope="col">Backstock</th>
              <th class="staff-table-head__th text-center" scope="col">Restock Needed</th>
              <th class="staff-table-head__th" scope="col">Backstock locations</th>
              <th class="staff-table-head__th text-center staff-actions-col" scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="9" class="py-5 text-center text-secondary">Loading restock data…</td>
            </tr>
            <tr v-else-if="!rows.length">
              <td colspan="9" class="py-5 text-center text-secondary">
                Upload a restock CSV to get started.
              </td>
            </tr>
            <tr v-else-if="!filteredRows.length">
              <td colspan="9" class="py-5 text-center text-secondary">No rows match your search.</td>
            </tr>
            <tr v-for="row in filteredRows" :key="row.sku" class="align-middle">
              <td class="restock-beta-product-col">
                <div class="restock-beta-product">
                  <div class="restock-beta-product__name">{{ row.name || "—" }}</div>
                  <div class="restock-beta-product__sku text-secondary small">{{ row.sku }}</div>
                  <a
                    :href="inventoryDetailHref(row)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="btn btn-outline-primary btn-sm mt-2"
                  >
                    Restock
                  </a>
                </div>
              </td>
              <td class="small">{{ row.account_name || "—" }}</td>
              <td class="text-center">{{ formatQty(row.on_hand) }}</td>
              <td class="text-center">{{ formatQty(row.allocated) }}</td>
              <td class="text-center">{{ formatQty(row.pickable_qty) }}</td>
              <td class="text-center">{{ formatQty(row.backstock_qty) }}</td>
              <td class="text-center">{{ formatQty(row.restock_needed) }}</td>
              <td class="restock-beta-locations-col">
                <template v-if="splitBackstockLocations(row.backstock_locations).length">
                  <div
                    v-for="(location, index) in splitBackstockLocations(row.backstock_locations)"
                    :key="`${row.sku}-${index}`"
                    class="restock-beta-loc-row small text-secondary"
                  >
                    {{ location }}
                  </div>
                </template>
                <span v-else class="text-secondary">—</span>
              </td>
              <td class="text-center">
                <button
                  type="button"
                  class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn"
                  :disabled="completingSku === row.sku"
                  @click="completeRow(row)"
                >
                  {{ completingSku === row.sku ? "Completing…" : "Complete" }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <Teleport to="body">
      <Transition name="crm-vx-confirm">
        <div
          v-if="uploadModalOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          @click.self="closeUploadModal"
        >
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <header class="crm-vx-modal__head border-bottom">
              <h2 class="crm-vx-modal__title mb-0">Upload Restock CSV</h2>
            </header>
            <div class="crm-vx-modal__body">
              <div class="mb-0">
                <label class="form-label" for="restock-beta-upload-file">CSV File</label>
                <input
                  id="restock-beta-upload-file"
                  type="file"
                  class="form-control"
                  accept=".csv,text/csv,text/plain"
                  @change="onUploadFileChange"
                />
              </div>
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="uploadBusy"
                @click="closeUploadModal"
              >
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="uploadBusy"
                @click="submitUpload"
              >
                {{ uploadBusy ? "Uploading…" : "Upload CSV" }}
              </button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.restock-beta-toolbar-account {
  min-width: 12rem;
  max-width: 16rem;
}

.restock-beta-product-col {
  min-width: 14rem;
  max-width: min(20rem, 32vw);
}

.restock-beta-product__name {
  white-space: normal;
  word-break: break-word;
  line-height: 1.4;
}

.restock-beta-product__sku {
  user-select: text;
}

.restock-beta-locations-col {
  min-width: 10rem;
  max-width: min(18rem, 28vw);
}

.restock-beta-loc-row + .restock-beta-loc-row {
  margin-top: 0.25rem;
}
</style>
