<script setup>
import { computed, ref, watch } from "vue";
import api from "../../services/api";
import { useToast } from "../../composables/useToast";
import { normalizeAccountFees } from "../../utils/accountFees.js";
import { CRM_BTN_PRIMARY, CRM_BTN_SECONDARY } from "../../constants/dialogFooter.js";

const props = defineProps({
  account: { type: Object, default: null },
  accountId: { type: String, required: true },
  canEdit: { type: Boolean, default: false },
});

const emit = defineEmits(["fees-updated"]);

const toast = useToast();

const editing = ref(false);
const saving = ref(false);
const deletingId = ref(null);
const DEFAULT_STORAGE_FEE_TYPES = [
  "Bin (Large)",
  "Bin (Medium)",
  "Bin (Small)",
  "Bin (X-Large)",
  "Custom",
  "Pallet (Large)",
  "Pallet (Medium)",
  "Pallet (Small)",
  "Pallet (X-Large)",
  "Shelf (Large)",
  "Shelf (Medium)",
  "Shelf (Small)",
  "Shelf (X-Large)",
  "Sleeve",
];

/** @type {import('vue').Ref<null | { firstPick: string, additionalPicks: string, processing: string, additionalItems: string, storage: { id: number | null, label: string, amount: string, currency: string }[] }>} */
const draft = ref(null);

const data = computed(() => normalizeAccountFees(props.account));
const storageRowsForDisplay = computed(() =>
  data.value.storageRows.length
    ? data.value.storageRows
    : DEFAULT_STORAGE_FEE_TYPES.map((label) => ({ id: null, label, value: "$0.00" })),
);

function showCell(v) {
  return v ?? "—";
}

function amountToInput(v) {
  if (v == null || v === "") return "";
  const n = Number(v);
  if (!Number.isFinite(n)) return "";
  return String(n);
}

function numOrNull(s) {
  if (s === "" || s == null) return null;
  const n = Number(s);
  return Number.isFinite(n) ? n : null;
}

function initDraft() {
  const f = props.account?.fees ?? {};
  const ful = f.fulfillment ?? {};
  const ret = f.returns ?? {};
  const storageRows = Array.isArray(f.storage) ? f.storage : [];
  draft.value = {
    firstPick: amountToInput(ful.first_pick_fee),
    additionalPicks: amountToInput(ful.additional_picks_fee),
    processing: amountToInput(ret.processing_fee),
    additionalItems: amountToInput(ret.additional_items_fee),
    storage: storageRows.length
      ? storageRows.map((row) => ({
          id: row.id != null ? Number(row.id) : null,
          label: row.label != null ? String(row.label) : "",
          amount: amountToInput(row.amount),
          currency: row.currency != null && String(row.currency).length === 3 ? String(row.currency) : "USD",
        }))
      : DEFAULT_STORAGE_FEE_TYPES.map((label) => ({
          id: null,
          label,
          amount: "0",
          currency: "USD",
        })),
  };
}

function startEdit() {
  initDraft();
  editing.value = true;
}

function cancelEdit() {
  editing.value = false;
  draft.value = null;
}

watch(
  () => props.account,
  () => {
    if (!editing.value) {
      draft.value = null;
    }
  },
  { deep: true },
);

async function saveFees() {
  if (!draft.value) return;
  saving.value = true;
  try {
    const storagePayload = draft.value.storage
      .filter((r) => {
        const hasLabel = r.label && String(r.label).trim() !== "";
        const hasAmt = numOrNull(r.amount) != null;
        return hasLabel || hasAmt || (r.id != null && r.id > 0);
      })
      .map((r) => {
        const out = {
          label: r.label && String(r.label).trim() !== "" ? String(r.label).trim() : "Storage fee",
          amount: numOrNull(r.amount),
          currency: r.currency && String(r.currency).length === 3 ? String(r.currency).toUpperCase() : "USD",
        };
        if (r.id != null && r.id > 0) {
          out.id = r.id;
        }
        return out;
      });

    const body = {
      fulfillment: {
        first_pick_fee: numOrNull(draft.value.firstPick),
        additional_picks_fee: numOrNull(draft.value.additionalPicks),
      },
      returns: {
        processing_fee: numOrNull(draft.value.processing),
        additional_items_fee: numOrNull(draft.value.additionalItems),
      },
      storage: storagePayload,
    };

    const { data } = await api.put(`/client-accounts/${props.accountId}/fees`, body);
    emit("fees-updated", data);
    editing.value = false;
    draft.value = null;
    toast.success("Fees saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save fees.");
  } finally {
    saving.value = false;
  }
}

function addStorageRow() {
  if (!draft.value) initDraft();
  if (!draft.value) return;
  draft.value.storage.push({
    id: null,
    label: "Storage",
    amount: "0",
    currency: "USD",
  });
}

async function removeStorageRow(row, idx) {
  if (row.id != null && row.id > 0) {
    deletingId.value = row.id;
    try {
      const { data } = await api.delete(`/client-accounts/${props.accountId}/fees/${row.id}`);
      emit("fees-updated", data);
      if (draft.value) {
        draft.value.storage.splice(idx, 1);
      }
      toast.success("Storage fee removed.");
    } catch (e) {
      toast.errorFrom(e, "Could not remove storage fee.");
    } finally {
      deletingId.value = null;
    }
    return;
  }
  if (draft.value) {
    draft.value.storage.splice(idx, 1);
  }
}
</script>

<template>
  <div class="crm-account-fees">
    <div class="d-flex flex-column flex-sm-row align-items-start justify-content-between gap-3 mb-4">
      <header class="crm-account-fees__page-head">
        <h2 class="crm-account-fees__page-title h5 mb-1 fw-semibold text-body">
          Account Fees
        </h2>
        <p class="crm-account-fees__page-sub small text-secondary mb-0">
          Fulfillment fees, returns fees, and storage pricing for this account.
        </p>
      </header>
      <div v-if="canEdit" class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0">
        <template v-if="!editing">
          <button
            type="button"
            class="btn btn-primary staff-page-primary btn-sm"
            @click="startEdit"
          >
            Edit Fees
          </button>
        </template>
        <template v-else>
          <button
            type="button"
            :class="CRM_BTN_SECONDARY"
            class="btn-sm"
            :disabled="saving"
            @click="cancelEdit"
          >
            Cancel
          </button>
          <button
            type="button"
            :class="CRM_BTN_PRIMARY"
            class="btn-sm"
            :disabled="saving || !draft"
            @click="saveFees"
          >
            {{ saving ? "Saving…" : "Save Fees" }}
          </button>
        </template>
      </div>
    </div>

    <!-- Fulfillment Fee -->
    <section class="crm-account-fees__section mb-4" aria-labelledby="crm-fees-fulfillment">
      <div class="crm-account-fees__section-card">
        <div class="crm-account-fees__section-head">
          <span class="crm-account-fees__icon crm-account-fees__icon--primary" aria-hidden="true">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"
              />
            </svg>
          </span>
          <h3 id="crm-fees-fulfillment" class="crm-account-fees__section-title mb-0">
            Fulfillment Fee
          </h3>
        </div>

        <div class="row g-3 mt-1">
          <div class="col-12 col-md-6">
            <div class="crm-account-fees__metric crm-account-fees__metric--tall staff-surface h-100">
              <div class="crm-account-fees__metric-body">
                <p class="crm-account-fees__metric-label mb-0">Fulfillment</p>
                <p class="crm-account-fees__metric-sub small text-secondary mb-0">
                  1st item picked in the order
                </p>
              </div>
              <template v-if="editing && draft">
                <input
                  v-model="draft.firstPick"
                  type="number"
                  step="0.0001"
                  class="form-control form-control-sm crm-account-fees__amount-input text-end"
                  placeholder="Amount"
                  aria-label="Fulfillment 1st item picked in the order amount"
                />
              </template>
              <p v-else class="crm-account-fees__metric-value mb-0">
                {{ showCell(data.fulfillment.firstPick) }}
              </p>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <div class="crm-account-fees__metric crm-account-fees__metric--tall staff-surface h-100">
              <div class="crm-account-fees__metric-body">
                <p class="crm-account-fees__metric-label mb-0">Additional Picks</p>
                <p class="crm-account-fees__metric-sub small text-secondary mb-0">
                  Additional items picked in the same order
                </p>
              </div>
              <template v-if="editing && draft">
                <input
                  v-model="draft.additionalPicks"
                  type="number"
                  step="0.0001"
                  class="form-control form-control-sm crm-account-fees__amount-input text-end"
                  placeholder="Amount"
                  aria-label="Additional items picked in the same order amount"
                />
              </template>
              <p v-else class="crm-account-fees__metric-value mb-0">
                {{ showCell(data.fulfillment.additionalPicks) }}
              </p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Returns Fee -->
    <section class="crm-account-fees__section mb-4" aria-labelledby="crm-fees-returns">
      <div class="crm-account-fees__section-card">
        <div class="crm-account-fees__section-head">
          <span class="crm-account-fees__icon crm-account-fees__icon--amber" aria-hidden="true">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M3 10h10a4 4 0 014 4v2M3 10l4-4m-4 4l4 4"
              />
            </svg>
          </span>
          <h3 id="crm-fees-returns" class="crm-account-fees__section-title mb-0">Returns Fee</h3>
        </div>

        <div class="row g-3 mt-1">
          <div class="col-12 col-md-6">
            <div class="crm-account-fees__metric crm-account-fees__metric--tall staff-surface h-100">
              <div class="crm-account-fees__metric-body">
                <p class="crm-account-fees__metric-label mb-0">Returns</p>
                <p class="crm-account-fees__metric-sub small text-secondary mb-0">
                  1st item returned
                </p>
              </div>
              <template v-if="editing && draft">
                <input
                  v-model="draft.processing"
                  type="number"
                  step="0.0001"
                  class="form-control form-control-sm crm-account-fees__amount-input text-end"
                  placeholder="Amount"
                  aria-label="Returns 1st item returned amount"
                />
              </template>
              <p v-else class="crm-account-fees__metric-value mb-0">
                {{ showCell(data.returns.processing) }}
              </p>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <div class="crm-account-fees__metric crm-account-fees__metric--tall staff-surface h-100">
              <div class="crm-account-fees__metric-body">
                <p class="crm-account-fees__metric-label mb-0">Additional Items</p>
                <p class="crm-account-fees__metric-sub small text-secondary mb-0">
                  Additional items returned
                </p>
              </div>
              <template v-if="editing && draft">
                <input
                  v-model="draft.additionalItems"
                  type="number"
                  step="0.0001"
                  class="form-control form-control-sm crm-account-fees__amount-input text-end"
                  placeholder="Amount"
                  aria-label="Additional items returned amount"
                />
              </template>
              <p v-else class="crm-account-fees__metric-value mb-0">
                {{ showCell(data.returns.additionalItems) }}
              </p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Storage -->
    <section class="crm-account-fees__section" aria-labelledby="crm-fees-storage">
      <div class="crm-account-fees__section-card">
        <div
          class="crm-account-fees__section-head flex-wrap"
          :class="editing ? 'justify-content-between' : ''"
        >
          <div class="d-flex align-items-center gap-3">
            <span class="crm-account-fees__icon crm-account-fees__icon--teal" aria-hidden="true">
              <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"
                />
              </svg>
            </span>
            <h3 id="crm-fees-storage" class="crm-account-fees__section-title mb-0">Storage Fees</h3>
          </div>
          <button
            v-if="editing && draft && canEdit"
            type="button"
            class="btn btn-outline-secondary btn-sm mt-2 mt-sm-0"
            @click="addStorageRow"
          >
            Add Storage Line
          </button>
        </div>

        <ul
          v-if="!editing && storageRowsForDisplay.length"
          class="list-unstyled mb-0 mt-3 row g-3"
        >
          <li v-for="(row, idx) in storageRowsForDisplay" :key="'st-' + (row.id ?? idx)" class="col-12">
            <div class="crm-account-fees__metric staff-surface">
              <div class="crm-account-fees__metric-body">
                <p class="crm-account-fees__metric-label mb-0">{{ row.label }}</p>
              </div>
              <p class="crm-account-fees__metric-value mb-0">
                {{ showCell(row.value) }}
              </p>
            </div>
          </li>
        </ul>

        <ul
          v-else-if="editing && draft && draft.storage.length"
          class="list-unstyled mb-0 mt-3 row g-3"
        >
          <li v-for="(row, idx) in draft.storage" :key="row.id ?? 'new-' + idx" class="col-12">
            <div class="crm-account-fees__metric staff-surface align-items-start flex-column flex-md-row">
              <div class="crm-account-fees__metric-body flex-grow-1 w-100 min-w-0">
                <label class="form-label small text-secondary mb-1">Label</label>
                <input v-model="row.label" type="text" class="form-control form-control-sm mb-2" />
                <label class="form-label small text-secondary mb-1">Amount (USD)</label>
                <input
                  v-model="row.amount"
                  type="number"
                  step="0.0001"
                  class="form-control form-control-sm"
                />
              </div>
              <button
                type="button"
                class="btn btn-outline-danger btn-sm align-self-stretch align-self-md-center mt-2 mt-md-0 text-nowrap flex-shrink-0"
                :disabled="deletingId === row.id"
                @click="removeStorageRow(row, idx)"
              >
                {{ deletingId === row.id ? "Removing…" : "Remove" }}
              </button>
            </div>
          </li>
        </ul>

        <div v-else-if="!editing" class="mt-3">
          <div class="crm-account-fees__metric staff-surface">
            <div class="crm-account-fees__metric-body">
              <p class="crm-account-fees__metric-label mb-0 text-secondary small">
                No storage fees on file
              </p>
              <p class="crm-account-fees__metric-sub small text-secondary mb-0">
                Use Edit Fees to add one or more storage lines.
              </p>
            </div>
            <p class="crm-account-fees__metric-value mb-0">—</p>
          </div>
        </div>

        <div v-else-if="editing && draft && !draft.storage.length" class="mt-3">
          <p class="small text-secondary mb-2">
            No storage lines yet. Add a line or save to keep this section empty.
          </p>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.crm-account-fees__page-title {
  color: var(--bs-heading-color, #3d3c4a);
}

.crm-account-fees__section-card {
  padding: 1.25rem 1.25rem 1.35rem;
}

.crm-account-fees__section-head {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.crm-account-fees__section-title {
  font-size: 1.0625rem;
  font-weight: 600;
  color: var(--bs-body-color);
}

.crm-account-fees__icon {
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 0.5rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.crm-account-fees__icon--primary {
  background: rgba(37, 99, 235, 0.12);
  color: #2563eb;
}

.crm-account-fees__icon--amber {
  background: rgba(245, 158, 11, 0.14);
  color: #d97706;
}

.crm-account-fees__icon--teal {
  background: rgba(13, 148, 136, 0.14);
  color: #0d9488;
}

.crm-account-fees__metric {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.125rem;
}

.crm-account-fees__metric--tall {
  min-height: 6.5rem;
  align-items: center;
}

.crm-account-fees__metric-body {
  min-width: 0;
}

.crm-account-fees__metric-label {
  font-size: 0.9375rem;
  font-weight: 600;
  color: var(--bs-body-color);
}

.crm-account-fees__metric-sub {
  margin-top: 0.25rem;
  line-height: 1.35;
}

.crm-account-fees__metric-value {
  font-size: 1.125rem;
  font-weight: 700;
  color: var(--bs-emphasis-color);
  white-space: nowrap;
}

.crm-account-fees__amount-input {
  max-width: 10rem;
  min-width: 6rem;
}

@media (max-width: 575.98px) {
  .crm-account-fees__metric {
    flex-direction: column;
    align-items: stretch;
  }
  .crm-account-fees__metric-value {
    white-space: normal;
  }
  .crm-account-fees__amount-input {
    max-width: 100%;
  }
}

[data-bs-theme="dark"] .crm-account-fees__icon--primary {
  background: rgba(96, 165, 250, 0.15);
  color: #93c5fd;
}

[data-bs-theme="dark"] .crm-account-fees__icon--amber {
  background: rgba(251, 191, 36, 0.12);
  color: #fcd34d;
}

[data-bs-theme="dark"] .crm-account-fees__icon--teal {
  background: rgba(45, 212, 191, 0.12);
  color: #5eead4;
}
</style>
