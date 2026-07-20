<script setup>
import { computed, onMounted, ref } from "vue";
import { RouterLink } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";
import { formatCents } from "../../utils/formatMoney.js";

const toast = useToast();
const loading = ref(true);
const generating = ref(false);
const errorMsg = ref("");
const weekStartInput = ref("");
const defaultWeekStart = ref("");
const current = ref(null);
const previous = ref(null);
const comparison = ref(null);
const confirmGenerateOpen = ref(false);

const BREAKDOWN = [
  { key: "fulfillment_cents", label: "Fulfillment", hint: "Picks + additional picks" },
  { key: "postage_cents", label: "Postage", hint: "Carrier & shipping labels" },
  { key: "materials_cents", label: "Materials", hint: "Packaging materials" },
  { key: "returns_cents", label: "Returns", hint: "Return processing" },
  { key: "custom_work_cents", label: "Custom Work", hint: "Ad-hoc / custom lines" },
  { key: "wholesale_cents", label: "Wholesale", hint: "Wholesale charges" },
];

function mondayIso(date = new Date()) {
  const d = new Date(date);
  const day = d.getDay();
  const diff = day === 0 ? -6 : 1 - day;
  d.setDate(d.getDate() + diff);
  d.setHours(0, 0, 0, 0);
  return toIsoDate(d);
}

function toIsoDate(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

function formatWeekRange(start, end) {
  if (!start || !end) return "—";
  const opts = { month: "short", day: "numeric", year: "numeric" };
  const a = new Date(`${start}T12:00:00`);
  const b = new Date(`${end}T12:00:00`);
  return `${a.toLocaleDateString(undefined, opts)} – ${b.toLocaleDateString(undefined, opts)}`;
}

const weekLabel = computed(() => {
  if (current.value?.week_start && current.value?.week_end) {
    return formatWeekRange(current.value.week_start, current.value.week_end);
  }
  if (weekStartInput.value) {
    const start = weekStartInput.value;
    const endDate = new Date(`${start}T12:00:00`);
    endDate.setDate(endDate.getDate() + 6);
    return formatWeekRange(start, toIsoDate(endDate));
  }
  return "No week generated yet";
});

const previousWeekLabel = computed(() => {
  if (!previous.value?.week_start) return null;
  return formatWeekRange(previous.value.week_start, previous.value.week_end);
});

const deltaCents = computed(() => comparison.value?.delta_cents ?? null);
const deltaPercent = computed(() => comparison.value?.percent ?? null);

const deltaPositive = computed(() => {
  if (deltaCents.value == null) return null;
  return deltaCents.value >= 0;
});

const deltaClass = computed(() => {
  if (deltaPositive.value === null) return "billing-wow__change--neutral";
  return deltaPositive.value ? "billing-wow__change--up" : "billing-wow__change--down";
});

const hasSnapshot = computed(() => !!current.value);

const alreadyGeneratedForInput = computed(() => {
  if (!weekStartInput.value || !current.value?.week_start) return false;
  return String(current.value.week_start) === String(weekStartInput.value);
});

async function load(weekStart) {
  loading.value = true;
  errorMsg.value = "";
  try {
    const params = {};
    if (weekStart) params.week_start = weekStart;
    const { data } = await api.get("/billing/week-summaries", { params });
    current.value = data?.current ?? null;
    previous.value = data?.previous ?? null;
    comparison.value = data?.comparison ?? null;
    defaultWeekStart.value = data?.default_week_start || mondayIso();
    if (!weekStartInput.value) {
      weekStartInput.value = current.value?.week_start || defaultWeekStart.value;
    }
  } catch (e) {
    errorMsg.value =
      e.response?.data?.message || "Could not load billing week summary.";
  } finally {
    loading.value = false;
  }
}

function requestGenerate() {
  if (!weekStartInput.value) {
    toast.error("Choose a week to generate.");
    return;
  }
  confirmGenerateOpen.value = true;
}

async function confirmGenerate() {
  generating.value = true;
  try {
    const { data } = await api.post("/billing/week-summaries/generate", {
      week_start: weekStartInput.value,
    });
    current.value = data?.current ?? data?.generated ?? null;
    previous.value = data?.previous ?? null;
    comparison.value = data?.comparison ?? null;
    if (current.value?.week_start) {
      weekStartInput.value = current.value.week_start;
    }
    confirmGenerateOpen.value = false;
    toast.success("Week summary generated.");
  } catch (e) {
    toast.errorFrom(e, "Could not generate week summary.");
  } finally {
    generating.value = false;
  }
}

async function onWeekChange() {
  if (!weekStartInput.value) return;
  // Snap display to Monday of selected date via reload if snapshot exists
  await load(weekStartInput.value);
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Billing Summary",
    description: "Weekly billed totals and charge-type breakdown.",
  });
  load();
});
</script>

<template>
  <div class="staff-page staff-page--wide billing-week-summary">
    <div
      class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 mb-1 fw-semibold text-body">Billing Summary</h1>
        <p class="text-secondary small mb-0">
          Weekly billed totals from invoice service periods (cached snapshots)
        </p>
      </div>
      <div class="d-flex flex-wrap align-items-end gap-2 ms-lg-auto">
        <div>
          <label class="form-label small text-secondary mb-1" for="billing-week-start">
            Week (Monday)
          </label>
          <input
            id="billing-week-start"
            v-model="weekStartInput"
            type="date"
            class="form-control form-control-sm billing-week-summary__date"
            :disabled="loading || generating"
            @change="onWeekChange"
          />
        </div>
        <button
          type="button"
          class="btn btn-primary staff-page-primary"
          :disabled="loading || generating || !weekStartInput"
          @click="requestGenerate"
        >
          {{ generating ? "Generating…" : "Generate Week" }}
        </button>
        <RouterLink
          to="/admin/billing/invoices"
          class="btn btn-outline-secondary"
        >
          View Invoices
        </RouterLink>
      </div>
    </div>

    <div v-if="errorMsg" class="alert alert-danger" role="alert">
      {{ errorMsg }}
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading summary…" />
    </div>

    <template v-else>
      <div v-if="!hasSnapshot" class="billing-week-summary__empty card border-0 shadow-sm p-4 p-md-5 mb-4">
        <h2 class="h5 fw-semibold mb-2">No Snapshot Yet</h2>
        <p class="text-secondary mb-3">
          Generate a week to scan invoice billing periods once and save the totals.
          Later visits load the saved numbers instead of recalculating.
        </p>
        <button
          type="button"
          class="btn btn-primary align-self-start"
          :disabled="generating || !weekStartInput"
          @click="requestGenerate"
        >
          Generate {{ weekStartInput || "Week" }}
        </button>
      </div>

      <template v-else>
        <section class="billing-wow card border-0 shadow-sm mb-4">
          <div class="billing-wow__inner p-4 p-md-5">
            <div class="billing-wow__eyebrow">Total Billed</div>
            <div class="billing-wow__week">{{ weekLabel }}</div>
            <div class="billing-wow__amount">
              {{ formatCents(current.total_billed_cents) }}
            </div>
            <div class="billing-wow__meta d-flex flex-wrap align-items-center gap-3 mt-3">
              <div v-if="previous" class="billing-wow__prev">
                <span class="billing-wow__prev-label">Previous week</span>
                <span class="billing-wow__prev-value">
                  {{ formatCents(previous.total_billed_cents) }}
                </span>
                <span v-if="previousWeekLabel" class="billing-wow__prev-range">
                  {{ previousWeekLabel }}
                </span>
              </div>
              <div v-else class="billing-wow__prev text-secondary">
                No previous week snapshot
              </div>
              <div
                v-if="deltaCents != null"
                class="billing-wow__change"
                :class="deltaClass"
              >
                <span class="billing-wow__change-amount">
                  {{ deltaCents >= 0 ? "+" : "" }}{{ formatCents(deltaCents) }}
                </span>
                <span v-if="deltaPercent != null" class="billing-wow__change-pct">
                  {{ deltaPercent >= 0 ? "+" : "" }}{{ deltaPercent }}%
                </span>
              </div>
            </div>
            <div class="billing-wow__footer small mt-3">
              {{ current.invoice_count }} invoice{{ current.invoice_count === 1 ? "" : "s" }}
              <template v-if="current.generated_at">
                · Generated {{ new Date(current.generated_at).toLocaleString() }}
              </template>
            </div>
          </div>
        </section>

        <h2 class="h6 fw-semibold text-body mb-3">Breakdown by Charge Type</h2>
        <div class="row g-3">
          <div
            v-for="row in BREAKDOWN"
            :key="row.key"
            class="col-12 col-sm-6 col-xl-4"
          >
            <div class="billing-breakdown-card h-100">
              <div class="billing-breakdown-card__label">{{ row.label }}</div>
              <div class="billing-breakdown-card__hint">{{ row.hint }}</div>
              <div class="billing-breakdown-card__value">
                {{ formatCents(current[row.key] || 0) }}
              </div>
            </div>
          </div>
        </div>
      </template>
    </template>

    <ConfirmModal
      :open="confirmGenerateOpen"
      title="Generate Week Summary"
      :message="
        alreadyGeneratedForInput
          ? 'This week already has a snapshot. Generate again to overwrite with fresh invoice totals?'
          : 'Scan invoices for this week’s billing periods and save category totals?'
      "
      confirm-label="Generate"
      :busy="generating"
      :danger="false"
      @close="confirmGenerateOpen = false"
      @confirm="confirmGenerate"
    />
  </div>
</template>

<style scoped>
.billing-week-summary__date {
  min-width: 11rem;
}

.billing-week-summary__empty {
  background: linear-gradient(145deg, #fff9f0 0%, #fff 55%);
  border-radius: 0.75rem;
}

.billing-wow {
  border-radius: 1rem;
  overflow: hidden;
  background: linear-gradient(135deg, #c9952a 0%, #e8c872 42%, #f7e7b8 100%);
}

.billing-wow__inner {
  color: #3d2a0a;
}

.billing-wow__eyebrow {
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  opacity: 0.75;
}

.billing-wow__week {
  font-size: 0.95rem;
  font-weight: 600;
  margin-top: 0.25rem;
  opacity: 0.9;
}

.billing-wow__amount {
  font-size: clamp(2rem, 4vw, 2.75rem);
  font-weight: 800;
  line-height: 1.15;
  margin-top: 0.75rem;
  letter-spacing: -0.02em;
}

.billing-wow__prev {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}

.billing-wow__prev-label {
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  opacity: 0.7;
}

.billing-wow__prev-value {
  font-size: 1.1rem;
  font-weight: 700;
}

.billing-wow__prev-range {
  font-size: 0.75rem;
  opacity: 0.75;
}

.billing-wow__change {
  display: inline-flex;
  align-items: baseline;
  gap: 0.5rem;
  padding: 0.4rem 0.75rem;
  border-radius: 999px;
  font-weight: 700;
  background: rgba(255, 255, 255, 0.45);
}

.billing-wow__change--up {
  color: #0f7a3a;
  background: rgba(16, 185, 129, 0.22);
}

.billing-wow__change--down {
  color: #b42318;
  background: rgba(239, 68, 68, 0.2);
}

.billing-wow__change--neutral {
  color: #3d2a0a;
}

.billing-wow__change-pct {
  font-size: 0.9rem;
}

.billing-wow__footer {
  opacity: 0.75;
}

.billing-breakdown-card {
  background: #fff;
  border: 1px solid rgba(201, 149, 42, 0.22);
  border-radius: 0.75rem;
  padding: 1.1rem 1.25rem;
  box-shadow: 0 0.125rem 0.5rem rgba(61, 42, 10, 0.04);
}

.billing-breakdown-card__label {
  font-weight: 700;
  color: #3d2a0a;
}

.billing-breakdown-card__hint {
  font-size: 0.75rem;
  color: #6b7280;
  margin-top: 0.15rem;
}

.billing-breakdown-card__value {
  margin-top: 0.75rem;
  font-size: 1.35rem;
  font-weight: 750;
  color: #1f2937;
  letter-spacing: -0.01em;
}
</style>
