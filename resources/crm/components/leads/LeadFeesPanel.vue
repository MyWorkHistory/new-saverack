<script setup>
import { computed, inject, onMounted, onUnmounted, ref, watch } from "vue";
import api from "../../services/api";
import AccountFeeAmountModal from "../clients/AccountFeeAmountModal.vue";
import PricingFeeList from "../settings/PricingFeeList.vue";
import { useToast } from "../../composables/useToast.js";
import { normalizeAccountFeeItems } from "../../utils/accountFees.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import {
  PRICING_CATEGORY_OPTIONS,
  feeMatchesCategory,
  feeMatchesSearch,
} from "../../utils/pricingFeeUi.js";

const props = defineProps({
  leadId: { type: [String, Number], required: true },
  lead: { type: Object, default: null },
});

const emit = defineEmits(["fees-updated"]);

const crmUser = inject("crmUser", ref(null));
const toast = useToast();

const canUpdate = computed(() => {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes("leads.update");
});

const search = ref("");
const categoryFilter = ref("all");
const filterMenuOpen = ref(false);

const allFees = computed(() => normalizeAccountFeeItems({ fees: props.lead?.fees || {} }));

const filteredFees = computed(() =>
  allFees.value.filter(
    (fee) => feeMatchesSearch(fee, search.value) && feeMatchesCategory(fee, categoryFilter.value),
  ),
);

const amountModalOpen = ref(false);
const amountModalFee = ref(null);
const amountSaving = ref(false);

function resetFilters() {
  categoryFilter.value = "all";
  filterMenuOpen.value = false;
}

function onDocClick(event) {
  if (!event.target.closest("[data-toolbar-filter]")) {
    filterMenuOpen.value = false;
  }
}

function openFee(fee) {
  if (!canUpdate.value) return;
  amountModalFee.value = fee;
  amountModalOpen.value = true;
}

function closeModal() {
  if (amountSaving.value) return;
  amountModalOpen.value = false;
  amountModalFee.value = null;
}

async function saveFeeAmount(payload) {
  const fee = amountModalFee.value;
  if (!fee?.id || amountSaving.value) return;
  amountSaving.value = true;
  try {
    const { data } = await api.patch(`/leads/${props.leadId}/fees/${fee.id}`, {
      amount: payload.amount,
      cost: payload.cost,
    });
    amountModalOpen.value = false;
    amountModalFee.value = null;
    emit("fees-updated", data);
    toast.success("Fee updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update fee.");
  } finally {
    amountSaving.value = false;
  }
}

watch(
  () => props.leadId,
  () => {
    amountModalOpen.value = false;
    amountModalFee.value = null;
    search.value = "";
    categoryFilter.value = "all";
    filterMenuOpen.value = false;
  },
);

onMounted(() => {
  document.addEventListener("click", onDocClick);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="crm-lead-fees">
    <header class="mb-4">
      <h2 class="h5 mb-1 fw-semibold text-body">Lead Fees</h2>
      <p class="small text-secondary mb-0">
        Pricing for this lead. Click a fee to view the description and set a lead-specific price.
      </p>
    </header>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row">
          <input
            v-model="search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search fee name or description"
            aria-label="Search lead fees"
            autocomplete="off"
          />
          <div class="position-relative flex-shrink-0" data-toolbar-filter>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen"
              aria-haspopup="true"
              aria-controls="lead-fees-filter-panel"
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
              id="lead-fees-filter-panel"
              class="dropdown-menu dropdown-menu-end show shadow border p-0 staff-toolbar-filter-dropdown"
              role="dialog"
              aria-label="Fee filters"
              @click.stop
            >
              <div class="staff-toolbar-filter-dropdown__head">
                <span>Filters</span>
                <button
                  type="button"
                  class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
                  @click="resetFilters"
                >
                  Reset
                </button>
              </div>
              <div class="staff-toolbar-filter-dropdown__body">
                <label class="form-label" for="lead-fees-filter-category">Category</label>
                <select id="lead-fees-filter-category" v-model="categoryFilter" class="form-select">
                  <option
                    v-for="opt in PRICING_CATEGORY_OPTIONS"
                    :key="opt.value"
                    :value="opt.value"
                  >
                    {{ opt.label }}
                  </option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="!allFees.length" class="text-center text-secondary py-5 px-3">
        <p class="mb-0">No fee schedule loaded for this lead.</p>
      </div>

      <div v-else-if="!filteredFees.length" class="text-center text-secondary py-5 px-3">
        <p class="mb-0">No fees match your filters.</p>
      </div>

      <div v-else class="staff-table-wrap">
        <div class="p-3 p-md-4">
          <PricingFeeList
            variant="schedule"
            :fees="filteredFees"
            :clickable="canUpdate"
            @select="openFee"
          />
        </div>
      </div>
    </div>

    <AccountFeeAmountModal
      :open="amountModalOpen"
      :fee="amountModalFee"
      :saving="amountSaving"
      :show-remove="false"
      @close="closeModal"
      @save="saveFeeAmount"
    />
  </div>
</template>
