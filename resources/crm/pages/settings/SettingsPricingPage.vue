<script setup>
import { computed, inject, onMounted, onUnmounted, ref, watch } from "vue";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import PricingFeeModal from "../../components/settings/PricingFeeModal.vue";
import { useToast } from "../../composables/useToast.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";

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
const filterMenuOpen = ref(false);

const canUpdate = computed(() => {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes("settings.update");
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

function categoryBadgeClass(category) {
  const c = String(category || "").trim().toLowerCase();
  if (c === "fulfillment") return "settings-pricing-badge settings-pricing-badge--fulfillment";
  if (c === "returns") return "settings-pricing-badge settings-pricing-badge--returns";
  if (c === "storage") return "settings-pricing-badge settings-pricing-badge--storage";
  if (c === "receiving") return "settings-pricing-badge settings-pricing-badge--receiving";
  if (c === "custom_work") return "settings-pricing-badge settings-pricing-badge--custom";
  return "settings-pricing-badge";
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

function resetFilters() {
  categoryFilter.value = "all";
  filterMenuOpen.value = false;
}

function onDocClick(event) {
  if (!event.target.closest("[data-toolbar-filter]")) {
    filterMenuOpen.value = false;
  }
}

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

function buildFormData(payload, forUpdate = false) {
  const fd = new FormData();
  fd.append("name", payload.name);
  fd.append("description", payload.description ?? "");
  fd.append("category", payload.category);
  fd.append("amount", String(payload.amount));
  if (payload.icon) fd.append("icon", payload.icon);
  if (payload.remove_icon) fd.append("remove_icon", "1");
  if (forUpdate) {
    fd.append("_method", "PATCH");
  }
  return fd;
}

async function onSave(payload) {
  if (!canUpdate.value) return;
  const hadFilters = search.value.trim() !== "" || categoryFilter.value !== "all";
  saving.value = true;
  try {
    const filterNote = hadFilters ? " Filters were reset so you can see the saved fee." : "";
    if (editingFee.value?.id) {
      const fd = buildFormData(payload, true);
      await api.post(`/settings/pricing-fees/${editingFee.value.id}`, fd);
      toast.success(`Fee updated.${filterNote}`);
    } else {
      const fd = buildFormData(payload, false);
      await api.post("/settings/pricing-fees", fd);
      toast.success(`Fee created.${filterNote}`);
    }
    modalOpen.value = false;
    editingFee.value = null;
    if (hadFilters) {
      search.value = "";
      categoryFilter.value = "all";
    }
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
  document.addEventListener("click", onDocClick);
  fetchFees();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4">
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 fw-semibold text-body mb-1">Pricing</h1>
        <p class="text-secondary small mb-0">
          Default fees applied to new accounts. Changes sync to linked account fees.
        </p>
      </div>
      <div v-if="canUpdate" class="ms-md-auto">
        <button
          type="button"
          class="btn btn-primary staff-page-primary"
          @click="openCreate"
        >
          Create Fee
        </button>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row">
          <input
            v-model="search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search fee name or description"
            aria-label="Search fees"
            autocomplete="off"
          />
          <div class="position-relative flex-shrink-0" data-toolbar-filter>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen"
              aria-haspopup="true"
              aria-controls="pricing-filter-panel"
              :disabled="loading"
              @click.stop="filterMenuOpen = !filterMenuOpen"
            >
              <svg
                width="18"
                height="18"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                viewBox="0 0 24 24"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"
                />
              </svg>
              <span class="staff-toolbar-filter-text">Filters</span>
            </button>
            <div
              v-if="filterMenuOpen"
              id="pricing-filter-panel"
              class="dropdown-menu dropdown-menu-end show shadow border p-0 staff-toolbar-filter-dropdown"
              role="dialog"
              aria-label="Table filters"
              @click.stop
            >
              <div class="staff-toolbar-filter-dropdown__head">
                <span>Filters</span>
                <button
                  type="button"
                  class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
                  :disabled="loading"
                  @click="resetFilters"
                >
                  Reset
                </button>
              </div>
              <div class="staff-toolbar-filter-dropdown__body">
                <label class="form-label" for="pricing-filter-category">Category</label>
                <select id="pricing-filter-category" v-model="categoryFilter" class="form-select">
                  <option v-for="opt in CATEGORY_OPTIONS" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                  </option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="loading" class="d-flex justify-content-center py-5">
        <CrmLoadingSpinner message="Loading pricing fees…" />
      </div>

      <div v-else-if="!fees.length" class="text-center text-secondary py-5 px-3">
        <p class="mb-0">No fees match your filters.</p>
      </div>

      <div v-else class="staff-table-wrap">
        <div class="p-3 p-md-4">
          <div class="settings-pricing-cards">
          <div v-for="fee in fees" :key="fee.id">
            <article class="card h-100 staff-surface border-0 shadow-sm">
              <div class="card-body d-flex flex-column">
                <div class="d-flex align-items-start gap-3 mb-2">
                  <div class="settings-pricing-card__icon-wrap rounded border bg-light d-flex align-items-center justify-content-center flex-shrink-0">
                    <img
                      v-if="fee.icon_url"
                      :src="resolvePublicUrl(fee.icon_url)"
                      :alt="fee.name"
                      class="rounded"
                      style="width: 44px; height: 44px; object-fit: contain"
                    />
                    <span v-else class="settings-pricing-card__icon-fallback text-secondary text-center px-1">
                      <svg
                        v-if="fee.category === 'fulfillment'"
                        width="22"
                        height="22"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="1.75"
                        aria-hidden="true"
                      >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                      </svg>
                      <svg
                        v-else-if="fee.category === 'returns'"
                        width="22"
                        height="22"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="1.75"
                        aria-hidden="true"
                      >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a4 4 0 014 4v2M3 10l4-4m-4 4l4 4" />
                      </svg>
                      <svg
                        v-else-if="fee.category === 'storage'"
                        width="22"
                        height="22"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="1.75"
                        aria-hidden="true"
                      >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7.5A2.5 2.5 0 016.5 5h11A2.5 2.5 0 0120 7.5v9A2.5 2.5 0 0117.5 19h-11A2.5 2.5 0 014 16.5v-9Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5" />
                      </svg>
                      <svg
                        v-else-if="fee.category === 'receiving'"
                        width="22"
                        height="22"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="1.75"
                        aria-hidden="true"
                      >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h16m0 0-4-4m4 4-4 4" />
                      </svg>
                      <svg
                        v-else
                        width="22"
                        height="22"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="1.75"
                        aria-hidden="true"
                      >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m-6 5h6m-6 5h3M6 4h12a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2Z" />
                      </svg>
                    </span>
                  </div>
                  <div class="flex-grow-1 min-w-0">
                    <h2 class="h6 fw-semibold mb-1 text-truncate">{{ fee.name }}</h2>
                    <span :class="categoryBadgeClass(fee.category)">{{ fee.category_label }}</span>
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
        </div>
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

<style scoped>
.settings-pricing-cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 1rem;
  width: 100%;
}

.settings-pricing-card__icon-wrap {
  width: 48px;
  height: 48px;
  overflow: hidden;
}

.settings-pricing-card__icon-fallback {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
}

.settings-pricing-badge {
  display: inline-flex;
  align-items: center;
  border-radius: 9999px;
  padding: 0.2rem 0.55rem;
  font-size: 0.75rem;
  font-weight: 600;
  border: 1px solid transparent;
}

.settings-pricing-badge--fulfillment {
  color: #1d4ed8;
  background: #dbeafe;
  border-color: #bfdbfe;
}

.settings-pricing-badge--returns {
  color: #b45309;
  background: #fef3c7;
  border-color: #fde68a;
}

.settings-pricing-badge--storage {
  color: #0f766e;
  background: #ccfbf1;
  border-color: #99f6e4;
}

.settings-pricing-badge--receiving {
  color: #7c2d12;
  background: #ffedd5;
  border-color: #fdba74;
}

.settings-pricing-badge--custom {
  color: #6b21a8;
  background: #f3e8ff;
  border-color: #e9d5ff;
}
</style>
