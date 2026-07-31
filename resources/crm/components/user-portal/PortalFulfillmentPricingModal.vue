<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import api from "../../services/api";
import PortalOnboardingModalShell from "./PortalOnboardingModalShell.vue";
import PricingFeeList from "../settings/PricingFeeList.vue";
import PricingFeeInfoModal from "../settings/PricingFeeInfoModal.vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast";
import { openApiPdfBlob } from "../../utils/openApiPdfBlob.js";
import { normalizeAccountFeeItems } from "../../utils/accountFees.js";
import {
  CLIENT_VISIBLE_PRICING_CATEGORY_OPTIONS,
  feeMatchesCategory,
  feeMatchesSearch,
  PORTAL_PRICING_CATEGORY_ORDER,
} from "../../utils/pricingFeeUi.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  pricing: { type: Object, default: null },
  /** When set, downloads from admin onboarding PDF route */
  clientAccountId: { type: [String, Number], default: null },
  adminMode: { type: Boolean, default: false },
  pageMode: { type: Boolean, default: false },
  taskVerified: { type: Boolean, default: false },
  verifying: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "accepted", "verify", "unverify", "open-fees"]);

const toast = useToast();
const busy = ref(false);
const downloading = ref(false);
const agreed = ref(false);

const search = ref("");
const categoryFilter = ref("all");
const filterMenuOpen = ref(false);
const infoOpen = ref(false);
const selectedFee = ref(null);

const approved = computed(() => !!props.pricing?.approved);
const accepted = computed(() => props.pricing?.status === "completed" || !!props.pricing?.accepted_at);
const canMarkComplete = computed(() => props.adminMode && approved.value && !accepted.value);
const canVerifyForClient = computed(
  () => props.adminMode && approved.value && !props.taskVerified,
);
const allFeeItems = computed(() => {
  if (!approved.value) return [];
  return normalizeAccountFeeItems({ fees: { items: props.pricing?.fees || [] } });
});

const filteredFeeItems = computed(() =>
  allFeeItems.value.filter(
    (fee) => feeMatchesSearch(fee, search.value) && feeMatchesCategory(fee, categoryFilter.value),
  ),
);

const downloadPath = computed(() => {
  if (props.adminMode && props.clientAccountId) {
    return `/client-accounts/${props.clientAccountId}/onboarding/fulfillment-pricing.pdf`;
  }
  return "/portal/onboarding/fulfillment-pricing.pdf";
});

const showAgree = computed(
  () => !props.adminMode && approved.value && !accepted.value,
);

const showSearchFilter = computed(() => props.pageMode && approved.value);
const showClickHint = computed(() => approved.value && !props.adminMode);

watch(
  () => props.open,
  (isOpen) => {
    if (!props.pageMode && !isOpen) {
      agreed.value = false;
      infoOpen.value = false;
      selectedFee.value = null;
    }
  },
);

function close() {
  if (!busy.value && !props.verifying && !props.pageMode) emit("update:open", false);
}

function openFeesTab() {
  if (!props.adminMode || busy.value || props.verifying) return;
  emit("open-fees");
  if (!props.pageMode) emit("update:open", false);
}

function resetFilters() {
  categoryFilter.value = "all";
  filterMenuOpen.value = false;
}

function onSelectFee(fee) {
  if (props.adminMode) return;
  selectedFee.value = fee;
  infoOpen.value = true;
}

function onDocClick(e) {
  const t = e.target;
  if (!(t instanceof Element)) return;
  if (t.closest("[data-toolbar-filter]")) return;
  filterMenuOpen.value = false;
}

onMounted(() => {
  document.addEventListener("click", onDocClick);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});

async function downloadPdf() {
  if (downloading.value) return;
  downloading.value = true;
  try {
    await openApiPdfBlob(api, downloadPath.value);
  } catch (e) {
    toast.errorFrom(e, "Could not download pricing PDF.");
  } finally {
    downloading.value = false;
  }
}

async function onAgree() {
  if (!showAgree.value || !agreed.value || busy.value) return;
  busy.value = true;
  try {
    const { data } = await api.post("/portal/onboarding/fulfillment-pricing/accept");
    emit("accepted", data);
    toast.success("Fulfillment Pricing accepted.");
    if (!props.pageMode) emit("update:open", false);
  } catch (e) {
    toast.errorFrom(e, "Could not accept pricing.");
  } finally {
    busy.value = false;
  }
}

async function onMarkComplete() {
  if (!canMarkComplete.value || busy.value || props.verifying) return;
  if (!props.clientAccountId) {
    toast.error("Missing client account.");
    return;
  }
  busy.value = true;
  try {
    const { data } = await api.post(
      `/client-accounts/${props.clientAccountId}/onboarding/fulfillment-pricing/accept`,
    );
    emit("accepted", data);
    toast.success("Fulfillment Pricing marked complete.");
  } catch (e) {
    toast.errorFrom(e, "Could not mark pricing complete.");
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <component
    :is="pageMode ? 'div' : PortalOnboardingModalShell"
    v-bind="pageMode ? { class: 'portal-pricing-page-panel' } : { open, lg: true, scrollable: true }"
    @update:open="close"
  >
    <header
      class="crm-vx-modal__head d-flex align-items-start justify-content-between gap-3"
      :class="{ 'portal-pricing-page-panel__head': pageMode }"
    >
      <div class="min-w-0">
        <h2 v-if="!pageMode" class="crm-vx-modal__title mb-0">Fulfillment Pricing</h2>
        <p v-if="adminMode && taskVerified && accepted" class="text-secondary small mb-0 mt-1">
          Fulfillment pricing is completed and verified.
        </p>
        <p v-else-if="adminMode && taskVerified" class="text-secondary small mb-0 mt-1">
          Pricing is verified. Mark Complete separately if the client has accepted the schedule.
        </p>
        <p v-else-if="adminMode && accepted" class="text-secondary small mb-0 mt-1">
          Pricing is completed. Verify separately when you have confirmed the schedule.
        </p>
        <p v-else-if="adminMode && approved" class="text-secondary small mb-0 mt-1">
          Fees are Approved. Mark Complete and Verify are separate actions.
        </p>
        <p v-else-if="adminMode" class="text-secondary small mb-0 mt-1">
          Approve fulfillment pricing on the Fees tab first (that verifies this task; complete separately).
        </p>
        <p v-else-if="pageMode && accepted" class="text-secondary small mb-0">
          You have accepted this account's fulfillment pricing schedule.
        </p>
        <p v-else-if="pageMode && approved" class="text-secondary small mb-0">
          Review your quoted fulfillment rates below. Download a PDF copy or accept the schedule.
        </p>
        <p v-else-if="pageMode" class="text-secondary small mb-0">
          Your pricing is currently being adjusted to match your approved quote. Once the update is
          complete, it will be available for your review. Please return to this section to complete
          your onboarding.
        </p>
      </div>
      <button
        type="button"
        class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1 flex-shrink-0"
        :disabled="downloading || busy || verifying"
        aria-label="Download"
        @click="downloadPdf"
      >
        <CrmLoadingSpinner v-if="downloading" small />
        <svg
          v-else
          width="18"
          height="18"
          viewBox="0 0 24 24"
          fill="currentColor"
          aria-hidden="true"
        >
          <path d="M12 16l-5-5h3V4h4v7h3l-5 5zm-7 2h14v2H5v-2z" />
        </svg>
        <span>Download</span>
      </button>
    </header>

    <div
      class="crm-vx-modal__body portal-onboard-modal__body"
      :class="{ 'portal-pricing-page-panel__body': pageMode }"
    >
      <div
        v-if="!approved"
        class="portal-fulfillment-pricing-modal__empty text-center text-secondary py-5 px-3"
      >
        <template v-if="adminMode">
          <p class="mb-2 fw-semibold text-body">Fulfillment Pricing Is Not Approved Yet</p>
          <p class="mb-0 mx-auto" style="max-width: 28rem">
            Review and Approve the account fee schedule on the Fees tab. Approving verifies
            Fulfillment Pricing on Onboarding; completion stays separate (client accept or Mark
            Complete).
          </p>
          <button
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--primary mt-4"
            :disabled="busy || verifying"
            @click="openFeesTab"
          >
            Open Fees Tab
          </button>
        </template>
        <p v-else class="mb-0 mx-auto" style="max-width: 32rem">
          Your pricing is currently being adjusted to match your approved quote. Once the update is
          complete, it will be available for your review. Please return to this section to complete
          your onboarding.
        </p>
      </div>

      <template v-else>
        <p
          v-if="showClickHint"
          class="portal-fulfillment-pricing-modal__hint text-center mb-3"
        >
          Click on price for more info about the charges
        </p>

        <div v-if="showSearchFilter" class="staff-table-toolbar mb-3 px-0">
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
                  <label class="form-label" for="portal-pricing-filter-category">Category</label>
                  <select
                    id="portal-pricing-filter-category"
                    v-model="categoryFilter"
                    class="form-select"
                  >
                    <option
                      v-for="opt in CLIENT_VISIBLE_PRICING_CATEGORY_OPTIONS"
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

        <div
          v-if="!filteredFeeItems.length"
          class="text-center text-secondary py-5 px-3"
        >
          <p class="mb-0">
            {{ allFeeItems.length ? "No fees match your filters." : "No fees are configured yet." }}
          </p>
        </div>
        <PricingFeeList
          v-else
          variant="schedule"
          :fees="filteredFeeItems"
          :clickable="!adminMode"
          :category-order="PORTAL_PRICING_CATEGORY_ORDER"
          @select="onSelectFee"
        />
      </template>
    </div>

    <footer
      class="crm-vx-modal__footer flex-wrap gap-2"
      :class="{ 'portal-pricing-page-panel__footer': pageMode }"
    >
      <div
        v-if="adminMode && (taskVerified || canVerifyForClient || canMarkComplete)"
        class="d-flex flex-wrap gap-2 me-auto"
      >
        <button
          v-if="taskVerified"
          type="button"
          class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
          :disabled="busy || verifying"
          @click="emit('unverify')"
        >
          <CrmLoadingSpinner v-if="verifying" small class="me-1" />
          Remove Verification
        </button>
        <button
          v-else-if="canVerifyForClient"
          type="button"
          class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
          :disabled="busy || verifying"
          title="Verify only — does not mark pricing complete"
          @click="emit('verify')"
        >
          <CrmLoadingSpinner v-if="verifying" small class="me-1" />
          Verify
        </button>
        <button
          v-if="canMarkComplete"
          type="button"
          class="crm-vx-modal-btn crm-vx-modal-btn--primary"
          :disabled="busy || verifying"
          title="Mark Complete only — does not verify"
          @click="onMarkComplete"
        >
          <CrmLoadingSpinner v-if="busy" small class="me-1" />
          Mark Complete
        </button>
      </div>

      <template v-if="showAgree">
        <label class="portal-fulfillment-pricing-modal__agree form-check d-flex align-items-start gap-2 me-auto mb-0">
          <input
            v-model="agreed"
            class="form-check-input mt-1 flex-shrink-0"
            type="checkbox"
            :disabled="busy"
          />
          <span class="form-check-label small text-secondary">
            By checking this box and clicking "I Agree," I acknowledge that I have reviewed and
            accepted Save Rack's current pricing schedule and fee structure. I understand that
            pricing may be updated from time to time in accordance with the Fulfillment Services
            Agreement.
          </span>
        </label>
        <button
          type="button"
          class="crm-vx-modal-btn crm-vx-modal-btn--primary"
          :disabled="!agreed || busy"
          @click="onAgree"
        >
          <CrmLoadingSpinner v-if="busy" small class="me-1" />
          I Agree
        </button>
      </template>

      <button
        v-if="!pageMode"
        type="button"
        class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
        :disabled="busy || verifying"
        @click="close"
      >
        Close
      </button>
    </footer>

    <PricingFeeInfoModal v-model:open="infoOpen" :fee="selectedFee" />
  </component>
</template>

<style scoped>
.portal-fulfillment-pricing-modal__agree {
  max-width: min(36rem, 100%);
  flex: 1 1 16rem;
}

.portal-fulfillment-pricing-modal__empty {
  font-size: 0.95rem;
}

.portal-fulfillment-pricing-modal__hint {
  color: #dc2626;
  font-size: 0.875rem;
  font-weight: 500;
}

.portal-pricing-page-panel__head,
.portal-pricing-page-panel__body,
.portal-pricing-page-panel__footer {
  padding-left: 1.25rem;
  padding-right: 1.25rem;
}

.portal-pricing-page-panel__head {
  padding-top: 1.25rem;
  padding-bottom: 0.75rem;
}

.portal-pricing-page-panel__body {
  padding-top: 0.5rem;
  padding-bottom: 1rem;
}

.portal-pricing-page-panel__footer {
  padding-top: 0.75rem;
  padding-bottom: 1.25rem;
  border-top: 1px solid var(--bs-border-color, #e5e7eb);
}
</style>
