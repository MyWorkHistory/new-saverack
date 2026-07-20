<script setup>
import { computed, onMounted, ref, watch } from "vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmRefreshToolbarButton from "../../components/common/CrmRefreshToolbarButton.vue";
import CrmSyncToolbar from "../../components/common/CrmSyncToolbar.vue";
import { HOLD_TYPE_SECTIONS } from "../../constants/holdSummaryCards.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useAdminHomeDashboard } from "../../composables/useAdminHomeDashboard.js";
import { useToast } from "../../composables/useToast.js";
import { avatarClassFromSeed, initialsFromName } from "../../utils/avatarDisplay.js";
import { formatDateTimeUs } from "../../utils/formatUserDates.js";

const PER_PAGE = 20;

const HOLD_BREAKDOWN = [
  {
    key: "hold_operator",
    label: "Operator Hold",
    description: "Orders manually placed on hold by warehouse staff",
    tone: "red",
    icon: "operator",
  },
  {
    key: "hold_address",
    label: "Address Hold",
    description: "Invalid or incomplete address",
    tone: "pink",
    icon: "address",
  },
  {
    key: "hold_fraud",
    label: "Fraud Hold",
    description: "Awaiting fraud review",
    tone: "blue",
    icon: "fraud",
  },
  {
    key: "hold_payment",
    label: "Payment Hold",
    description: "Payment issue detected",
    tone: "orange",
    icon: "payment",
  },
  {
    key: "hold_user",
    label: "User Hold",
    description: "Hold placed by client",
    tone: "purple",
    icon: "user",
  },
  {
    key: "paused",
    label: "Paused",
    description: "Ready-to-ship orders paused",
    tone: "gray",
    icon: "paused",
  },
];

const toast = useToast();
const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

const { loading, refreshing, sections, load, refreshSection } = useAdminHomeDashboard({
  onError: (e) => toast.errorFrom(e, "Could not load orders by account."),
});

const search = ref("");
const page = ref(1);
const selectedAccountId = ref(null);
const sortDir = ref("desc");

function sectionData(key) {
  return (
    sections.value?.[key] || {
      accounts: [],
      total_count: 0,
      status: "idle",
      refreshed_at: null,
      truncated: false,
    }
  );
}

/** Merge on-hold + hold-type + paused RTS accounts from cached dashboard snapshots. */
const allAccounts = computed(() => {
  const byId = new Map();

  function ensure(row) {
    const accountId = Number(row?.account_id || 0);
    if (accountId <= 0) return null;
    let entry = byId.get(accountId);
    if (!entry) {
      entry = {
        account_id: accountId,
        account_name: String(row.account_name || `Account #${accountId}`),
        account_status: String(row.account_status || "active").toLowerCase(),
        on_hold_count: 0,
        paused_rts_count: 0,
        holds: {
          hold_operator: 0,
          hold_address: 0,
          hold_fraud: 0,
          hold_payment: 0,
          hold_user: 0,
        },
      };
      byId.set(accountId, entry);
    } else if (row.account_name) {
      entry.account_name = String(row.account_name);
    }
    if (row.account_status) {
      entry.account_status = String(row.account_status).toLowerCase();
    }
    return entry;
  }

  for (const row of sectionData("on_hold").accounts || []) {
    const entry = ensure(row);
    if (!entry) continue;
    entry.on_hold_count = Math.max(entry.on_hold_count, Number(row.orders_count || 0));
  }

  for (const hold of HOLD_TYPE_SECTIONS) {
    for (const row of sectionData(hold.key).accounts || []) {
      const entry = ensure(row);
      if (!entry) continue;
      const count = Number(row.orders_count || 0);
      entry.holds[hold.key] = Math.max(entry.holds[hold.key] || 0, count);
    }
  }

  // Paused accounts: RTS counts feed the "Paused" breakdown row.
  for (const row of sectionData("ready_to_ship").accounts || []) {
    if (String(row?.account_status || "").toLowerCase() !== "paused") continue;
    const entry = ensure(row);
    if (!entry) continue;
    entry.paused_rts_count = Math.max(entry.paused_rts_count, Number(row.orders_count || 0));
  }

  return [...byId.values()]
    .map((a) => {
      const holdSum = Object.values(a.holds).reduce((s, n) => s + Number(n || 0), 0);
      // Prefer distinct on-hold total; fall back to hold-type sum when on_hold row is missing.
      const totalOrders = a.on_hold_count > 0 ? a.on_hold_count : holdSum;
      return {
        ...a,
        total_orders: totalOrders,
        is_paused: a.account_status === "paused",
      };
    })
    .filter((a) => a.total_orders > 0)
    .sort((a, b) => {
      const diff = Number(b.total_orders) - Number(a.total_orders);
      return sortDir.value === "asc" ? -diff : diff;
    });
});

const filteredAccounts = computed(() => {
  const q = String(search.value || "").trim().toLowerCase();
  if (!q) return allAccounts.value;
  return allAccounts.value.filter((a) =>
    String(a.account_name || "").toLowerCase().includes(q),
  );
});

const totalFiltered = computed(() => filteredAccounts.value.length);
const lastPage = computed(() => Math.max(1, Math.ceil(totalFiltered.value / PER_PAGE)));

const pagedAccounts = computed(() => {
  const start = (page.value - 1) * PER_PAGE;
  return filteredAccounts.value.slice(start, start + PER_PAGE);
});

const showingFrom = computed(() =>
  totalFiltered.value === 0 ? 0 : (page.value - 1) * PER_PAGE + 1,
);
const showingTo = computed(() =>
  Math.min(page.value * PER_PAGE, totalFiltered.value),
);

const pageNumbers = computed(() => {
  const last = lastPage.value;
  const cur = page.value;
  const pages = [];
  const push = (n) => {
    if (!pages.includes(n)) pages.push(n);
  };
  push(1);
  for (let i = cur - 1; i <= cur + 1; i++) {
    if (i > 1 && i < last) push(i);
  }
  if (last > 1) push(last);
  return pages.sort((a, b) => a - b);
});

const selectedAccount = computed(() => {
  if (selectedAccountId.value == null) return null;
  return allAccounts.value.find((a) => a.account_id === selectedAccountId.value) || null;
});

const breakdownRows = computed(() => {
  const account = selectedAccount.value;
  if (!account) return [];
  return HOLD_BREAKDOWN.map((meta) => {
    const orders =
      meta.key === "paused"
        ? Number(account.paused_rts_count || 0)
        : Number(account.holds?.[meta.key] || 0);
    return { ...meta, orders };
  });
});

const selectedHoldTotal = computed(() => {
  const account = selectedAccount.value;
  if (!account) return 0;
  return Number(account.total_orders || 0);
});

const lastSyncedLabel = computed(() => {
  const keys = ["on_hold", "ready_to_ship", ...HOLD_TYPE_SECTIONS.map((h) => h.key)];
  let latestMs = null;
  for (const key of keys) {
    const at = sectionData(key).refreshed_at;
    if (!at) continue;
    const ms = new Date(at).getTime();
    if (!Number.isFinite(ms)) continue;
    if (latestMs === null || ms > latestMs) latestMs = ms;
  }
  return latestMs !== null ? formatDateTimeUs(new Date(latestMs).toISOString()) : "";
});

function selectAccount(accountId) {
  selectedAccountId.value = Number(accountId);
}

function toggleSort() {
  sortDir.value = sortDir.value === "desc" ? "asc" : "desc";
  page.value = 1;
}

function goToPage(n) {
  const next = Math.min(Math.max(1, Number(n) || 1), lastPage.value);
  page.value = next;
}

function statusBadge(account) {
  if (account?.is_paused) {
    return { label: "Paused", className: "oba-badge oba-badge--paused" };
  }
  return { label: "On Hold", className: "oba-badge oba-badge--hold" };
}

function refreshToastMessage(data) {
  if (data?.refresh_index_only) {
    return "Counts updated from database. Full sync queued if index was empty.";
  }
  if (data?.refresh_synced) return "Refreshed.";
  if (data?.refresh_enqueued) return "Refresh queued — counts will update shortly.";
  return "Refresh started.";
}

async function onRefresh() {
  try {
    const data = await refreshSection("all", { sync: true });
    toast.success(refreshToastMessage(data));
  } catch {
    /* toast handled */
  }
}

watch(search, () => {
  page.value = 1;
});

watch(pagedAccounts, (rows) => {
  if (!rows.length) {
    selectedAccountId.value = null;
    return;
  }
  const stillVisible = rows.some((r) => r.account_id === selectedAccountId.value);
  if (!stillVisible) {
    selectedAccountId.value = rows[0].account_id;
  }
});

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Orders | By Account",
    description: "On-hold order breakdown by account.",
  });
  try {
    await load();
  } catch {
    /* toast handled */
  }
});
</script>

<template>
  <div class="staff-page staff-page--wide orders-by-account">
    <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4">
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 mb-1 fw-semibold text-body">Orders by Account</h1>
        <p class="text-secondary small mb-0">
          View on-hold order breakdown by account
        </p>
      </div>
      <CrmSyncToolbar :last-synced-label="lastSyncedLabel" prefix="Last Updated:">
        <CrmRefreshToolbarButton
          label="Refresh"
          title="Refresh order counts"
          :loading="refreshing"
          :disabled="loading || refreshing"
          @click="onRefresh"
        />
      </CrmSyncToolbar>
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading accounts…" />
    </div>

    <div v-else class="row g-3 g-xl-4 orders-by-account__split">
      <div class="col-12 col-xl-5">
        <section class="oba-card h-100">
          <header class="oba-card__head oba-card__head--accounts">
            <h2 class="oba-card__title">Accounts</h2>
          </header>
          <div class="oba-card__search">
            <svg
              class="oba-card__search-icon"
              width="18"
              height="18"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="1.75"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M21 21l-4.35-4.35m1.6-4.4a7 7 0 11-14 0 7 7 0 0114 0z"
              />
            </svg>
            <input
              v-model="search"
              type="search"
              class="form-control oba-card__search-input"
              placeholder="Search accounts..."
              aria-label="Search accounts"
            />
          </div>

          <div class="table-responsive">
            <table class="oba-table">
              <thead>
                <tr>
                  <th>Account</th>
                  <th>Status</th>
                  <th class="text-end">
                    <button
                      type="button"
                      class="oba-sort-btn"
                      @click="toggleSort"
                    >
                      Total Orders
                      <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M7 10l5-5 5 5zm0 4l5 5 5-5z" />
                      </svg>
                    </button>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="pagedAccounts.length === 0">
                  <td colspan="3" class="text-secondary text-center py-4">
                    No accounts with on-hold orders.
                  </td>
                </tr>
                <tr
                  v-for="account in pagedAccounts"
                  :key="account.account_id"
                  class="oba-table__row"
                  :class="{ 'oba-table__row--active': selectedAccountId === account.account_id }"
                  @click="selectAccount(account.account_id)"
                >
                  <td>
                    <div class="oba-account">
                      <span
                        class="oba-account__avatar"
                        :class="avatarClassFromSeed(account.account_name)"
                        aria-hidden="true"
                      >
                        {{ initialsFromName(account.account_name) }}
                      </span>
                      <span class="oba-account__name">{{ account.account_name }}</span>
                    </div>
                  </td>
                  <td>
                    <span :class="statusBadge(account).className">
                      <span class="oba-badge__dot" aria-hidden="true" />
                      {{ statusBadge(account).label }}
                    </span>
                  </td>
                  <td class="text-end">
                    <span class="oba-orders">{{ nf.format(account.total_orders) }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <footer class="oba-card__footer">
            <nav class="oba-pagination" aria-label="Accounts pagination">
              <button
                type="button"
                class="oba-page-btn"
                :disabled="page <= 1"
                @click="goToPage(page - 1)"
              >
                ‹
              </button>
              <template v-for="(n, idx) in pageNumbers" :key="n">
                <span
                  v-if="idx > 0 && n - pageNumbers[idx - 1] > 1"
                  class="oba-page-ellipsis"
                >…</span>
                <button
                  type="button"
                  class="oba-page-btn"
                  :class="{ 'oba-page-btn--active': n === page }"
                  @click="goToPage(n)"
                >
                  {{ n }}
                </button>
              </template>
              <button
                type="button"
                class="oba-page-btn"
                :disabled="page >= lastPage"
                @click="goToPage(page + 1)"
              >
                ›
              </button>
            </nav>
            <p class="oba-card__count mb-0">
              Showing {{ nf.format(showingFrom) }} to {{ nf.format(showingTo) }}
              of {{ nf.format(totalFiltered) }} accounts
            </p>
          </footer>
        </section>
      </div>

      <div class="col-12 col-xl-7">
        <section class="oba-card h-100">
          <template v-if="selectedAccount">
            <header class="oba-card__head">
              <h2 class="oba-card__title">
                Hold Breakdown - {{ selectedAccount.account_name }}
              </h2>
              <p class="oba-card__total">
                <span class="oba-card__total-icon" aria-hidden="true">
                  <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
                    <path
                      d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"
                    />
                  </svg>
                </span>
                Total Orders On Hold:
                <strong>{{ nf.format(selectedHoldTotal) }}</strong>
              </p>
            </header>

            <div class="table-responsive">
              <table class="oba-breakdown-table">
                <thead>
                  <tr>
                    <th>Hold Type</th>
                    <th class="text-end">Orders</th>
                    <th>Description</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in breakdownRows" :key="row.key">
                    <td>
                      <div class="oba-hold-type">
                        <span
                          class="oba-hold-type__icon"
                          :class="`oba-hold-type__icon--${row.tone}`"
                          aria-hidden="true"
                        >
                          <svg v-if="row.icon === 'operator'" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2zm0-4h-2V7h2z" />
                          </svg>
                          <svg v-else-if="row.icon === 'address'" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 110-5 2.5 2.5 0 010 5z" />
                          </svg>
                          <svg v-else-if="row.icon === 'fraud'" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-1 6h2v6h-2zm0 8h2v2h-2z" />
                          </svg>
                          <svg v-else-if="row.icon === 'payment'" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16zm0-10H4V6h16z" />
                          </svg>
                          <svg v-else-if="row.icon === 'user'" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                          </svg>
                          <svg v-else fill="currentColor" viewBox="0 0 24 24">
                            <path d="M6 19h4V5H6zm8-14v14h4V5z" />
                          </svg>
                        </span>
                        <span class="oba-hold-type__label">{{ row.label }}</span>
                      </div>
                    </td>
                    <td class="text-end fw-semibold">{{ nf.format(row.orders) }}</td>
                    <td class="text-secondary">{{ row.description }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
          <div v-else class="oba-card__empty text-secondary">
            Select an account to view hold breakdown.
          </div>
        </section>
      </div>
    </div>
  </div>
</template>

<style scoped>
.orders-by-account {
  --oba-border: #e5e7eb;
  --oba-muted: #6b7280;
  --oba-text: #111827;
  --oba-blue: #2563eb;
  --oba-blue-soft: #eff6ff;
}

.oba-card {
  background: #fff;
  border: 1px solid var(--oba-border);
  border-radius: 0.85rem;
  box-shadow: 0 0.125rem 0.5rem rgba(15, 23, 42, 0.04);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.oba-card__search {
  position: relative;
  padding: 0.75rem 1rem 0.75rem;
}

.oba-card__search-icon {
  position: absolute;
  left: 1.65rem;
  top: 50%;
  transform: translateY(-50%);
  color: #9ca3af;
  pointer-events: none;
}

.oba-card__search-input {
  padding-left: 2.35rem;
  border-radius: 0.55rem;
}

.oba-card__head {
  padding: 1.15rem 1.25rem 0.5rem;
}

.oba-card__head--accounts {
  padding-bottom: 0;
}

.oba-card__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--oba-text);
}

.oba-card__total {
  margin: 0.75rem 0 0;
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  color: var(--oba-blue);
  font-size: 0.95rem;
}

.oba-card__total-icon {
  display: inline-flex;
  color: var(--oba-blue);
}

.oba-card__empty {
  padding: 2.5rem 1.25rem;
  text-align: center;
}

.oba-card__footer {
  margin-top: auto;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.85rem 1rem 1rem;
  border-top: 1px solid var(--oba-border);
}

.oba-card__count {
  font-size: 0.8rem;
  color: var(--oba-muted);
}

.oba-table,
.oba-breakdown-table {
  width: 100%;
  border-collapse: collapse;
  margin: 0;
}

.oba-table th,
.oba-table td,
.oba-breakdown-table th,
.oba-breakdown-table td {
  padding: 0.85rem 1rem;
  border-top: 1px solid var(--oba-border);
  vertical-align: middle;
  font-size: 0.9rem;
}

.oba-table thead th,
.oba-breakdown-table thead th {
  border-top: none;
  font-size: 0.72rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--oba-muted);
  font-weight: 650;
  background: #fafafa;
}

.oba-table__row {
  cursor: pointer;
}

.oba-table__row:hover {
  background: #f8fafc;
}

.oba-table__row--active {
  background: var(--oba-blue-soft);
  box-shadow: inset 3px 0 0 var(--oba-blue);
}

.oba-sort-btn {
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  letter-spacing: inherit;
  text-transform: inherit;
  font-weight: inherit;
  padding: 0;
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  margin-left: auto;
}

.oba-table thead th:last-child .oba-sort-btn {
  width: 100%;
  justify-content: flex-end;
}

.oba-account {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  min-width: 0;
}

.oba-account__avatar {
  width: 2.15rem;
  height: 2.15rem;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 0.72rem;
  font-weight: 700;
  flex-shrink: 0;
}

.oba-account__name {
  font-weight: 650;
  color: var(--oba-text);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.oba-orders {
  color: var(--oba-blue);
  font-weight: 700;
}

.oba-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  border-radius: 999px;
  padding: 0.2rem 0.65rem;
  font-size: 0.75rem;
  font-weight: 650;
  white-space: nowrap;
}

.oba-badge__dot {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  background: currentColor;
}

.oba-badge--hold {
  background: #fee2e2;
  color: #dc2626;
}

.oba-badge--paused {
  background: #f3f4f6;
  color: #4b5563;
}

.oba-pagination {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
}

.oba-page-btn {
  min-width: 2rem;
  height: 2rem;
  border-radius: 0.4rem;
  border: 1px solid var(--oba-border);
  background: #fff;
  color: var(--oba-text);
  font-size: 0.85rem;
  font-weight: 600;
}

.oba-page-btn:disabled {
  opacity: 0.45;
}

.oba-page-btn--active {
  background: var(--oba-blue);
  border-color: var(--oba-blue);
  color: #fff;
}

.oba-page-ellipsis {
  color: var(--oba-muted);
  padding: 0 0.15rem;
}

.oba-hold-type {
  display: inline-flex;
  align-items: center;
  gap: 0.65rem;
}

.oba-hold-type__icon {
  width: 1.85rem;
  height: 1.85rem;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.oba-hold-type__icon svg {
  width: 1rem;
  height: 1rem;
}

.oba-hold-type__icon--red {
  background: #fee2e2;
  color: #dc2626;
}
.oba-hold-type__icon--pink {
  background: #fce7f3;
  color: #db2777;
}
.oba-hold-type__icon--blue {
  background: #dbeafe;
  color: #2563eb;
}
.oba-hold-type__icon--orange {
  background: #ffedd5;
  color: #ea580c;
}
.oba-hold-type__icon--purple {
  background: #ede9fe;
  color: #7c3aed;
}
.oba-hold-type__icon--gray {
  background: #f3f4f6;
  color: #6b7280;
}

.oba-hold-type__label {
  font-weight: 650;
  color: var(--oba-text);
}
</style>
