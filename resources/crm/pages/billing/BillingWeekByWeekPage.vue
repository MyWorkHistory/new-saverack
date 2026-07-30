<script setup>
import { computed, onMounted, ref } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";
import { formatCents } from "../../utils/formatMoney.js";

const toast = useToast();
const router = useRouter();

const loading = ref(true);
const errorMsg = ref("");
const weeks = ref([]);
const totals = ref(null);
const fromInput = ref("");
const toInput = ref("");
const rangeMenuOpen = ref(false);

const COLUMNS = [
  { key: "fulfillment_cents", label: "Fulfillment Fees", tone: "blue", icon: "cube" },
  { key: "postage_cents", label: "Postage", tone: "purple", icon: "mail" },
  { key: "materials_cents", label: "Materials", tone: "orange", icon: "box" },
  { key: "returns_cents", label: "Returns", tone: "red", icon: "return" },
  { key: "custom_work_cents", label: "Custom Work", tone: "teal", icon: "tools" },
  { key: "wholesale_cents", label: "Wholesale", tone: "amber", icon: "users" },
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
  const opts = { month: "short", day: "numeric" };
  const a = new Date(`${start}T12:00:00`);
  const b = new Date(`${end}T12:00:00`);
  return `${a.toLocaleDateString(undefined, opts)} – ${b.toLocaleDateString(undefined, opts)}`;
}

function formatFullRange(start, end) {
  if (!start || !end) return "—";
  const opts = { month: "short", day: "numeric", year: "numeric" };
  const a = new Date(`${start}T12:00:00`);
  const b = new Date(`${end}T12:00:00`);
  return `${a.toLocaleDateString(undefined, opts)} – ${b.toLocaleDateString(undefined, opts)}`;
}

const pickerLabel = computed(() => {
  if (!fromInput.value || !toInput.value) return "Choose range";
  const toEnd = addDaysIso(toInput.value, 6);
  return formatFullRange(fromInput.value, toEnd);
});

const weekRows = computed(() =>
  weeks.value.map((week, index) => ({
    ...week,
    weekNumber: index + 1,
    rangeLabel: formatShortRange(week.week_start, week.week_end),
  })),
);

const weekCount = computed(() => Number(totals.value?.week_count) || weekRows.value.length);

async function load() {
  loading.value = true;
  errorMsg.value = "";
  try {
    const params = {};
    if (fromInput.value) params.from = fromInput.value;
    if (toInput.value) params.to = toInput.value;
    const { data } = await api.get("/billing/week-summaries/weeks", { params });
    weeks.value = Array.isArray(data?.weeks) ? data.weeks : [];
    totals.value = data?.totals ?? null;
    if (data?.from) fromInput.value = data.from;
    if (data?.to) toInput.value = data.to;
  } catch (e) {
    errorMsg.value = e.response?.data?.message || "Could not load week-by-week revenue.";
    weeks.value = [];
    totals.value = null;
  } finally {
    loading.value = false;
  }
}

async function applyRange() {
  rangeMenuOpen.value = false;
  if (fromInput.value) fromInput.value = mondayIso(new Date(`${fromInput.value}T12:00:00`));
  if (toInput.value) toInput.value = mondayIso(new Date(`${toInput.value}T12:00:00`));
  await load();
}

function openWeek(week) {
  if (!week?.week_start) return;
  router.push({
    name: "billing-revenue",
    query: { week_start: week.week_start },
  });
}

function exportCsv() {
  if (!weekRows.value.length) {
    toast.error("No weeks to export.");
    return;
  }
  const headers = [
    "Week",
    "Week Start",
    "Week End",
    ...COLUMNS.map((c) => c.label),
    "Total",
  ];
  const lines = [headers.join(",")];
  for (const row of weekRows.value) {
    lines.push(
      [
        `Week ${row.weekNumber}`,
        row.week_start || "",
        row.week_end || "",
        ...COLUMNS.map((c) => ((Number(row[c.key]) || 0) / 100).toFixed(2)),
        ((Number(row.total_billed_cents) || 0) / 100).toFixed(2),
      ].join(","),
    );
  }
  if (totals.value) {
    lines.push(
      [
        `Total (${weekCount.value} Weeks)`,
        "",
        "",
        ...COLUMNS.map((c) => ((Number(totals.value[c.key]) || 0) / 100).toFixed(2)),
        ((Number(totals.value.total_billed_cents) || 0) / 100).toFixed(2),
      ].join(","),
    );
  }
  const blob = new Blob([lines.join("\n")], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `revenue-week-by-week-${fromInput.value || "from"}-${toInput.value || "to"}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Week by Week",
    description: "Breakdown by charge type across generated weeks.",
  });
  load();
});
</script>

<template>
  <div class="staff-page staff-page--wide billing-week-by-week">
    <header class="billing-week-by-week__head">
      <div class="min-w-0">
        <h1 class="billing-week-by-week__title">Breakdown by Charge Type (Week by Week)</h1>
        <p class="billing-week-by-week__subtitle">All amounts in USD</p>
      </div>
      <div class="billing-week-by-week__actions">
        <div class="billing-week-by-week__range" data-week-range>
          <button
            type="button"
            class="billing-week-by-week__range-btn"
            :disabled="loading"
            @click="rangeMenuOpen = !rangeMenuOpen"
          >
            <span>{{ pickerLabel }}</span>
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
          </button>
          <div v-if="rangeMenuOpen" class="billing-week-by-week__range-menu">
            <label class="small text-secondary mb-1 d-block">From week (Monday)</label>
            <input v-model="fromInput" type="date" class="form-control form-control-sm mb-2" />
            <label class="small text-secondary mb-1 d-block">To week (Monday)</label>
            <input v-model="toInput" type="date" class="form-control form-control-sm mb-3" />
            <button type="button" class="btn btn-primary btn-sm w-100" @click="applyRange">
              Apply Range
            </button>
          </div>
        </div>
        <button
          type="button"
          class="billing-week-by-week__export"
          :disabled="loading || !weekRows.length"
          title="Export"
          aria-label="Export"
          @click="exportCsv"
        >
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
          </svg>
        </button>
      </div>
    </header>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner />
    </div>
    <p v-else-if="errorMsg" class="text-danger mb-0">{{ errorMsg }}</p>
    <div v-else-if="!weekRows.length" class="billing-week-by-week__empty">
      <p class="mb-2">No generated weeks in this range.</p>
      <p class="small text-secondary mb-3">
        Generate a week on Revenue first, then it will appear here.
      </p>
      <RouterLink class="btn btn-primary btn-sm" :to="{ name: 'billing-revenue' }">
        Open Revenue
      </RouterLink>
    </div>
    <div v-else class="billing-week-by-week__card">
      <div class="table-responsive">
        <table class="billing-week-by-week__table">
          <thead>
            <tr>
              <th scope="col">Week</th>
              <th v-for="col in COLUMNS" :key="col.key" scope="col">
                <span class="billing-week-by-week__colhead">
                  <span
                    class="billing-summary-type__icon"
                    :class="`billing-summary-type__icon--${col.tone}`"
                    aria-hidden="true"
                  >
                    <svg v-if="col.icon === 'cube'" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                    </svg>
                    <svg v-else-if="col.icon === 'mail'" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                    </svg>
                    <svg v-else-if="col.icon === 'box'" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                    </svg>
                    <svg v-else-if="col.icon === 'return'" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" />
                    </svg>
                    <svg v-else-if="col.icon === 'tools'" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17l-5.13 5.12a1.5 1.5 0 01-2.12-2.12l5.12-5.13m7.82-1.91a3.75 3.75 0 10-5.3-5.3 3.75 3.75 0 005.3 5.3z" />
                    </svg>
                    <svg v-else width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                    </svg>
                  </span>
                  {{ col.label }}
                </span>
              </th>
              <th scope="col" class="billing-week-by-week__total-col">Total</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in weekRows"
              :key="row.id || row.week_start"
              class="billing-week-by-week__row"
              @click="openWeek(row)"
            >
              <td>
                <div class="billing-week-by-week__week">
                  <span class="billing-week-by-week__week-num">Week {{ row.weekNumber }}</span>
                  <span class="billing-week-by-week__week-range">{{ row.rangeLabel }}</span>
                </div>
              </td>
              <td
                v-for="col in COLUMNS"
                :key="col.key"
                :class="`billing-week-by-week__amt billing-week-by-week__amt--${col.tone}`"
              >
                {{ formatCents(row[col.key]) }}
              </td>
              <td class="billing-week-by-week__amt billing-week-by-week__amt--total">
                {{ formatCents(row.total_billed_cents) }}
              </td>
            </tr>
          </tbody>
          <tfoot v-if="totals">
            <tr>
              <td>
                <strong>Total ({{ weekCount }} Weeks)</strong>
              </td>
              <td
                v-for="col in COLUMNS"
                :key="`t-${col.key}`"
                :class="`billing-week-by-week__amt billing-week-by-week__amt--${col.tone}`"
              >
                <strong>{{ formatCents(totals[col.key]) }}</strong>
              </td>
              <td class="billing-week-by-week__amt billing-week-by-week__amt--total">
                <strong>{{ formatCents(totals.total_billed_cents) }}</strong>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.billing-week-by-week__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.25rem;
}

.billing-week-by-week__title {
  font-size: 1.35rem;
  font-weight: 700;
  margin: 0 0 0.25rem;
  color: var(--bs-body-color, #0f172a);
}

.billing-week-by-week__subtitle {
  margin: 0;
  font-size: 0.875rem;
  color: var(--bs-secondary-color, #64748b);
}

.billing-week-by-week__actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.billing-week-by-week__range {
  position: relative;
}

.billing-week-by-week__range-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  border: 1px solid #e2e8f0;
  background: #fff;
  border-radius: 0.5rem;
  padding: 0.45rem 0.75rem;
  font-size: 0.875rem;
  font-weight: 500;
  color: #0f172a;
}

.billing-week-by-week__range-menu {
  position: absolute;
  right: 0;
  top: calc(100% + 0.35rem);
  z-index: 20;
  width: 16rem;
  padding: 0.85rem;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 0.65rem;
  box-shadow: 0 0.5rem 1.25rem rgba(15, 23, 42, 0.1);
}

.billing-week-by-week__export {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.35rem;
  height: 2.35rem;
  border: 1px solid #e2e8f0;
  border-radius: 0.5rem;
  background: #fff;
  color: #475569;
}

.billing-week-by-week__empty {
  background: #fff;
  border: 1px solid #e8e6ec;
  border-radius: 0.75rem;
  padding: 2.5rem 1.5rem;
  text-align: center;
}

.billing-week-by-week__card {
  background: #fff;
  border: 1px solid #e8e6ec;
  border-radius: 0.75rem;
  overflow: hidden;
}

.billing-week-by-week__table {
  width: 100%;
  margin: 0;
  border-collapse: collapse;
  font-size: 0.875rem;
}

.billing-week-by-week__table th,
.billing-week-by-week__table td {
  padding: 0.85rem 1rem;
  border-bottom: 1px solid #f1f5f9;
  vertical-align: middle;
  white-space: nowrap;
}

.billing-week-by-week__table thead th {
  background: #f8fafc;
  font-weight: 600;
  color: #334155;
  font-size: 0.8125rem;
}

.billing-week-by-week__colhead {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

.billing-week-by-week__row {
  cursor: pointer;
}

.billing-week-by-week__row:nth-child(even) {
  background: #fafbfc;
}

.billing-week-by-week__row:hover {
  background: #f1f5f9;
}

.billing-week-by-week__week {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.billing-week-by-week__week-num {
  font-weight: 650;
  color: #0f172a;
}

.billing-week-by-week__week-range {
  font-size: 0.75rem;
  color: #64748b;
}

.billing-week-by-week__amt {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}

.billing-week-by-week__amt--blue {
  color: #2563eb;
}
.billing-week-by-week__amt--purple {
  color: #7c3aed;
}
.billing-week-by-week__amt--orange {
  color: #ea580c;
}
.billing-week-by-week__amt--red {
  color: #dc2626;
}
.billing-week-by-week__amt--teal {
  color: #0d9488;
}
.billing-week-by-week__amt--amber {
  color: #d97706;
}
.billing-week-by-week__amt--total {
  color: #1d4ed8;
  font-weight: 700;
}

.billing-week-by-week__table tfoot tr {
  background: #eff6ff;
}

.billing-week-by-week__table tfoot td {
  border-bottom: 0;
}

.billing-summary-type__icon {
  width: 1.5rem;
  height: 1.5rem;
  border-radius: 0.4rem;
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
</style>
