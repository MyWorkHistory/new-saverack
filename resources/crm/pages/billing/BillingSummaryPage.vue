<script setup>
import { computed, onMounted, ref } from "vue";
import api from "../../services/api";
import BillingDollarStatIcon from "../../components/billing/BillingDollarStatIcon.vue";
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
const rangeMenuOpen = ref(false);

const BREAKDOWN = [
  {
    key: "fulfillment_cents",
    label: "Fulfillment Fees",
    tone: "blue",
    icon: "cube",
  },
  {
    key: "postage_cents",
    label: "Postage",
    tone: "purple",
    icon: "mail",
  },
  {
    key: "materials_cents",
    label: "Materials",
    tone: "orange",
    icon: "box",
  },
  {
    key: "returns_cents",
    label: "Returns",
    tone: "red",
    icon: "return",
  },
  {
    key: "custom_work_cents",
    label: "Custom Work",
    tone: "teal",
    icon: "tools",
  },
  {
    key: "wholesale_cents",
    label: "Wholesale",
    tone: "amber",
    icon: "users",
  },
];

function toIsoDate(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

function mondayIso(date = new Date()) {
  const d = new Date(date);
  const day = d.getDay();
  const diff = day === 0 ? -6 : 1 - day;
  d.setDate(d.getDate() + diff);
  d.setHours(0, 0, 0, 0);
  return toIsoDate(d);
}

function addDaysIso(iso, days) {
  const d = new Date(`${iso}T12:00:00`);
  d.setDate(d.getDate() + days);
  return toIsoDate(d);
}

function formatShortRange(start, end) {
  if (!start || !end) return "—";
  const opts = { month: "short", day: "numeric", year: "numeric" };
  const a = new Date(`${start}T12:00:00`);
  const b = new Date(`${end}T12:00:00`);
  return `${a.toLocaleDateString(undefined, opts)} – ${b.toLocaleDateString(undefined, opts)}`;
}

function rowCompare(currentCents, previousCents) {
  const cur = Number(currentCents) || 0;
  const prev = previousCents == null ? null : Number(previousCents) || 0;
  if (prev === null) {
    return { delta: null, percent: null, up: null };
  }
  const delta = cur - prev;
  const percent = prev === 0 ? null : Math.round((delta / Math.abs(prev)) * 10000) / 100;
  return { delta, percent, up: delta >= 0 };
}

const hasSnapshot = computed(() => !!current.value);

const currentRangeLabel = computed(() => {
  if (current.value?.week_start && current.value?.week_end) {
    return formatShortRange(current.value.week_start, current.value.week_end);
  }
  if (weekStartInput.value) {
    return formatShortRange(weekStartInput.value, addDaysIso(weekStartInput.value, 6));
  }
  return "Select a week";
});

const previousRangeLabel = computed(() => {
  if (previous.value?.week_start && previous.value?.week_end) {
    return formatShortRange(previous.value.week_start, previous.value.week_end);
  }
  return "—";
});

const pickerLabel = computed(() => {
  if (!hasSnapshot.value) {
    return weekStartInput.value
      ? `${formatShortRange(weekStartInput.value, addDaysIso(weekStartInput.value, 6))}`
      : "Choose week";
  }
  return `${currentRangeLabel.value} (Last Week)`;
});

const lastWeekTotal = computed(() => Number(current.value?.total_billed_cents) || 0);
const previousWeekTotal = computed(() =>
  previous.value ? Number(previous.value.total_billed_cents) || 0 : null,
);

const heroChange = computed(() => {
  if (comparison.value?.delta_cents != null) {
    return rowCompare(lastWeekTotal.value, previousWeekTotal.value);
  }
  return rowCompare(lastWeekTotal.value, previousWeekTotal.value);
});

const breakdownRows = computed(() =>
  BREAKDOWN.map((meta) => {
    const cur = Number(current.value?.[meta.key]) || 0;
    const prev = previous.value ? Number(previous.value[meta.key]) || 0 : null;
    const cmp = rowCompare(cur, prev);
    return { ...meta, current: cur, previous: prev, ...cmp };
  }),
);

const alreadyGeneratedForInput = computed(() => {
  if (!weekStartInput.value || !current.value?.week_start) return false;
  return String(current.value.week_start) === String(weekStartInput.value);
});

function formatSignedCents(cents) {
  if (cents == null || !Number.isFinite(Number(cents))) return "—";
  const n = Number(cents);
  const abs = formatCents(Math.abs(n));
  if (n > 0) return `+ ${abs}`;
  if (n < 0) return `− ${abs}`;
  return abs;
}

function formatPercent(pct, up) {
  if (pct == null || !Number.isFinite(Number(pct))) return "—";
  const n = Number(pct);
  const abs = Math.abs(n).toFixed(2);
  if (up === true) return `${abs}%`;
  if (up === false) return `${abs}%`;
  return `${abs}%`;
}

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
    } else if (current.value?.week_start) {
      weekStartInput.value = current.value.week_start;
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
  rangeMenuOpen.value = false;
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

async function onWeekPicked() {
  rangeMenuOpen.value = false;
  if (!weekStartInput.value) return;
  await load(weekStartInput.value);
}

function exportCsv() {
  if (!hasSnapshot.value) {
    toast.error("Generate a week before exporting.");
    return;
  }
  const headers = [
    "Charge Type",
    "Last Week",
    "Previous Week",
    "Change",
    "% Change",
  ];
  const lines = [headers.join(",")];
  for (const row of breakdownRows.value) {
    lines.push(
      [
        `"${row.label}"`,
        (row.current / 100).toFixed(2),
        row.previous == null ? "" : (row.previous / 100).toFixed(2),
        row.delta == null ? "" : (row.delta / 100).toFixed(2),
        row.percent == null ? "" : row.percent.toFixed(2),
      ].join(","),
    );
  }
  const t = heroChange.value;
  lines.push(
    [
      "Total",
      (lastWeekTotal.value / 100).toFixed(2),
      previousWeekTotal.value == null ? "" : (previousWeekTotal.value / 100).toFixed(2),
      t.delta == null ? "" : (t.delta / 100).toFixed(2),
      t.percent == null ? "" : t.percent.toFixed(2),
    ].join(","),
  );
  const blob = new Blob([lines.join("\n")], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `billing-summary-${current.value.week_start || "week"}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Billing Summary",
    description: "Weekly overview of charges and comparisons.",
  });
  load();
});
</script>

<template>
  <div class="staff-page staff-page--wide billing-summary-ui">
    <header class="billing-summary-ui__head">
      <div class="min-w-0">
        <h1 class="billing-summary-ui__title">Billing Summary</h1>
        <p class="billing-summary-ui__subtitle">
          Weekly overview of charges and comparisons.
        </p>
      </div>
      <div class="billing-summary-ui__actions">
        <button
          type="button"
          class="billing-summary-ui__btn billing-summary-ui__btn--ghost"
          :disabled="!hasSnapshot || loading"
          @click="exportCsv"
        >
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
          </svg>
          Export
        </button>

        <div class="billing-summary-ui__picker-wrap">
          <button
            type="button"
            class="billing-summary-ui__btn billing-summary-ui__btn--picker"
            :disabled="loading || generating"
            @click="rangeMenuOpen = !rangeMenuOpen"
          >
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5A2.25 2.25 0 015.25 5.25h13.5A2.25 2.25 0 0121 7.5v11.25A2.25 2.25 0 0118.75 21H5.25A2.25 2.25 0 013 18.75zM3 9.75h18" />
            </svg>
            <span class="text-truncate">{{ pickerLabel }}</span>
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
            </svg>
          </button>
          <div v-if="rangeMenuOpen" class="billing-summary-ui__picker-menu shadow">
            <label class="form-label small mb-1" for="billing-week-start">Week start (Monday)</label>
            <input
              id="billing-week-start"
              v-model="weekStartInput"
              type="date"
              class="form-control form-control-sm mb-2"
            />
            <div class="d-flex gap-2">
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary flex-grow-1"
                @click="onWeekPicked"
              >
                Load
              </button>
              <button
                type="button"
                class="btn btn-sm btn-primary flex-grow-1"
                :disabled="generating"
                @click="requestGenerate"
              >
                {{ generating ? "…" : "Generate" }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </header>

    <div v-if="errorMsg" class="alert alert-danger" role="alert">{{ errorMsg }}</div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading summary…" />
    </div>

    <template v-else>
      <div v-if="!hasSnapshot" class="billing-summary-ui__empty">
        <h2 class="h5 fw-semibold mb-2">No Week Generated Yet</h2>
        <p class="text-secondary mb-3 mb-0">
          Pick a week and generate once to cache totals from invoice billing periods.
        </p>
        <button
          type="button"
          class="btn btn-primary mt-3"
          :disabled="generating || !weekStartInput"
          @click="requestGenerate"
        >
          Generate Week
        </button>
      </div>

      <template v-else>
        <div class="row g-3 g-xl-4 mb-4 billing-summary-ui__cards">
          <div class="col-12 col-md-4">
            <div class="staff-stat-card billing-inv-summary-card billing-inv-summary-card--static h-100">
              <p class="staff-stat-card__label">
                Last Week ({{ currentRangeLabel }})
              </p>
              <p class="staff-stat-card__value text-primary">
                {{ formatCents(lastWeekTotal) }}
              </p>
              <p class="staff-stat-card__sub">Total billed</p>
              <div class="staff-stat-card__icon staff-stat-card__icon--money" aria-hidden="true">
                <BillingDollarStatIcon />
              </div>
            </div>
          </div>

          <div class="col-12 col-md-4">
            <div class="staff-stat-card billing-inv-summary-card billing-inv-summary-card--static h-100">
              <p class="staff-stat-card__label">
                Previous Week ({{ previousRangeLabel }})
              </p>
              <p class="staff-stat-card__value">
                {{ previousWeekTotal == null ? "—" : formatCents(previousWeekTotal) }}
              </p>
              <p class="staff-stat-card__sub">Total billed</p>
              <div
                class="staff-stat-card__icon bg-secondary-subtle text-secondary"
                aria-hidden="true"
              >
                <svg fill="currentColor" viewBox="0 0 24 24">
                  <path
                    d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20a2 2 0 0 0 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2m0 16H5V10h14zm0-12H5V6h14z"
                  />
                </svg>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-4">
            <div class="staff-stat-card billing-inv-summary-card billing-inv-summary-card--static h-100">
              <p class="staff-stat-card__label">Change (vs Previous Week)</p>
              <p
                class="staff-stat-card__value"
                :class="
                  heroChange.up === true
                    ? 'text-success'
                    : heroChange.up === false
                      ? 'text-danger'
                      : ''
                "
              >
                {{ formatSignedCents(heroChange.delta) }}
              </p>
              <p
                class="staff-stat-card__sub"
                :class="
                  heroChange.up === true
                    ? 'text-success'
                    : heroChange.up === false
                      ? 'text-danger'
                      : ''
                "
              >
                <template v-if="heroChange.percent != null">
                  {{ formatPercent(heroChange.percent, heroChange.up) }}
                  {{ heroChange.up ? "increase" : "decrease" }}
                </template>
                <template v-else>
                  {{ previousWeekTotal == null ? "Generate previous week to compare" : "—" }}
                </template>
              </p>
              <div
                class="staff-stat-card__icon"
                :class="
                  heroChange.up === false
                    ? 'bg-danger-subtle text-danger'
                    : 'bg-success-subtle text-success'
                "
                aria-hidden="true"
              >
                <svg v-if="heroChange.up === false" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M16 18l2.29-2.29-4.88-4.88-4 4L2 7.41 3.41 6l6 6 4-4 6.3 6.29L22 12v6z" />
                </svg>
                <svg v-else fill="currentColor" viewBox="0 0 24 24">
                  <path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z" />
                </svg>
              </div>
            </div>
          </div>
        </div>

        <section class="billing-summary-table-card">
          <h2 class="billing-summary-table-card__title">Breakdown by Charge Type</h2>
          <div class="table-responsive">
            <table class="billing-summary-table">
              <thead>
                <tr>
                  <th>Charge Type</th>
                  <th>Last Week<br /><span>({{ currentRangeLabel }})</span></th>
                  <th>Previous Week<br /><span>({{ previousRangeLabel }})</span></th>
                  <th>Change</th>
                  <th>% Change</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in breakdownRows" :key="row.key">
                  <td>
                    <div class="billing-summary-type">
                      <span
                        class="billing-summary-type__icon"
                        :class="`billing-summary-type__icon--${row.tone}`"
                        aria-hidden="true"
                      >
                        <svg v-if="row.icon === 'cube'" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                        </svg>
                        <svg v-else-if="row.icon === 'mail'" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                        </svg>
                        <svg v-else-if="row.icon === 'box'" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                        </svg>
                        <svg v-else-if="row.icon === 'return'" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" />
                        </svg>
                        <svg v-else-if="row.icon === 'tools'" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17l-5.13 5.12a1.5 1.5 0 01-2.12-2.12l5.12-5.13m7.82-1.91a3.75 3.75 0 10-5.3-5.3 3.75 3.75 0 005.3 5.3z" />
                        </svg>
                        <svg v-else width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                        </svg>
                      </span>
                      <span class="billing-summary-type__label">{{ row.label }}</span>
                    </div>
                  </td>
                  <td class="billing-summary-table__accent">{{ formatCents(row.current) }}</td>
                  <td>{{ row.previous == null ? "—" : formatCents(row.previous) }}</td>
                  <td
                    :class="
                      row.up === true
                        ? 'billing-summary-table__up'
                        : row.up === false
                          ? 'billing-summary-table__down'
                          : ''
                    "
                  >
                    {{ formatSignedCents(row.delta) }}
                  </td>
                  <td
                    :class="
                      row.up === true
                        ? 'billing-summary-table__up'
                        : row.up === false
                          ? 'billing-summary-table__down'
                          : ''
                    "
                  >
                    <span class="billing-summary-table__pct">
                      <svg
                        v-if="row.up === true"
                        width="14"
                        height="14"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2.25"
                        aria-hidden="true"
                      >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25" />
                      </svg>
                      <svg
                        v-else-if="row.up === false"
                        width="14"
                        height="14"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2.25"
                        aria-hidden="true"
                      >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 4.5l15 15m0 0V8.25m0 11.25H8.25" />
                      </svg>
                      {{ formatPercent(row.percent, row.up) }}
                    </span>
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <td>Total</td>
                  <td class="billing-summary-table__accent fw-bold">
                    {{ formatCents(lastWeekTotal) }}
                  </td>
                  <td class="fw-semibold">
                    {{ previousWeekTotal == null ? "—" : formatCents(previousWeekTotal) }}
                  </td>
                  <td
                    class="fw-semibold"
                    :class="
                      heroChange.up === true
                        ? 'billing-summary-table__up'
                        : heroChange.up === false
                          ? 'billing-summary-table__down'
                          : ''
                    "
                  >
                    {{ formatSignedCents(heroChange.delta) }}
                  </td>
                  <td
                    class="fw-semibold"
                    :class="
                      heroChange.up === true
                        ? 'billing-summary-table__up'
                        : heroChange.up === false
                          ? 'billing-summary-table__down'
                          : ''
                    "
                  >
                    <span class="billing-summary-table__pct">
                      <svg
                        v-if="heroChange.up === true"
                        width="14"
                        height="14"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2.25"
                        aria-hidden="true"
                      >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25" />
                      </svg>
                      <svg
                        v-else-if="heroChange.up === false"
                        width="14"
                        height="14"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2.25"
                        aria-hidden="true"
                      >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 4.5l15 15m0 0V8.25m0 11.25H8.25" />
                      </svg>
                      {{ formatPercent(heroChange.percent, heroChange.up) }}
                    </span>
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        </section>
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
.billing-summary-ui {
  --bs-accent: #2563eb;
  --bs-border: #e5e7eb;
  --bs-muted: #6b7280;
  --bs-text: #111827;
  --bs-up: #16a34a;
  --bs-down: #dc2626;
  --bs-card-radius: 0.85rem;
}

.billing-summary-ui__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.billing-summary-ui__title {
  margin: 0;
  font-size: 1.75rem;
  font-weight: 700;
  color: var(--bs-text);
  letter-spacing: -0.02em;
}

.billing-summary-ui__subtitle {
  margin: 0.35rem 0 0;
  color: var(--bs-muted);
  font-size: 0.95rem;
}

.billing-summary-ui__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.65rem;
}

.billing-summary-ui__btn {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  border-radius: 0.55rem;
  border: 1px solid var(--bs-border);
  background: #fff;
  color: var(--bs-text);
  font-size: 0.875rem;
  font-weight: 600;
  padding: 0.55rem 0.9rem;
  line-height: 1.2;
}

.billing-summary-ui__btn:disabled {
  opacity: 0.55;
}

.billing-summary-ui__btn--ghost:hover:not(:disabled) {
  background: #f9fafb;
}

.billing-summary-ui__btn--picker {
  max-width: min(100%, 22rem);
}

.billing-summary-ui__picker-wrap {
  position: relative;
}

.billing-summary-ui__picker-menu {
  position: absolute;
  right: 0;
  top: calc(100% + 0.4rem);
  z-index: 20;
  width: 16.5rem;
  padding: 0.85rem;
  border-radius: 0.65rem;
  border: 1px solid var(--bs-border);
  background: #fff;
}

.billing-summary-ui__empty {
  border: 1px solid var(--bs-border);
  border-radius: var(--bs-card-radius);
  background: #fff;
  padding: 2rem;
}

.billing-summary-ui__cards :deep(.billing-inv-summary-card--static) {
  cursor: default;
}

.billing-summary-table-card {
  border: 1px solid var(--bs-border);
  border-radius: var(--bs-card-radius);
  background: #fff;
  overflow: hidden;
}

.billing-summary-table-card__title {
  margin: 0;
  padding: 1rem 1.25rem 0.85rem;
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--bs-text);
}

.billing-summary-table {
  width: 100%;
  border-collapse: collapse;
  margin: 0;
}

.billing-summary-table th,
.billing-summary-table td {
  padding: 0.95rem 1.15rem;
  border-top: 1px solid var(--bs-border);
  vertical-align: middle;
  font-size: 0.925rem;
  white-space: nowrap;
}

.billing-summary-table thead th {
  border-top: none;
  color: var(--bs-muted);
  font-weight: 600;
  font-size: 0.8rem;
  text-align: left;
}

.billing-summary-table thead th span {
  font-weight: 500;
  color: #9ca3af;
}

.billing-summary-table tbody td:nth-child(n + 2),
.billing-summary-table thead th:nth-child(n + 2),
.billing-summary-table tfoot td:nth-child(n + 2) {
  text-align: right;
}

.billing-summary-table__accent {
  color: var(--bs-accent);
  font-weight: 650;
}

.billing-summary-table__up {
  color: var(--bs-up);
  font-weight: 650;
}

.billing-summary-table__down {
  color: var(--bs-down);
  font-weight: 650;
}

.billing-summary-table__pct {
  display: inline-flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.25rem;
}

.billing-summary-table tfoot tr {
  background: #eff6ff;
}

.billing-summary-table tfoot td {
  font-weight: 700;
}

.billing-summary-type {
  display: inline-flex;
  align-items: center;
  gap: 0.65rem;
}

.billing-summary-type__icon {
  width: 1.85rem;
  height: 1.85rem;
  border-radius: 0.45rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.billing-summary-type__icon--blue {
  background: #dbeafe;
  color: #2563eb;
}
.billing-summary-type__icon--purple {
  background: #ede9fe;
  color: #7c3aed;
}
.billing-summary-type__icon--orange {
  background: #ffedd5;
  color: #ea580c;
}
.billing-summary-type__icon--red {
  background: #fee2e2;
  color: #dc2626;
}
.billing-summary-type__icon--teal {
  background: #ccfbf1;
  color: #0d9488;
}
.billing-summary-type__icon--amber {
  background: #fef3c7;
  color: #d97706;
}

.billing-summary-type__label {
  font-weight: 600;
  color: var(--bs-text);
}
</style>
