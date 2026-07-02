<script setup>
import { computed, inject, onMounted, onUnmounted, ref, watch } from "vue";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import PricingFeeList from "../../components/settings/PricingFeeList.vue";
import PricingFeeModal from "../../components/settings/PricingFeeModal.vue";
import { useToast } from "../../composables/useToast.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { PRICING_CATEGORY_OPTIONS } from "../../utils/pricingFeeUi.js";

const CATEGORY_OPTIONS = PRICING_CATEGORY_OPTIONS;

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
  if (!fee) return;
  modalOpen.value = false;
  editingFee.value = null;
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
          <PricingFeeList :fees="fees" :clickable="canUpdate" @select="openEdit" />
        </div>
      </div>
    </div>

    <PricingFeeModal
      :open="modalOpen"
      :fee="editingFee"
      :saving="saving"
      :can-delete="canUpdate"
      @close="closeModal"
      @save="onSave"
      @delete="confirmDelete(editingFee)"
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
