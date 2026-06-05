<script setup>
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateTimeUs } from "../../utils/formatUserDates.js";

const toast = useToast();
const router = useRouter();

const rows = ref([]);
const loading = ref(false);
const searchQuery = ref("");
const uploadModalOpen = ref(false);
const uploadBusy = ref(false);
const uploadFile = ref(null);
const meta = ref({
  original_filename: null,
  row_count: 0,
  uploaded_at: null,
});

const uploadedAtLabel = computed(() => {
  const raw = meta.value.uploaded_at;
  if (!raw) return null;
  const d = new Date(raw);
  if (Number.isNaN(d.getTime())) return null;
  return formatDateTimeUs(d);
});

const filteredRows = computed(() => {
  const q = searchQuery.value.trim().toLowerCase();
  if (!q) return rows.value;
  return rows.value.filter((row) => {
    const sku = String(row?.sku || "").toLowerCase();
    const name = String(row?.name || "").toLowerCase();
    return sku.includes(q) || name.includes(q);
  });
});

function inventoryDetailHref(row) {
  const sku = String(row?.sku || "").trim();
  if (!sku) return "#";
  return router.resolve({
    name: "inventory-detail",
    params: { sku },
  }).href;
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
    row_count: Number(data?.row_count || rows.value.length),
    uploaded_at: data?.uploaded_at ?? null,
  };
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
    toast.success(`Uploaded ${Number(data?.row_count || 0).toLocaleString()} rows.`);
    closeUploadModal(true);
  } catch (e) {
    toast.errorFrom(e, "Could not upload CSV.");
  } finally {
    uploadBusy.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Inventory | Restock (Beta)",
    description: "Upload a restock CSV and review SKUs that need replenishment.",
  });
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
          Upload a restock CSV to review SKUs with backstock and replenishment needs. Use View Product to open inventory detail and transfer stock.
        </p>
        <p v-if="uploadedAtLabel" class="text-secondary small mb-0 mt-1">
          Last upload: {{ uploadedAtLabel }}
          <span v-if="meta.original_filename"> ({{ meta.original_filename }})</span>
        </p>
      </div>
      <div class="ms-md-auto flex-shrink-0">
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
              <th class="staff-table-head__th" scope="col">SKU</th>
              <th class="staff-table-head__th" scope="col">Name</th>
              <th class="staff-table-head__th text-center" scope="col">On Hand</th>
              <th class="staff-table-head__th text-center" scope="col">Allocated</th>
              <th class="staff-table-head__th text-center" scope="col">Pickable QTY</th>
              <th class="staff-table-head__th text-center" scope="col">Backstock</th>
              <th class="staff-table-head__th text-center" scope="col">Restock Needed</th>
              <th class="staff-table-head__th" scope="col">Backstock locations</th>
              <th class="staff-table-head__th text-center" scope="col">Action</th>
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
              <td class="fw-semibold">
                <a
                  :href="inventoryDetailHref(row)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="user-inv-table__sku-link"
                >
                  {{ row.sku }}
                </a>
              </td>
              <td>{{ row.name || "—" }}</td>
              <td class="text-center">{{ formatQty(row.on_hand) }}</td>
              <td class="text-center">{{ formatQty(row.allocated) }}</td>
              <td class="text-center">{{ formatQty(row.pickable_qty) }}</td>
              <td class="text-center">{{ formatQty(row.backstock_qty) }}</td>
              <td class="text-center">{{ formatQty(row.restock_needed) }}</td>
              <td class="text-secondary small">{{ row.backstock_locations || "—" }}</td>
              <td class="text-center">
                <a
                  :href="inventoryDetailHref(row)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="btn btn-link btn-sm p-0"
                >
                  View Product
                </a>
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
