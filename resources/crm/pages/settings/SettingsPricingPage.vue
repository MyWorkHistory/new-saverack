<script setup>
import { computed, inject, onMounted, ref, watch } from "vue";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import PricingFeeModal from "../../components/settings/PricingFeeModal.vue";
import { useToast } from "../../composables/useToast.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { crmIsAdmin } from "../../utils/crmUser.js";

const CATEGORY_OPTIONS = [
  { value: "all", label: "All Categories" },
  { value: "fulfillment", label: "Fulfillment" },
  { value: "returns", label: "Returns" },
  { value: "storage", label: "Storage" },
  { value: "receiving", label: "Receiving" },
  { value: "custom_work", label: "Custom Work" },
];

const crmUser = inject("crmUser", ref(null));
const toast = useToast();

const loading = ref(true);
const fees = ref([]);
const search = ref("");
const categoryFilter = ref("all");
const modalOpen = ref(false);
const editingFee = ref(null);
const saving = ref(false);
const deleteTarget = ref(null);
const deleteBusy = ref(false);

const canUpdate = computed(() => {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes("settings.update");
});

const filteredFees = computed(() => {
  const q = search.value.trim().toLowerCase();
  return fees.value.filter((f) => {
    if (categoryFilter.value !== "all" && f.category !== categoryFilter.value) {
      return false;
    }
    if (!q) return true;
    const hay = `${f.name ?? ""} ${f.description ?? ""}`.toLowerCase();
    return hay.includes(q);
  });
});

function formatPrice(amount) {
  const n = Number(amount);
  if (!Number.isFinite(n)) return "$0.00";
  try {
    return new Intl.NumberFormat(undefined, { style: "currency", currency: "USD" }).format(n);
  } catch {
    return `$${n}`;
  }
}

function excerpt(text, max = 100) {
  if (!text) return "";
  const s = String(text);
  return s.length <= max ? s : `${s.slice(0, max).trim()}…`;
}

let searchDebounce = null;
watch(search, () => {
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    fetchFees();
  }, 280);
});

watch(categoryFilter, () => {
  fetchFees();
});

async function fetchFees() {
  loading.value = true;
  try {
    const params = {};
    if (search.value.trim()) params.search = search.value.trim();
    if (categoryFilter.value !== "all") params.category = categoryFilter.value;
    const { data } = await api.get("/settings/pricing-fees", { params });
    fees.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load pricing fees.");
    fees.value = [];
  } finally {
    loading.value = false;
  }
}

function openCreate() {
  editingFee.value = null;
  modalOpen.value = true;
}

function openEdit(fee) {
  editingFee.value = { ...fee };
  modalOpen.value = true;
}

function closeModal() {
  if (!saving.value) {
    modalOpen.value = false;
    editingFee.value = null;
  }
}

function buildFormData(payload) {
  const fd = new FormData();
  fd.append("name", payload.name);
  if (payload.description) fd.append("description", payload.description);
  fd.append("category", payload.category);
  fd.append("amount", String(payload.amount));
  if (payload.icon) fd.append("icon", payload.icon);
  if (payload.remove_icon) fd.append("remove_icon", "1");
  return fd;
}

async function onSave(payload) {
  if (!canUpdate.value) return;
  saving.value = true;
  try {
    const fd = buildFormData(payload);
    if (editingFee.value?.id) {
      await api.patch(`/settings/pricing-fees/${editingFee.value.id}`, fd, {
        headers: { "Content-Type": "multipart/form-data" },
      });
      toast.success("Fee updated.");
    } else {
      await api.post("/settings/pricing-fees", fd, {
        headers: { "Content-Type": "multipart/form-data" },
      });
      toast.success("Fee created.");
    }
    modalOpen.value = false;
    editingFee.value = null;
    await fetchFees();
  } catch (e) {
    toast.errorFrom(e, "Could not save fee.");
  } finally {
    saving.value = false;
  }
}

function confirmDelete(fee) {
  deleteTarget.value = fee;
}

async function doDelete() {
  if (!deleteTarget.value) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/settings/pricing-fees/${deleteTarget.value.id}`);
    toast.success("Fee deleted.");
    deleteTarget.value = null;
    await fetchFees();
  } catch (e) {
    toast.errorFrom(e, "Could not delete fee.");
  } finally {
    deleteBusy.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Pricing",
    description: "Default fees applied to new client accounts.",
  });
  fetchFees();
});
</script>

<template>
  <div class="staff-page px-3 px-md-4 py-4">
    <header class="d-flex flex-column flex-md-row align-items-md-start justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 fw-semibold text-body mb-1">Pricing</h1>
        <p class="text-secondary small mb-0">
          Default fees applied to new accounts. Changes sync to linked account fees.
        </p>
      </div>
      <button
        v-if="canUpdate"
        type="button"
        class="btn btn-primary staff-page-primary flex-shrink-0"
        @click="openCreate"
      >
        Create Fee
      </button>
    </header>

    <div class="d-flex flex-column flex-sm-row gap-2 mb-4">
      <input
        v-model="search"
        type="search"
        class="form-control"
        placeholder="Search by name…"
        aria-label="Search fees"
      />
      <select v-model="categoryFilter" class="form-select" style="max-width: 14rem" aria-label="Category">
        <option v-for="opt in CATEGORY_OPTIONS" :key="opt.value" :value="opt.value">
          {{ opt.label }}
        </option>
      </select>
    </div>

    <CrmLoadingSpinner v-if="loading" />

    <div
      v-else-if="!filteredFees.length"
      class="text-center text-secondary py-5 staff-surface rounded"
    >
      <p class="mb-0">No fees match your filters.</p>
    </div>

    <div v-else class="row g-3">
      <div v-for="fee in filteredFees" :key="fee.id" class="col-12 col-md-6 col-xl-4">
        <article class="card h-100 staff-surface border-0 shadow-sm">
          <div class="card-body d-flex flex-column">
            <div class="d-flex align-items-start gap-3 mb-2">
              <div
                class="rounded border bg-light d-flex align-items-center justify-content-center flex-shrink-0"
                style="width: 48px; height: 48px"
              >
                <img
                  v-if="fee.icon_url"
                  :src="fee.icon_url"
                  :alt="fee.name"
                  class="rounded"
                  style="width: 44px; height: 44px; object-fit: contain"
                />
                <span v-else class="small text-secondary text-center px-1">
                  {{ fee.category_label }}
                </span>
              </div>
              <div class="flex-grow-1 min-w-0">
                <h2 class="h6 fw-semibold mb-1 text-truncate">{{ fee.name }}</h2>
                <span class="badge text-bg-light border text-secondary">{{ fee.category_label }}</span>
              </div>
            </div>
            <p v-if="fee.description" class="small text-secondary mb-2 flex-grow-1">
              {{ excerpt(fee.description) }}
            </p>
            <p v-else class="small text-secondary mb-2 flex-grow-1 fst-italic">No description</p>
            <div class="d-flex align-items-center justify-content-between mt-auto pt-2 border-top">
              <span class="fw-semibold text-body">{{ formatPrice(fee.amount) }}</span>
              <div v-if="canUpdate" class="btn-group btn-group-sm">
                <button type="button" class="btn btn-outline-secondary" @click="openEdit(fee)">
                  Edit
                </button>
                <button type="button" class="btn btn-outline-danger" @click="confirmDelete(fee)">
                  Delete
                </button>
              </div>
            </div>
          </div>
        </article>
      </div>
    </div>

    <PricingFeeModal
      :open="modalOpen"
      :fee="editingFee"
      :saving="saving"
      @close="closeModal"
      @save="onSave"
    />

    <ConfirmModal
      :open="!!deleteTarget"
      title="Delete Fee"
      message="Delete this default fee? This is only allowed when no client accounts are linked."
      confirm-label="Delete"
      :busy="deleteBusy"
      @close="deleteTarget = null"
      @confirm="doDelete"
    />
  </div>
</template>
