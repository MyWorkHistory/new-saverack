<script setup>
import { computed, inject, onMounted, onUnmounted, ref } from "vue";
import { RouterLink } from "vue-router";
import VueApexCharts from "vue3-apexcharts";
import api from "../services/api";
import CrmMetricCard from "../components/dashboard/CrmMetricCard.vue";
import CrmLoadingSpinner from "../components/common/CrmLoadingSpinner.vue";
import ConfirmModal from "../components/common/ConfirmModal.vue";
import UserEditModal from "../components/users/UserEditModal.vue";
import { useToast } from "../composables/useToast";
import { crmIsAdmin } from "../utils/crmUser";
import { errorMessage } from "../utils/apiError";
import { formatBirthdayUs, formatIsoDate } from "../utils/formatUserDates";
import CrmIconRowActions from "../components/common/CrmIconRowActions.vue";
import { resolvePublicUrl } from "../utils/resolvePublicUrl.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();

const loading = ref(true);
const period = ref("monthly");
const search = ref("");
const statusFilter = ref("");

const currentUser = ref(null);
const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const deleteTarget = ref(null);
const deleteBusy = ref(false);
const deleteError = ref("");
const userEditModalOpen = ref(false);
const userEditModalUserId = ref("");
const recentUsersFilterOpen = ref(false);

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canUpdateUsers = computed(() => userHasPerm("users.update"));
const canDeleteUsers = computed(() => userHasPerm("users.delete"));
const showRowActions = computed(
  () => canUpdateUsers.value || canDeleteUsers.value,
);

const tableColspan = computed(() => {
  let n = 6;
  if (showRowActions.value) n += 1;
  return n;
});

const deleteModalOpen = computed(() => deleteTarget.value !== null);
const deleteMessage = computed(() => {
  const u = deleteTarget.value;
  return u
    ? `Are you sure you want to delete ${u.name}? This cannot be undone.`
    : "";
});

const summary = ref({
  metrics: {
    total_users: { value: 0, change_pct: 0 },
    active_users: { value: 0, change_pct: 0 },
    activities_today: { value: 0, change_pct: 0 },
  },
  chart: { labels: [], activity: [], new_users: [] },
  year_totals: {},
  users_by_status: { pending: 0, active: 0, inactive: 0 },
  recent_users: [],
  recent_activity: [],
  engagement_score: 0,
});

const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });
const df = new Intl.DateTimeFormat(undefined, {
  weekday: "short",
  day: "numeric",
  month: "short",
});
const tf = new Intl.DateTimeFormat(undefined, {
  hour: "numeric",
  minute: "2-digit",
});

function splitWhen(iso) {
  if (!iso) return { date: "—", time: "—" };
  const d = new Date(iso);
  return { date: df.format(d), time: tf.format(d) };
}

const avatarPalettes = [
  "bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200",
  "bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-200",
  "bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-200",
  "bg-emerald-100 text-emerald-900 dark:bg-emerald-500/20 dark:text-emerald-200",
  "bg-rose-100 text-rose-900 dark:bg-rose-500/20 dark:text-rose-200",
];

function initials(name) {
  if (!name || typeof name !== "string") return "?";
  const parts = name.trim().split(/\s+/).slice(0, 2);
  return parts.map((p) => p[0]?.toUpperCase() ?? "").join("") || "?";
}

function avatarClassForUser(email) {
  let h = 0;
  const s = email || "";
  for (let i = 0; i < s.length; i++) h = (h + s.charCodeAt(i)) % 997;
  return avatarPalettes[h % avatarPalettes.length];
}

const roleLabels = (user) => {
  const r = user.roles;
  if (!r || !r.length) return "—";
  return r.map((x) => x.label || x.name).join(", ");
};

const statusBadgeClass = (status) => {
  const s = String(status || "").toLowerCase();
  if (s === "active") {
    return "bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300";
  }
  if (s === "pending") {
    return "bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200";
  }
  if (s === "inactive") {
    return "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300";
  }
  return "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300";
};

const filteredUsers = computed(() => {
  let rows = summary.value.recent_users ?? [];
  const q = search.value.trim().toLowerCase();
  if (q) {
    rows = rows.filter(
      (r) =>
        (r.name && r.name.toLowerCase().includes(q)) ||
        (r.email && r.email.toLowerCase().includes(q)),
    );
  }
  if (statusFilter.value) {
    rows = rows.filter((r) => r.status === statusFilter.value);
  }
  return rows;
});

const manageMenuUser = computed(() =>
  filteredUsers.value.find((u) => u.id === manageOpenId.value) ?? null,
);

const chartBundle = computed(() => {
  const c = summary.value.chart;
  const yt = summary.value.year_totals ?? {};
  if (!c?.labels?.length) {
    return { labels: [], series: [] };
  }

  if (period.value === "monthly") {
    return {
      labels: c.labels,
      series: [
        { name: "Activity", data: c.activity ?? [] },
        { name: "New users", data: c.new_users ?? [] },
      ],
    };
  }

  if (period.value === "quarterly") {
    const sumQ = (arr) => {
      const a = arr ?? [];
      return [
        (a[0] ?? 0) + (a[1] ?? 0) + (a[2] ?? 0),
        (a[3] ?? 0) + (a[4] ?? 0) + (a[5] ?? 0),
        (a[6] ?? 0) + (a[7] ?? 0) + (a[8] ?? 0),
        (a[9] ?? 0) + (a[10] ?? 0) + (a[11] ?? 0),
      ];
    };
    return {
      labels: ["Q1", "Q2", "Q3", "Q4"],
      series: [
        { name: "Activity", data: sumQ(c.activity) },
        { name: "New users", data: sumQ(c.new_users) },
      ],
    };
  }

  const y = new Date().getFullYear();
  return {
    labels: [String(y - 1), String(y)],
    series: [
      {
        name: "Activity",
        data: [yt.activity_last_year ?? 0, yt.activity_this_year ?? 0],
      },
      {
        name: "New users",
        data: [yt.users_last_year ?? 0, yt.users_this_year ?? 0],
      },
    ],
  };
});

const areaChartOptions = computed(() => ({
  chart: {
    type: "area",
    fontFamily: "inherit",
    toolbar: { show: false },
    zoom: { enabled: false },
  },
  dataLabels: { enabled: false },
  stroke: { curve: "smooth", width: [2, 2] },
  fill: {
    type: "gradient",
    gradient: {
      shadeIntensity: 1,
      opacityFrom: 0.45,
      opacityTo: 0.05,
    },
  },
  colors: ["#2563eb", "#1d4ed8"],
  xaxis: {
    categories: chartBundle.value.labels,
    axisBorder: { show: false },
    axisTicks: { show: false },
    labels: {
      style: { colors: "#6B7280", fontSize: "12px" },
    },
  },
  yaxis: {
    labels: {
      style: { colors: "#6B7280", fontSize: "12px" },
    },
  },
  grid: {
    borderColor: "#E5E7EB",
    strokeDashArray: 4,
    xaxis: { lines: { show: false } },
  },
  legend: {
    position: "top",
    horizontalAlign: "right",
    fontSize: "12px",
    markers: { radius: 10 },
  },
  tooltip: {
    theme: "light",
    x: { show: true },
  },
}));

const donutSeries = computed(() => {
  const u = summary.value.users_by_status ?? {};
  return [u.pending ?? 0, u.active ?? 0, u.inactive ?? 0];
});

const donutTotal = computed(() =>
  donutSeries.value.reduce((a, b) => a + b, 0),
);

const donutOptions = computed(() => ({
  chart: { fontFamily: "inherit" },
  labels: ["Pending", "Active", "Inactive"],
  colors: ["#93C5FD", "#2563eb", "#0F172A"],
  plotOptions: {
    pie: {
      donut: {
        size: "72%",
        labels: {
          show: true,
          name: { show: true, fontSize: "14px" },
          value: { show: true, fontSize: "22px", fontWeight: 600 },
          total: {
            show: true,
            showAlways: true,
            label: "Total",
            formatter: () => nf.format(donutTotal.value),
          },
        },
      },
    },
  },
  stroke: { width: 0 },
  dataLabels: { enabled: false },
  legend: { show: false },
}));

const radialOptions = computed(() => ({
  chart: { fontFamily: "inherit", sparkline: { enabled: true } },
  plotOptions: {
    radialBar: {
      hollow: { size: "62%" },
      track: { background: "#E5E7EB" },
      dataLabels: {
        name: { show: false },
        value: {
          fontSize: "28px",
          fontWeight: 700,
          color: "#111827",
          formatter: (val) => `${val}%`,
        },
      },
    },
  },
  colors: ["#2563eb"],
  labels: ["Engagement"],
}));

const avgActivityMonthly = computed(() => {
  const a = summary.value.chart?.activity ?? [];
  if (!a.length) return { line1: "—", line2: "—" };
  const sum = a.reduce((x, y) => x + y, 0);
  const avg = sum / a.length;
  const last = a[a.length - 1] ?? 0;
  const prev = a[a.length - 2] ?? 0;
  const d = prev ? (((last - prev) / prev) * 100).toFixed(1) : 0;
  return {
    line1: nf.format(Math.round(avg)),
    line2: `${Number(d) >= 0 ? "+" : ""}${d}%`,
  };
});

const activityTrendBarPct = computed(() => {
  const a = summary.value.chart?.activity ?? [];
  const t = a.reduce((x, y) => x + y, 0);
  const m = Math.max(...a, 1);
  const last = a.length ? a[a.length - 1] : 0;
  if (!t || !m) return 0;
  return Math.min(100, (last / m) * 100);
});

const MENU_W = 176;
const MENU_H = 112;

function placeManageMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const r = anchorEl.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
  if (top + MENU_H > window.innerHeight - 8) {
    top = Math.max(8, r.top - MENU_H - 4);
  }
  manageMenuRect.value = { top, left };
}

function closeManageMenu() {
  manageOpenId.value = null;
}

function openUserEditModal(user) {
  userEditModalUserId.value = String(user.id);
  userEditModalOpen.value = true;
  closeManageMenu();
}

async function refreshDashboardSummary() {
  try {
    const { data } = await api.get("/dashboard/summary");
    summary.value = { ...summary.value, ...data };
  } catch {
    /* ignore */
  }
}

function onWindowScrollOrResize() {
  if (manageOpenId.value !== null) {
    closeManageMenu();
  }
}

function toggleManageMenu(userId, e) {
  e.stopPropagation();
  if (manageOpenId.value === userId) {
    closeManageMenu();
    return;
  }
  manageOpenId.value = userId;
  const btn = e.currentTarget;
  if (btn instanceof HTMLElement) {
    placeManageMenu(btn);
  }
}

function onDocClick(e) {
  if (!e.target.closest("[data-row-actions]")) {
    manageOpenId.value = null;
  }
  if (!e.target.closest("[data-recent-filter]")) {
    recentUsersFilterOpen.value = false;
  }
}

const fetchMe = async () => {
  try {
    const { data } = await api.get("/auth/me");
    currentUser.value = data;
  } catch {
    currentUser.value = null;
  }
};

const canDeleteRow = (user) => {
  if (!canDeleteUsers.value) return false;
  return !(currentUser.value && user.id === currentUser.value.id);
};

const openDeleteModal = (user) => {
  manageOpenId.value = null;
  deleteError.value = "";
  deleteTarget.value = user;
};

const closeDeleteModal = () => {
  if (deleteBusy.value) return;
  deleteTarget.value = null;
};

const confirmDelete = async () => {
  const user = deleteTarget.value;
  if (!user) return;
  deleteBusy.value = true;
  deleteError.value = "";
  try {
    await api.delete(`/users/${user.id}`);
    deleteTarget.value = null;
    toast.success("User deleted.");
    const { data } = await api.get("/dashboard/summary");
    summary.value = { ...summary.value, ...data };
  } catch (e) {
    deleteError.value = errorMessage(e, "Could not delete user.");
    toast.errorFrom(e, "Could not delete user.");
  } finally {
    deleteBusy.value = false;
  }
};

onMounted(async () => {
  loading.value = true;
  try {
    await fetchMe();
    const { data } = await api.get("/dashboard/summary");
    summary.value = { ...summary.value, ...data };
  } finally {
    loading.value = false;
  }
  document.addEventListener("click", onDocClick);
  window.addEventListener("scroll", onWindowScrollOrResize, true);
  window.addEventListener("resize", onWindowScrollOrResize);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  window.removeEventListener("scroll", onWindowScrollOrResize, true);
  window.removeEventListener("resize", onWindowScrollOrResize);
});
</script>

<template>
  <div class="space-y-6">
    <div
      v-if="loading"
      class="flex justify-center rounded-2xl border border-gray-200 bg-white p-10 dark:border-gray-800 dark:bg-white/[0.03]"
    >
      <CrmLoadingSpinner message="Loading dashboard…" />
    </div>

    <template v-else>
      <!-- Top metrics -->
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <CrmMetricCard
          label="Total users"
          :value="nf.format(summary.metrics.total_users.value)"
          :change-pct="summary.metrics.total_users.change_pct"
          period-label="From last month"
        />
        <CrmMetricCard
          label="Active users"
          :value="nf.format(summary.metrics.active_users.value)"
          :change-pct="summary.metrics.active_users.change_pct"
          period-label="New active accounts (MoM)"
        />
        <CrmMetricCard
          label="Activities today"
          :value="nf.format(summary.metrics.activities_today.value)"
          :change-pct="summary.metrics.activities_today.change_pct"
          period-label="vs yesterday"
        />
      </div>

      <!-- Statistics + radial -->
      <div class="grid grid-cols-1 gap-4 xl:grid-cols-12">
        <div
          class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03] xl:col-span-8"
        >
          <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
          >
            <div>
              <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                Statistics
              </h2>
              <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                Activity and new registrations by period
              </p>
              <div class="mt-4 flex flex-wrap gap-6">
                <div>
                  <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                    Avg. monthly activity
                  </p>
                  <p class="mt-1 text-xl font-bold text-gray-900 dark:text-white">
                    {{ avgActivityMonthly.line1 }}
                    <span
                      class="ml-2 inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400"
                    >
                      {{ avgActivityMonthly.line2 }} vs prior
                    </span>
                  </p>
                </div>
                <div>
                  <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                    Latest month vs prev.
                  </p>
                  <p class="mt-1 text-xl font-bold text-gray-900 dark:text-white">
                    {{
                      nf.format(
                        summary.chart.activity?.at(-1) ?? 0,
                      )
                    }}
                    <span
                      class="ml-2 text-sm font-normal text-gray-500 dark:text-gray-400"
                    >
                      activity
                    </span>
                  </p>
                </div>
              </div>
            </div>
            <div
              class="inline-flex rounded-lg border border-gray-200 p-0.5 dark:border-gray-700"
            >
              <button
                type="button"
                class="rounded-md px-3 py-1.5 text-xs font-semibold transition"
                :class="
                  period === 'monthly'
                    ? 'bg-[#2563eb] text-white shadow-sm'
                    : 'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/5'
                "
                @click="period = 'monthly'"
              >
                Monthly
              </button>
              <button
                type="button"
                class="rounded-md px-3 py-1.5 text-xs font-semibold transition"
                :class="
                  period === 'quarterly'
                    ? 'bg-[#2563eb] text-white shadow-sm'
                    : 'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/5'
                "
                @click="period = 'quarterly'"
              >
                Quarterly
              </button>
              <button
                type="button"
                class="rounded-md px-3 py-1.5 text-xs font-semibold transition"
                :class="
                  period === 'annually'
                    ? 'bg-[#2563eb] text-white shadow-sm'
                    : 'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/5'
                "
                @click="period = 'annually'"
              >
                Annually
              </button>
            </div>
          </div>
          <div class="mt-2 min-h-[320px]">
            <VueApexCharts
              type="area"
              height="320"
              :options="areaChartOptions"
              :series="chartBundle.series"
            />
          </div>
        </div>

        <div
          class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03] xl:col-span-4"
        >
          <div class="flex items-start justify-between">
            <div>
              <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                Engagement
              </h2>
              <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                Today’s activity vs active accounts
              </p>
            </div>
            <button
              type="button"
              class="rounded-lg p-1 text-gray-400 hover:bg-gray-100 dark:hover:bg-white/10"
              aria-label="Menu"
            >
              <span class="inline-block rotate-90 font-bold leading-none">⋯</span>
            </button>
          </div>
          <div class="flex justify-center py-2">
            <VueApexCharts
              type="radialBar"
              height="260"
              :options="radialOptions"
              :series="[summary.engagement_score]"
            />
          </div>
          <p
            class="text-center text-xs font-medium text-gray-500 dark:text-gray-400"
          >
            This month goals
          </p>
          <div class="mt-4 space-y-3 border-t border-gray-100 pt-4 dark:border-gray-800">
            <div>
              <div class="flex justify-between text-xs font-medium">
                <span class="text-gray-600 dark:text-gray-300">Active users</span>
                <span class="text-gray-900 dark:text-white">{{
                  nf.format(summary.metrics.active_users.value)
                }}</span>
              </div>
              <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                <div
                  class="h-full rounded-full bg-[#2563eb]"
                  :style="{
                    width: `${
                      summary.metrics.total_users.value
                        ? Math.min(
                            100,
                            (summary.metrics.active_users.value /
                              summary.metrics.total_users.value) *
                              100,
                          )
                        : 0
                    }%`,
                  }"
                />
              </div>
            </div>
            <div>
              <div class="flex justify-between text-xs font-medium">
                <span class="text-gray-600 dark:text-gray-300">Activity (30d)</span>
                <span class="text-gray-900 dark:text-white">{{
                  nf.format(
                    (summary.chart.activity ?? []).reduce(
                      (a, b) => a + b,
                      0,
                    ),
                  )
                }}</span>
              </div>
              <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                <div
                  class="h-full rounded-full bg-sky-400"
                  :style="{ width: `${activityTrendBarPct}%` }"
                />
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Donut + schedule -->
      <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div
          class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]"
        >
          <div class="flex items-start justify-between">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
              Users by status
            </h2>
            <button
              type="button"
              class="rounded-lg p-1 text-gray-400 hover:bg-gray-100 dark:hover:bg-white/10"
              aria-label="Menu"
            >
              <span class="inline-block rotate-90 font-bold leading-none">⋯</span>
            </button>
          </div>
          <div class="mt-2 flex flex-col items-stretch gap-4 md:flex-row">
            <div class="min-w-0 flex-1 md:max-w-[55%]">
              <VueApexCharts
                type="donut"
                height="320"
                :options="donutOptions"
                :series="donutSeries"
              />
            </div>
            <ul
              class="flex flex-1 flex-col justify-center gap-3 text-sm dark:text-gray-300"
            >
              <li
                v-for="(slice, idx) in [
                  {
                    key: 'Pending',
                    pct:
                      donutTotal > 0
                        ? Math.round(
                            (donutSeries[0] / donutTotal) * 100,
                          )
                        : 0,
                    count: donutSeries[0],
                    dot: 'bg-sky-300',
                  },
                  {
                    key: 'Active',
                    pct:
                      donutTotal > 0
                        ? Math.round(
                            (donutSeries[1] / donutTotal) * 100,
                          )
                        : 0,
                    count: donutSeries[1],
                    dot: 'bg-[#2563eb]',
                  },
                  {
                    key: 'Inactive',
                    pct:
                      donutTotal > 0
                        ? Math.round(
                            (donutSeries[2] / donutTotal) * 100,
                          )
                        : 0,
                    count: donutSeries[2],
                    dot: 'bg-slate-800',
                  },
                ]"
                :key="slice.key"
                class="flex gap-3"
              >
                <span
                  class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full"
                  :class="slice.dot"
                />
                <div>
                  <p class="font-medium text-gray-900 dark:text-white">
                    {{ slice.key }}
                  </p>
                  <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ slice.pct }}% • {{ nf.format(slice.count) }} accounts
                  </p>
                </div>
              </li>
            </ul>
          </div>
        </div>

        <div
          class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]"
        >
          <div class="flex items-start justify-between">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
              Recent activity
            </h2>
            <button
              type="button"
              class="rounded-lg p-1 text-gray-400 hover:bg-gray-100 dark:hover:bg-white/10"
              aria-label="Menu"
            >
              <span class="inline-block rotate-90 font-bold leading-none">⋯</span>
            </button>
          </div>
          <ul class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
            <li
              v-for="item in summary.recent_activity"
              :key="item.id"
              class="flex gap-3 py-3 first:pt-0"
            >
              <span
                class="mt-1 flex h-4 w-4 shrink-0 rounded border border-gray-300 dark:border-gray-600"
              />
              <div class="min-w-0 flex-1">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                  {{ splitWhen(item.at).date }}
                  <span class="text-gray-800 dark:text-gray-200">
                    {{ splitWhen(item.at).time }}
                  </span>
                </p>
                <p class="mt-0.5 font-semibold text-gray-900 dark:text-white">
                  {{ item.title }}
                </p>
                <p
                  v-if="item.description"
                  class="mt-0.5 line-clamp-2 text-xs text-gray-500 dark:text-gray-400"
                >
                  {{ item.description }}
                </p>
              </div>
            </li>
          </ul>
        </div>
      </div>

      <p v-if="deleteError" class="text-sm text-red-600 dark:text-red-400">
        {{ deleteError }}
      </p>

      <!-- Recent users: outer card + inner bordered table (TailAdmin BasicTables pattern) -->
      <div
        class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900"
      >
        <div class="px-4 py-5 sm:px-6">
          <div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">
              Recent staff
            </h2>
            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
              Latest accounts in the directory
            </p>
          </div>
        </div>

        <div
          class="border-t border-gray-100 px-4 py-4 dark:border-gray-800 sm:px-6 sm:pb-6"
        >
          <div
            class="mb-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between"
          >
            <div class="relative min-w-0 max-w-md flex-1">
              <span
                class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"
              >
                <svg
                  class="h-5 w-5"
                  fill="none"
                  viewBox="0 0 20 20"
                  stroke="currentColor"
                  stroke-width="1.5"
                >
                  <path
                    stroke-linecap="round"
                    d="M3.042 9.374c0-3.497 2.835-6.332 6.333-6.332 3.497 0 6.332 2.835 6.332 6.332 0 3.498-2.835 6.333-6.332 6.333-3.498 0-6.333-2.835-6.333-6.333zM17.208 17.205l-2.82-2.82"
                  />
                </svg>
              </span>
              <input
                v-model="search"
                type="search"
                placeholder="Search…"
                class="h-11 w-full rounded-lg border border-gray-200 bg-gray-50 py-2.5 pl-10 pr-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-[#2563eb] focus:outline-none focus:ring-2 focus:ring-[#2563eb]/20 dark:border-gray-700 dark:bg-gray-800/50 dark:text-white dark:placeholder:text-gray-500"
              />
            </div>
            <div
              class="relative flex shrink-0 items-center"
              data-recent-filter
            >
              <button
                type="button"
                class="inline-flex h-11 items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                :class="{ 'ring-2 ring-[#2563eb]/30': recentUsersFilterOpen }"
                :aria-expanded="recentUsersFilterOpen"
                @click.stop="recentUsersFilterOpen = !recentUsersFilterOpen"
              >
                <svg
                  class="h-5 w-5 text-gray-500"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                  stroke-width="2"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"
                  />
                </svg>
                Filter
              </button>
              <Transition
                enter-active-class="transition ease-out duration-100"
                enter-from-class="transform opacity-0 scale-95"
                enter-to-class="transform opacity-100 scale-100"
                leave-active-class="transition ease-in duration-75"
                leave-from-class="transform opacity-100 scale-100"
                leave-to-class="transform opacity-0 scale-95"
              >
                <div
                  v-if="recentUsersFilterOpen"
                  class="absolute right-0 top-full z-30 mt-2 w-64 origin-top-right rounded-xl border border-gray-200 bg-white p-4 shadow-lg dark:border-gray-700 dark:bg-gray-900"
                  @click.stop
                >
                  <label
                    class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                    >Status</label
                  >
                  <select
                    v-model="statusFilter"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    @change="recentUsersFilterOpen = false"
                  >
                    <option value="">All statuses</option>
                    <option value="pending">Pending</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                </div>
              </Transition>
            </div>
          </div>
          <div
            class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]"
          >
            <div class="overflow-x-auto">
              <table class="min-w-[1024px] w-full text-left text-sm">
            <thead>
              <tr
                class="border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/50"
              >
                <th class="px-5 py-3 text-left sm:px-6">
                  <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                    Status
                  </p>
                </th>
                <th class="px-5 py-3 text-left sm:px-6">
                  <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                    User
                  </p>
                </th>
                <th class="px-5 py-3 text-left sm:px-6">
                  <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                    Position
                  </p>
                </th>
                <th class="px-5 py-3 text-left sm:px-6">
                  <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                    Birthday
                  </p>
                </th>
                <th class="px-5 py-3 text-left sm:px-6">
                  <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                    Hire date
                  </p>
                </th>
                <th class="px-5 py-3 text-left sm:px-6">
                  <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                    Role
                  </p>
                </th>
                <th
                  v-if="showRowActions"
                  class="w-[4.5rem] min-w-[4.75rem] px-5 py-3 text-right sm:px-6"
                >
                  <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                    Action
                  </p>
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
              <tr
                v-for="row in filteredUsers"
                :key="row.id"
                class="border-t border-gray-100 bg-white hover:bg-gray-50/80 dark:border-gray-800 dark:bg-transparent dark:hover:bg-white/[0.02]"
              >
                <td class="px-5 py-4 align-middle sm:px-6">
                  <span
                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize"
                    :class="statusBadgeClass(row.status)"
                  >
                    {{ row.status }}
                  </span>
                </td>
                <td class="px-5 py-4 align-middle sm:px-6">
                  <div class="flex items-center gap-3">
                    <span class="relative h-10 w-10 shrink-0">
                      <img
                        v-if="row.avatar_url"
                        :src="resolvePublicUrl(row.avatar_url)"
                        alt=""
                        class="h-10 w-10 rounded-full object-cover"
                      />
                      <span
                        v-else
                        class="flex h-10 w-10 items-center justify-center rounded-full text-xs font-semibold"
                        :class="avatarClassForUser(row.email)"
                      >
                        {{ initials(row.name) }}
                      </span>
                    </span>
                    <div class="min-w-0">
                      <RouterLink
                        :to="`/staff/${row.id}`"
                        class="block truncate font-semibold text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400"
                      >
                        {{ row.name }}
                      </RouterLink>
                      <RouterLink
                        :to="`/staff/${row.id}`"
                        class="mt-0.5 block truncate text-xs text-gray-500 hover:text-blue-600 dark:text-gray-400"
                      >
                        {{ row.email }}
                      </RouterLink>
                    </div>
                  </div>
                </td>
                <td
                  class="max-w-[11rem] truncate px-5 py-4 align-middle text-gray-700 sm:px-6 dark:text-gray-300"
                  :title="row.job_position || undefined"
                >
                  {{ row.job_position || "—" }}
                </td>
                <td
                  class="whitespace-nowrap px-5 py-4 align-middle text-gray-700 sm:px-6 dark:text-gray-300"
                >
                  {{ formatBirthdayUs(row.birthday) }}
                </td>
                <td
                  class="whitespace-nowrap px-5 py-4 align-middle text-gray-700 sm:px-6 dark:text-gray-300"
                >
                  {{ formatIsoDate(row.hire_date) }}
                </td>
                <td
                  class="px-5 py-4 align-middle text-gray-700 sm:px-6 dark:text-gray-300"
                >
                  {{ roleLabels(row) }}
                </td>
                <td
                  v-if="showRowActions"
                  class="relative px-5 py-4 text-right align-middle sm:px-6"
                >
                  <div data-row-actions class="relative inline-flex justify-end">
                    <button
                      type="button"
                      class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:border-gray-300 hover:bg-gray-50 hover:text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-500 dark:hover:bg-white/10 dark:hover:text-white"
                      :aria-expanded="manageOpenId === row.id"
                      aria-haspopup="true"
                      aria-label="Row actions"
                      @click="toggleManageMenu(row.id, $event)"
                    >
                      <CrmIconRowActions />
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="filteredUsers.length === 0">
                <td
                  :colspan="tableColspan"
                  class="px-5 py-12 text-center text-gray-500 dark:text-gray-400 sm:px-6"
                >
                  No users match your filters.
                </td>
              </tr>
            </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <UserEditModal
        v-model:open="userEditModalOpen"
        :user-id="userEditModalUserId"
        @saved="refreshDashboardSummary"
      />

      <ConfirmModal
        :open="deleteModalOpen"
        title="Delete user"
        :message="deleteMessage"
        confirm-label="Delete"
        cancel-label="Cancel"
        :busy="deleteBusy"
        @close="closeDeleteModal"
        @confirm="confirmDelete"
      />

      <Teleport to="body">
        <Transition
          enter-active-class="transition ease-out duration-100"
          enter-from-class="transform opacity-0 scale-95"
          enter-to-class="transform opacity-100 scale-100"
          leave-active-class="transition ease-in duration-75"
          leave-from-class="transform opacity-100 scale-100"
          leave-to-class="transform opacity-0 scale-95"
        >
          <div
            v-if="manageMenuUser"
            data-row-actions
            class="fixed z-[300] w-44 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg ring-1 ring-black/5 dark:border-gray-700 dark:bg-gray-900 dark:ring-white/10"
            role="menu"
            :style="{
              top: `${manageMenuRect.top}px`,
              left: `${manageMenuRect.left}px`,
            }"
            @click.stop
          >
            <button
              v-if="canUpdateUsers"
              type="button"
              class="flex w-full items-center px-4 py-2.5 text-left text-sm font-medium text-gray-800 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
              role="menuitem"
              @click="openUserEditModal(manageMenuUser)"
            >
              Edit
            </button>
            <button
              v-if="canDeleteRow(manageMenuUser)"
              type="button"
              :class="[
                'flex w-full items-center px-4 py-2.5 text-left text-sm font-medium text-red-600 transition hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/25',
                canUpdateUsers
                  ? 'border-t border-gray-100 dark:border-gray-800'
                  : '',
              ]"
              role="menuitem"
              @click="openDeleteModal(manageMenuUser)"
            >
              Delete
            </button>
          </div>
        </Transition>
      </Teleport>
    </template>
  </div>
</template>
