<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
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
const weeks = ref([]);
const totals = ref(null);
const fromInput = ref("");
const toInput = ref("");
const rangeMenuOpen = ref(false);
const weekStartInput = ref("");
const defaultWeekStart = ref("");
const confirmGenerateOpen = ref(false);
const pollTimer = ref(null);
const pollingId = ref(null);

const unmatchedOpen = ref(false);
const unmatchedLoading = ref(false);
const unmatchedItems = ref([]);
const unmatchedWeek = ref(null);

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
  return formatFullRange(fromInput.value, addDaysIso(toInput.value, 6));
});

const weekRows = computed(() =>
  weeks.value.map((week, index) => ({
    ...week,
    weekNumber: index + 1,
    rangeLabel: formatShortRange(week.week_start, week.week_end),
  })),
);

const weekCount = computed(() => Number(totals.value?.week_count) || weekRows.value.length);

const alreadyGeneratedForInput = computed(() =>
  weeks.value.some((w) => String(w.week_start) === String(weekStartInput.value)),
);

function stopPoll() {
  if (pollTimer.value) {
    clearInterval(pollTimer.value);
    pollTimer.value = null;
  }
  pollingId.value = null;
}

async function loadList() {
  loading.value = true;
  errorMsg.value = "";
  try {
    const params = {};
    if (fromInput.value) params.from = fromInput.value;
    if (toInput.value) params.to = toInput.value;
    const { data } = await api.get("/billing/week-earnings", { params });
    weeks.value = Array.isArray(data?.weeks) ? data.weeks : [];
    totals.value = data?.totals ?? null;
    if (data?.from) fromInput.value = data.from;
    if (data?.to) toInput.value = data.to;
    if (!weekStartInput.value) {
      weekStartInput.value = data?.to || defaultWeekStart.value || mondayIso();
    }
  } catch (e) {
    errorMsg.value = e.response?.data?.message || "Could not load earnings.";
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
  await loadList();
}

function requestGenerate() {
  if (!weekStartInput.value) {
    toast.error("Choose a week to generate.");
    return;
  }
  weekStartInput.value = mondayIso(new Date(`${weekStartInput.value}T12:00:00`));
  confirmGenerateOpen.value = true;
}

async function confirmGenerate() {
  generating.value = true;
  try {
    const { data } = await api.post("/billing/week-earnings/generate", {
      week_start: weekStartInput.value,
    });
    confirmGenerateOpen.value = false;
    const id = data?.id;
    const status = data?.status;
    if (data?.default_week_start) {
      defaultWeekStart.value = data.default_week_start;
    }
    if (status === "completed") {
      toast.success("Earnings generated.");
      await loadList();
      generating.value = false;
      return;
    }
    toast.success("Earnings generation started.");
    if (id) {
      startPoll(id);
      return;
    }
    generating.value = false;
  } catch (e) {
    generating.value = false;
    toast.errorFrom(e, "Could not start earnings generation.");
  }
}

function startPoll(id) {
  stopPoll();
  pollingId.value = id;
  generating.value = true;
  pollTimer.value = setInterval(async () => {
    try {
      const { data } = await api.get(`/billing/week-earnings/${id}`);
      const status = data?.status;
      if (status === "completed") {
        stopPoll();
        generating.value = false;
        toast.success("Earnings generated.");
        await loadList();
      } else if (status === "failed") {
        stopPoll();
        generating.value = false;
        toast.error(data?.error_message || "Earnings generation failed.");
        await loadList();
      }
    } catch {
      /* keep polling briefly */
    }
  }, 2500);
}

async function openUnmatched(week) {
  if (!week?.id || !Number(week.unmatched_count)) return;
  unmatchedWeek.value = week;
  unmatchedOpen.value = true;
  unmatchedLoading.value = true;
  unmatchedItems.value = [];
  try {
    const { data } = await api.get(`/billing/week-earnings/${week.id}/unmatched`);
    unmatchedItems.value = Array.isArray(data?.items) ? data.items : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load unmatched items.");
    unmatchedOpen.value = false;
  } finally {
    unmatchedLoading.value = false;
  }
}

function accountFeesHref(accountId) {
  return `/admin/clients/accounts/${accountId}?tab=fees`;
}

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Earnings",
    description: "Weekly earnings from invoice charges minus account costs.",
  });
  defaultWeekStart.value = mondayIso(new Date(Date.now() - 7 * 86400000));
  weekStartInput.value = defaultWeekStart.value;
  await loadList();
});

onUnmounted(() => {
  stopPoll();
});
</script>

<template>
  <div class="staff-page staff-page--wide billing-earnings">
    <ConfirmModal
      :open="confirmGenerateOpen"
      title="Generate Earnings"
      :message="
        alreadyGeneratedForInput
          ? 'This week already has earnings. Regenerate and overwrite?'
          : 'Generate earnings for the selected week? This may take a while.'
      "
      confirm-label="Generate"
      :danger="false"
      :busy="generating"
      @close="confirmGenerateOpen = false"
      @confirm="confirmGenerate"
    />

    <header class="billing-earnings__head">
      <div class="min-w-0">
        <h1 class="billing-earnings__title">Earnings</h1>
        <p class="billing-earnings__subtitle">
          Profit by charge type (invoice charge minus account cost). Generate a week to refresh.
        </p>
      </div>
      <div class="billing-earnings__actions">
        <div class="billing-earnings__gen">
          <label class="visually-hidden" for="earnings-week-start">Week start</label>
          <input
            id="earnings-week-start"
            v-model="weekStartInput"
            type="date"
            class="form-control form-control-sm"
            :disabled="generating"
          />
          <button
            type="button"
            class="btn btn-primary btn-sm"
            :disabled="generating || !weekStartInput"
            @click="requestGenerate"
          >
            <CrmLoadingSpinner v-if="generating" small class="me-1" />
            {{ generating ? "Generating…" : "Generate" }}
          </button>
        </div>
        <div class="billing-earnings__range" data-earnings-range>
          <button
            type="button"
            class="billing-earnings__range-btn"
            :disabled="loading || generating"
            @click="rangeMenuOpen = !rangeMenuOpen"
          >
            <span>{{ pickerLabel }}</span>
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
          </button>
          <div v-if="rangeMenuOpen" class="billing-earnings__range-menu">
            <label class="small text-secondary mb-1 d-block">From week (Monday)</label>
            <input v-model="fromInput" type="date" class="form-control form-control-sm mb-2" />
            <label class="small text-secondary mb-1 d-block">To week (Monday)</label>
            <input v-model="toInput" type="date" class="form-control form-control-sm mb-3" />
            <button type="button" class="btn btn-primary btn-sm w-100" @click="applyRange">
              Apply Range
            </button>
          </div>
        </div>
      </div>
    </header>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner />
    </div>
    <p v-else-if="errorMsg" class="text-danger mb-0">{{ errorMsg }}</p>
    <div v-else-if="!weekRows.length" class="billing-earnings__empty">
      <p class="mb-2">No earnings weeks generated yet.</p>
      <p class="small text-secondary mb-0">
        Choose a week above and click Generate. Matching uses each account’s fee Cost.
      </p>
    </div>
    <div v-else class="billing-earnings__card">
      <div class="table-responsive">
        <table class="billing-earnings__table">
          <thead>
            <tr>
              <th scope="col">Week</th>
              <th v-for="col in COLUMNS" :key="col.key" scope="col">
                <span class="billing-earnings__colhead">
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
              <th scope="col" class="billing-earnings__total-col">Total</th>
              <th scope="col">Items Not Found</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in weekRows" :key="row.id || row.week_start">
              <td>
                <div class="billing-earnings__week">
                  <span class="billing-earnings__week-num">Week {{ row.weekNumber }}</span>
                  <span class="billing-earnings__week-range">{{ row.rangeLabel }}</span>
                </div>
              </td>
              <td
                v-for="col in COLUMNS"
                :key="col.key"
                :class="`billing-earnings__amt billing-earnings__amt--${col.tone}`"
              >
                {{ formatCents(row[col.key]) }}
              </td>
              <td class="billing-earnings__amt billing-earnings__amt--total">
                {{ formatCents(row.total_cents) }}
              </td>
              <td>
                <button
                  v-if="Number(row.unmatched_count) > 0"
                  type="button"
                  class="billing-earnings__unmatched-btn"
                  @click="openUnmatched(row)"
                >
                  {{ Number(row.unmatched_count).toLocaleString() }}
                </button>
                <span v-else class="text-secondary">0</span>
              </td>
            </tr>
          </tbody>
          <tfoot v-if="totals">
            <tr>
              <td><strong>Total ({{ weekCount }} Weeks)</strong></td>
              <td
                v-for="col in COLUMNS"
                :key="`t-${col.key}`"
                :class="`billing-earnings__amt billing-earnings__amt--${col.tone}`"
              >
                <strong>{{ formatCents(totals[col.key]) }}</strong>
              </td>
              <td class="billing-earnings__amt billing-earnings__amt--total">
                <strong>{{ formatCents(totals.total_cents) }}</strong>
              </td>
              <td>
                <strong>{{ Number(totals.unmatched_count || 0).toLocaleString() }}</strong>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <div
      v-if="unmatchedOpen"
      class="billing-earnings-modal"
      role="dialog"
      aria-modal="true"
      aria-label="Items Not Found"
      @click.self="unmatchedOpen = false"
    >
      <div class="billing-earnings-modal__panel">
        <header class="billing-earnings-modal__head">
          <div class="min-w-0">
            <h2 class="h5 mb-1">Items Not Found</h2>
            <p class="small text-secondary mb-0">
              <template v-if="unmatchedWeek">
                {{ formatShortRange(unmatchedWeek.week_start, unmatchedWeek.week_end) }}
                — add missing fees / costs on the account, then regenerate.
              </template>
            </p>
          </div>
          <button type="button" class="btn btn-sm btn-outline-secondary" @click="unmatchedOpen = false">
            Close
          </button>
        </header>
        <div class="billing-earnings-modal__body">
          <div v-if="unmatchedLoading" class="d-flex justify-content-center py-4">
            <CrmLoadingSpinner />
          </div>
          <p v-else-if="!unmatchedItems.length" class="text-secondary mb-0">No unmatched items.</p>
          <div v-else class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Item</th>
                  <th>Account</th>
                  <th>Invoice</th>
                  <th>Qty</th>
                  <th>Billed</th>
                  <th>Reason</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in unmatchedItems" :key="item.id">
                  <td>
                    <div class="fw-semibold">{{ item.display_name }}</div>
                    <div class="small text-secondary text-capitalize">{{ item.category }}</div>
                  </td>
                  <td>
                    <RouterLink
                      :to="accountFeesHref(item.client_account_id)"
                      class="link-primary"
                    >
                      {{ item.company_name || `Account #${item.client_account_id}` }}
                    </RouterLink>
                  </td>
                  <td>{{ item.invoice_number || "—" }}</td>
                  <td>{{ item.quantity }}</td>
                  <td>{{ formatCents(item.billed_cents) }}</td>
                  <td>{{ item.reason_label }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.billing-earnings__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.25rem;
}

.billing-earnings__title {
  font-size: 1.35rem;
  font-weight: 700;
  margin: 0 0 0.25rem;
}

.billing-earnings__subtitle {
  margin: 0;
  font-size: 0.875rem;
  color: var(--bs-secondary-color, #64748b);
}

.billing-earnings__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.65rem;
}

.billing-earnings__gen {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
}

.billing-earnings__range {
  position: relative;
}

.billing-earnings__range-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  border: 1px solid #e2e8f0;
  background: #fff;
  border-radius: 0.5rem;
  padding: 0.45rem 0.75rem;
  font-size: 0.875rem;
  font-weight: 500;
}

.billing-earnings__range-menu {
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

.billing-earnings__empty {
  background: #fff;
  border: 1px solid #e8e6ec;
  border-radius: 0.75rem;
  padding: 2.5rem 1.5rem;
  text-align: center;
}

.billing-earnings__card {
  background: #fff;
  border: 1px solid #e8e6ec;
  border-radius: 0.75rem;
  overflow: hidden;
}

.billing-earnings__table {
  width: 100%;
  margin: 0;
  border-collapse: collapse;
  font-size: 0.875rem;
}

.billing-earnings__table th,
.billing-earnings__table td {
  padding: 0.85rem 1rem;
  border-bottom: 1px solid #f1f5f9;
  vertical-align: middle;
  white-space: nowrap;
}

.billing-earnings__table thead th {
  background: #f8fafc;
  font-weight: 600;
  color: #334155;
  font-size: 0.8125rem;
}

.billing-earnings__colhead {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

.billing-earnings__week {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.billing-earnings__week-num {
  font-weight: 650;
}

.billing-earnings__week-range {
  font-size: 0.75rem;
  color: #64748b;
}

.billing-earnings__amt {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}

.billing-earnings__amt--blue { color: #2563eb; }
.billing-earnings__amt--purple { color: #7c3aed; }
.billing-earnings__amt--orange { color: #ea580c; }
.billing-earnings__amt--red { color: #dc2626; }
.billing-earnings__amt--teal { color: #0d9488; }
.billing-earnings__amt--amber { color: #d97706; }
.billing-earnings__amt--total { color: #1d4ed8; font-weight: 700; }

.billing-earnings__table tfoot tr {
  background: #eff6ff;
}

.billing-earnings__unmatched-btn {
  border: 0;
  background: #fee2e2;
  color: #b91c1c;
  font-weight: 650;
  border-radius: 999px;
  padding: 0.2rem 0.65rem;
  font-size: 0.8125rem;
}

.billing-earnings-modal {
  position: fixed;
  inset: 0;
  z-index: 1050;
  background: rgba(15, 23, 42, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}

.billing-earnings-modal__panel {
  width: min(56rem, 100%);
  max-height: min(85vh, 40rem);
  background: #fff;
  border-radius: 0.75rem;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.billing-earnings-modal__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #e8e6ec;
}

.billing-earnings-modal__body {
  padding: 1rem 1.25rem 1.25rem;
  overflow: auto;
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

.billing-summary-type__icon--blue { background: #dbeafe; color: #2563eb; }
.billing-summary-type__icon--purple { background: #ede9fe; color: #7c3aed; }
.billing-summary-type__icon--orange { background: #ffedd5; color: #ea580c; }
.billing-summary-type__icon--red { background: #fee2e2; color: #dc2626; }
.billing-summary-type__icon--teal { background: #ccfbf1; color: #0d9488; }
.billing-summary-type__icon--amber { background: #fef3c7; color: #d97706; }

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
