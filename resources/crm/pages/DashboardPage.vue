<script setup>
import { computed, onMounted, ref } from "vue";
import { RouterLink } from "vue-router";
import VueApexCharts from "vue3-apexcharts";
import api from "../services/api";
import CrmMetricCard from "../components/dashboard/CrmMetricCard.vue";
import CrmLoadingSpinner from "../components/common/CrmLoadingSpinner.vue";

const loading = ref(true);
const period = ref("monthly");
const search = ref("");
const statusFilter = ref("");

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
  "bg-sky-100 text-sky-700 ring-sky-200",
  "bg-violet-100 text-violet-700 ring-violet-200",
  "bg-amber-100 text-amber-800 ring-amber-200",
  "bg-emerald-100 text-emerald-800 ring-emerald-200",
  "bg-rose-100 text-rose-800 ring-rose-200",
  "bg-indigo-100 text-indigo-800 ring-indigo-200",
];

function initials(name) {
  if (!name || typeof name !== "string") return "?";
  const parts = name.trim().split(/\s+/).slice(0, 2);
  return parts.map((p) => p[0]?.toUpperCase() ?? "").join("") || "?";
}

function avatarClass(email) {
  let h = 0;
  const s = email || "";
  for (let i = 0; i < s.length; i++) h = (h + s.charCodeAt(i)) % 997;
  return avatarPalettes[h % avatarPalettes.length];
}

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
  colors: ["#206ba4", "#38BDF8"],
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
  colors: ["#93C5FD", "#206ba4", "#0F172A"],
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
  colors: ["#206ba4"],
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

onMounted(async () => {
  try {
    const { data } = await api.get("/dashboard/summary");
    summary.value = { ...summary.value, ...data };
  } finally {
    loading.value = false;
  }
});

function statusBadgeClass(s) {
  if (s === "active") {
    return "bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400";
  }
  if (s === "pending") {
    return "bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400";
  }
  return "bg-gray-100 text-gray-700 ring-gray-600/10 dark:bg-white/10 dark:text-gray-300";
}
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
                    ? 'bg-[#206ba4] text-white shadow-sm'
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
                    ? 'bg-[#206ba4] text-white shadow-sm'
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
                    ? 'bg-[#206ba4] text-white shadow-sm'
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
                  class="h-full rounded-full bg-[#206ba4]"
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
                    dot: 'bg-[#206ba4]',
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

      <!-- Recent users table -->
      <div
        class="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-white/[0.03]"
      >
        <div
          class="flex flex-col gap-4 border-b border-gray-100 p-5 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between"
        >
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
            Recent users
          </h2>
          <div class="flex flex-wrap items-center gap-2">
            <div class="relative">
              <span
                class="pointer-events-none absolute left-3 top-1/2 text-gray-400 -translate-y-1/2"
              >
                ⌕
              </span>
              <input
                v-model="search"
                type="search"
                placeholder="Search…"
                class="w-full min-w-[200px] rounded-lg border border-gray-200 bg-white py-2 pl-9 pr-3 text-sm outline-none ring-[#206ba4] focus:border-[#206ba4] focus:ring-2 focus:ring-[#206ba4]/20 dark:border-gray-700 dark:bg-white/5 dark:text-white sm:w-56"
              />
            </div>
            <select
              v-model="statusFilter"
              class="rounded-lg border border-gray-200 bg-white py-2 pl-3 pr-8 text-sm outline-none focus:border-[#206ba4] dark:border-gray-700 dark:bg-white/5 dark:text-white"
            >
              <option value="">All status</option>
              <option value="active">Active</option>
              <option value="pending">Pending</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full text-left text-sm">
            <thead class="bg-gray-50 text-xs font-semibold uppercase text-gray-500 dark:bg-white/[0.05] dark:text-gray-400">
              <tr>
                <th class="w-10 px-5 py-3">
                  <span class="inline-block h-4 w-4 rounded border border-gray-300" />
                </th>
                <th class="px-3 py-3">User</th>
                <th class="hidden px-3 py-3 md:table-cell">Email</th>
                <th class="px-3 py-3">Status</th>
                <th class="px-3 py-3">Joined</th>
                <th class="w-14 px-3 py-3 text-right" />
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <tr
                v-for="row in filteredUsers"
                :key="row.id"
                class="hover:bg-gray-50/80 dark:hover:bg-white/[0.03]"
              >
                <td class="px-5 py-3 align-middle">
                  <span
                    class="inline-block h-4 w-4 rounded border border-gray-300 dark:border-gray-600"
                  />
                </td>
                <td class="px-3 py-3 align-middle">
                  <div class="flex items-center gap-3">
                    <span
                      class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xs font-bold ring-2 ring-inset"
                      :class="avatarClass(row.email)"
                    >
                      {{ initials(row.name) }}
                    </span>
                    <div class="min-w-0">
                      <p class="font-semibold text-gray-900 dark:text-white">
                        {{ row.name }}
                      </p>
                      <p class="truncate text-xs text-gray-500 md:hidden dark:text-gray-400">
                        {{ row.email }}
                      </p>
                    </div>
                  </div>
                </td>
                <td
                  class="hidden max-w-[220px] truncate px-3 py-3 align-middle text-gray-600 dark:text-gray-300 md:table-cell"
                >
                  {{ row.email }}
                </td>
                <td class="px-3 py-3 align-middle">
                  <span
                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset"
                    :class="statusBadgeClass(row.status)"
                  >
                    {{
                      row.status
                        ? row.status.charAt(0).toUpperCase() + row.status.slice(1)
                        : "—"
                    }}
                  </span>
                </td>
                <td
                  class="whitespace-nowrap px-3 py-3 align-middle text-gray-600 dark:text-gray-300"
                >
                  {{
                    row.created_at
                      ? new Date(row.created_at).toISOString().slice(0, 10)
                      : "—"
                  }}
                </td>
                <td class="px-3 py-3 text-right align-middle">
                  <RouterLink
                    :to="`/users/${row.id}/edit`"
                    class="inline-flex rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-[#206ba4] dark:hover:bg-white/10"
                    title="Edit user"
                  >
                    ✎
                  </RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
          <p
            v-if="!filteredUsers.length"
            class="p-8 text-center text-sm text-gray-500"
          >
            No users match your filters.
          </p>
        </div>
      </div>
    </template>
  </div>
</template>
