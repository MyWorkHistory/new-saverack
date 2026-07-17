<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import api from "../../services/api";
import AccountFeeAmountModal from "./AccountFeeAmountModal.vue";
import PricingFeeList from "../settings/PricingFeeList.vue";
import ConfirmModal from "../common/ConfirmModal.vue";
import { useToast } from "../../composables/useToast";
import { normalizeAccountFeeItems } from "../../utils/accountFees.js";
import {
  PRICING_CATEGORY_OPTIONS,
  feeMatchesCategory,
  feeMatchesSearch,
} from "../../utils/pricingFeeUi.js";

const props = defineProps({
  account: { type: Object, default: null },
  accountId: { type: String, required: true },
  canEdit: { type: Boolean, default: false },
});

const emit = defineEmits(["fees-updated"]);

const toast = useToast();

const search = ref("");
const categoryFilter = ref("all");
const filterMenuOpen = ref(false);
const editingFee = ref(null);
const modalOpen = ref(false);
const saving = ref(false);
const statusMenuOpen = ref(false);
const statusConfirmOpen = ref(false);
const statusSaving = ref(false);
const pendingStatus = ref(null);

const CATEGORY_OPTIONS = PRICING_CATEGORY_OPTIONS;

const pricingStatus = computed(() => {
  const raw = String(props.account?.fulfillment_pricing_status || "pending").toLowerCase();
  return raw === "approved" ? "approved" : "pending";
});

const pricingStatusLabel = computed(() =>
  pricingStatus.value === "approved" ? "Approved" : "Pending",
);

const pricingStatusClass = computed(() =>
  pricingStatus.value === "approved"
    ? "badge rounded-pill fw-medium bg-success-subtle text-success"
    : "badge rounded-pill fw-medium bg-warning-subtle text-warning-emphasis",
);

const statusConfirmMessage = computed(() =>
  pendingStatus.value === "approved"
    ? "Mark fulfillment pricing as Approved? Clients will see this account's fee schedule in onboarding."
    : "Set fulfillment pricing back to Pending? Client acceptance will be cleared.",
);

const allFees = computed(() => normalizeAccountFeeItems(props.account));

const filteredFees = computed(() =>
  allFees.value.filter(
    (fee) => feeMatchesSearch(fee, search.value) && feeMatchesCategory(fee, categoryFilter.value),
  ),
);

function resetFilters() {
  categoryFilter.value = "all";
  filterMenuOpen.value = false;
}

function onDocClick(event) {
  if (!event.target.closest("[data-toolbar-filter]")) {
    filterMenuOpen.value = false;
  }
  if (!event.target.closest("[data-pricing-status]")) {
    statusMenuOpen.value = false;
  }
}

function openEdit(fee) {
  if (!props.canEdit) return;
  editingFee.value = { ...fee };
  modalOpen.value = true;
}

function closeModal() {
  if (!saving.value) {
    modalOpen.value = false;
    editingFee.value = null;
  }
}

function onStatusBadgeClick() {
  if (!props.canEdit || statusSaving.value) return;
  statusMenuOpen.value = !statusMenuOpen.value;
}

function requestStatusChange(next) {
  statusMenuOpen.value = false;
  if (!props.canEdit || next === pricingStatus.value) return;
  pendingStatus.value = next;
  statusConfirmOpen.value = true;
}

function closeStatusConfirm() {
  if (statusSaving.value) return;
  statusConfirmOpen.value = false;
  pendingStatus.value = null;
}

async function confirmStatusChange() {
  if (!pendingStatus.value || statusSaving.value) return;
  statusSaving.value = true;
  try {
    const { data } = await api.patch(
      `/client-accounts/${props.accountId}/fulfillment-pricing/status`,
      { status: pendingStatus.value },
    );
    emit("fees-updated", data);
    statusConfirmOpen.value = false;
    pendingStatus.value = null;
    toast.success(
      data?.fulfillment_pricing_status === "approved"
        ? "Fulfillment pricing marked Approved."
        : "Fulfillment pricing set to Pending.",
    );
  } catch (e) {
    toast.errorFrom(e, "Could not update pricing status.");
  } finally {
    statusSaving.value = false;
  }
}

async function onSave(payload) {
  if (!editingFee.value?.id) return;
  saving.value = true;
  try {
    const { data } = await api.patch(
      `/client-accounts/${props.accountId}/fees/${editingFee.value.id}`,
      { amount: payload.amount },
    );
    emit("fees-updated", data);
    modalOpen.value = false;
    editingFee.value = null;
    toast.success("Fee price saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save fee price.");
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  document.addEventListener("click", onDocClick);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="crm-account-fees">
    <header class="mb-4">
      <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <h2 class="h5 mb-0 fw-semibold text-body">Account Fees</h2>
        <div class="position-relative" data-pricing-status>
          <button
            type="button"
            class="border-0 p-0 bg-transparent"
            :disabled="!canEdit || statusSaving"
            :aria-expanded="statusMenuOpen"
            aria-haspopup="true"
            :title="canEdit ? 'Change fulfillment pricing status' : undefined"
            @click.stop="onStatusBadgeClick"
          >
            <span :class="pricingStatusClass">
              {{ pricingStatusLabel }}
            </span>
          </button>
          <div
            v-if="statusMenuOpen && canEdit"
            class="dropdown-menu dropdown-menu-start show shadow border py-1"
            role="menu"
            @click.stop
          >
            <button
              type="button"
              class="dropdown-item"
              role="menuitem"
              :disabled="pricingStatus === 'pending'"
              @click="requestStatusChange('pending')"
            >
              Pending
            </button>
            <button
              type="button"
              class="dropdown-item"
              role="menuitem"
              :disabled="pricingStatus === 'approved'"
              @click="requestStatusChange('approved')"
            >
              Approved
            </button>
          </div>
        </div>
      </div>
      <p class="small text-secondary mb-0">
        Pricing for this account. Click a fee to set an account-specific price.
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
            aria-label="Search account fees"
            autocomplete="off"
          />
          <div class="position-relative flex-shrink-0" data-toolbar-filter>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen"
              aria-haspopup="true"
              aria-controls="account-fees-filter-panel"
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
              id="account-fees-filter-panel"
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
                <label class="form-label" for="account-fees-filter-category">Category</label>
                <select id="account-fees-filter-category" v-model="categoryFilter" class="form-select">
                  <option v-for="opt in CATEGORY_OPTIONS" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                  </option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="!allFees.length" class="text-center text-secondary py-5 px-3">
        <p class="mb-0">No fees are configured for this account yet.</p>
      </div>

      <div v-else-if="!filteredFees.length" class="text-center text-secondary py-5 px-3">
        <p class="mb-0">No fees match your filters.</p>
      </div>

      <div v-else class="staff-table-wrap">
        <div class="p-3 p-md-4">
          <PricingFeeList
            :fees="filteredFees"
            :clickable="canEdit"
            @select="openEdit"
          />
        </div>
      </div>
    </div>

    <AccountFeeAmountModal
      :open="modalOpen"
      :fee="editingFee"
      :saving="saving"
      @close="closeModal"
      @save="onSave"
    />

    <ConfirmModal
      :open="statusConfirmOpen"
      title="Update Pricing Status"
      :message="statusConfirmMessage"
      confirm-label="Update Status"
      cancel-label="Cancel"
      :danger="false"
      :busy="statusSaving"
      @close="closeStatusConfirm"
      @confirm="confirmStatusChange"
    />
  </div>
</template>
