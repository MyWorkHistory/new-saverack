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
import StaffRoleIcon from "../components/users/StaffRoleIcon.vue";
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
    ? `Are You Sure You Want To Delete ${u.name}? This Cannot Be Undone.`
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
const orderMetrics = ref({
  ready_to_ship_total: 0,
  late_orders_total: 0,
  priority_orders_total: 0,
});
const DASHBOARD_ORDER_METRICS_CACHE_KEY = "dashboard.orderMetrics.v1";

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
  "bg-info-subtle text-info-emphasis",
  "bg-primary-subtle text-primary-emphasis",
  "bg-warning-subtle text-warning-emphasis",
  "bg-success-subtle text-success",
  "bg-danger-subtle text-danger",
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
    return "bg-success-subtle text-success";
  }
  if (s === "pending") {
    return "bg-warning-subtle text-warning-emphasis";
  }
  if (s === "inactive") {
    return "bg-body-secondary text-body-secondary";
  }
  return "bg-body-tertiary text-body-secondary";
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
        { name: "New Users", data: c.new_users ?? [] },
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
        { name: "New Users", data: sumQ(c.new_users) },
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
  colors: ["#7367F0", "#00BAD1"],
  xaxis: {
    categories: chartBundle.value.labels,
    axisBorder: { show: false },
    axisTicks: { show: false },
    labels: {
      style: { colors: "#808390", fontSize: "12px" },
    },
  },
  yaxis: {
    labels: {
      style: { colors: "#808390", fontSize: "12px" },
    },
  },
  grid: {
    borderColor: "rgba(47, 43, 61, 0.08)",
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

const donutLegendSlices = computed(() => {
  const colors = ["#B8B2FF", "#7367F0", "#2F2B3D"];
  const keys = ["Pending", "Active", "Inactive"];
  const series = donutSeries.value;
  const total = donutTotal.value;
  return keys.map((key, idx) => ({
    key,
    pct: total > 0 ? Math.round(((series[idx] ?? 0) / total) * 100) : 0,
    count: series[idx] ?? 0,
    color: colors[idx],
  }));
});

const donutOptions = computed(() => ({
  chart: { fontFamily: "inherit" },
  labels: ["Pending", "Active", "Inactive"],
  colors: ["#B8B2FF", "#7367F0", "#2F2B3D"],
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
      track: { background: "rgba(47, 43, 61, 0.08)" },
      dataLabels: {
        name: { show: false },
        value: {
          fontSize: "28px",
          fontWeight: 700,
          color: "#2F2B3D",
          formatter: (val) => `${val}%`,
        },
      },
    },
  },
  colors: ["#7367F0"],
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
    const now = new Date();
    const to = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
    const fromDate = new Date(now);
    fromDate.setDate(fromDate.getDate() - 29);
    const from = `${fromDate.getFullYear()}-${String(fromDate.getMonth() + 1).padStart(2, "0")}-${String(fromDate.getDate()).padStart(2, "0")}`;
    const [dashboardRes, summaryRes] = await Promise.all([
      api.get("/dashboard/summary"),
      api.get("/orders/summary", {
        params: { order_date_from: from, order_date_to: to },
      }),
    ]);
    summary.value = { ...summary.value, ...dashboardRes.data };
    orderMetrics.value = {
      ready_to_ship_total: Number(summaryRes?.data?.ready_to_ship_total || 0),
      late_orders_total: Number(summaryRes?.data?.late_orders_total || 0),
      priority_orders_total: Number(summaryRes?.data?.priority_orders_total || 0),
    };
    try {
      sessionStorage.setItem(
        DASHBOARD_ORDER_METRICS_CACHE_KEY,
        JSON.stringify(orderMetrics.value),
      );
    } catch (_) {
      // no-op
    }
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
    toast.success("User Deleted.");
    const { data } = await api.get("/dashboard/summary");
    summary.value = { ...summary.value, ...data };
  } catch (e) {
    deleteError.value = errorMessage(e, "Could not delete user.");
    toast.errorFrom(e, "Could Not Delete User.");
  } finally {
    deleteBusy.value = false;
  }
};

onMounted(async () => {
  loading.value = true;
  try {
    try {
      const cached = sessionStorage.getItem(DASHBOARD_ORDER_METRICS_CACHE_KEY);
      if (cached) {
        const parsed = JSON.parse(cached);
        orderMetrics.value = {
          ready_to_ship_total: Number(parsed?.ready_to_ship_total || 0),
          late_orders_total: Number(parsed?.late_orders_total || 0),
          priority_orders_total: Number(parsed?.priority_orders_total || 0),
        };
      }
    } catch (_) {
      // no-op
    }
    await fetchMe();
    const now = new Date();
    const to = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
    const fromDate = new Date(now);
    fromDate.setDate(fromDate.getDate() - 29);
    const from = `${fromDate.getFullYear()}-${String(fromDate.getMonth() + 1).padStart(2, "0")}-${String(fromDate.getDate()).padStart(2, "0")}`;
    const [dashboardRes, summaryRes] = await Promise.all([
      api.get("/dashboard/summary"),
      api.get("/orders/summary", {
        params: { order_date_from: from, order_date_to: to },
      }),
    ]);
    summary.value = { ...summary.value, ...dashboardRes.data };
    orderMetrics.value = {
      ready_to_ship_total: Number(summaryRes?.data?.ready_to_ship_total || 0),
      late_orders_total: Number(summaryRes?.data?.late_orders_total || 0),
      priority_orders_total: Number(summaryRes?.data?.priority_orders_total || 0),
    };
    try {
      sessionStorage.setItem(
        DASHBOARD_ORDER_METRICS_CACHE_KEY,
        JSON.stringify(orderMetrics.value),
      );
    } catch (_) {
      // no-op
    }
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
  <div class="vx-dashboard">
    <div
      v-if="loading"
      class="vx-card p-5 d-flex justify-content-center"
    >
      <CrmLoadingSpinner message="Loading Dashboard…" />
    </div>

    <template v-else>
      <div class="row g-4 mb-4">
        <div class="col-12 col-sm-6 col-xl-4">
          <CrmMetricCard
            label="Ready to Ship"
            :value="nf.format(orderMetrics.ready_to_ship_total)"
            :change-pct="null"
            period-label="Total Orders"
            :show-change="false"
          />
        </div>
        <div class="col-12 col-sm-6 col-xl-4">
          <CrmMetricCard
            label="Late Orders"
            :value="nf.format(orderMetrics.late_orders_total)"
            :change-pct="null"
            period-label="Total Orders"
            :show-change="false"
          />
        </div>
        <div class="col-12 col-sm-6 col-xl-4">
          <CrmMetricCard
            label="Priority Orders"
            :value="nf.format(orderMetrics.priority_orders_total)"
            :change-pct="null"
            period-label="Total Orders"
            :show-change="false"
          />
        </div>
      </div>

      <!-- Statistics + radial -->
      <div class="row g-4 mb-4">
        <div class="col-12 col-xl-8">
          <div class="vx-card p-4 h-100">
            <div
              class="d-flex flex-column flex-sm-row align-items-start justify-content-between gap-3"
            >
              <div class="flex-grow-1 min-w-0">
                <h2 class="vx-card-title">Statistics</h2>
                <p class="vx-card-sub">
                  Activity And New Registrations By Period
                </p>
                <div class="d-flex flex-wrap gap-4 mt-3">
                  <div>
                    <p class="small text-body-secondary fw-medium mb-1">
                      Avg. monthly activity
                    </p>
                    <p class="fs-5 fw-bold text-body mb-0">
                      {{ avgActivityMonthly.line1 }}
                      <span
                        class="badge bg-success-subtle text-success ms-2 align-middle"
                      >
                        {{ avgActivityMonthly.line2 }} Vs Prior
                      </span>
                    </p>
                  </div>
                  <div>
                    <p class="small text-body-secondary fw-medium mb-1">
                      Latest Month Vs Prev.
                    </p>
                    <p class="fs-5 fw-bold text-body mb-0">
                      {{
                        nf.format(summary.chart.activity?.at(-1) ?? 0)
                      }}
                      <span class="small fw-normal text-body-secondary ms-2">
                        Activity
                      </span>
                    </p>
                  </div>
                </div>
              </div>
              <div class="vx-period-pill flex-shrink-0">
                <button
                  type="button"
                  :class="{ active: period === 'monthly' }"
                  @click="period = 'monthly'"
                >
                  Monthly
                </button>
                <button
                  type="button"
                  :class="{ active: period === 'quarterly' }"
                  @click="period = 'quarterly'"
                >
                  Quarterly
                </button>
                <button
                  type="button"
                  :class="{ active: period === 'annually' }"
                  @click="period = 'annually'"
                >
                  Annually
                </button>
              </div>
            </div>
            <div class="mt-3" style="min-height: 320px">
              <VueApexCharts
                type="area"
                height="320"
                :options="areaChartOptions"
                :series="chartBundle.series"
              />
            </div>
          </div>
        </div>

        <div class="col-12 col-xl-4">
          <div class="vx-card p-4 h-100">
            <div class="vx-card-header-row">
              <div>
                <h2 class="vx-card-title">Engagement</h2>
                <p class="vx-card-sub">
                  Today’s Activity Vs Active Accounts
                </p>
              </div>
              <button
                type="button"
                class="btn btn-sm btn-link text-secondary p-1"
                aria-label="Menu"
              >
                <span class="d-inline-block user-select-none fs-5 lh-1"
                  >⋯</span
                >
              </button>
            </div>
            <div class="d-flex justify-content-center py-2">
              <VueApexCharts
                type="radialBar"
                height="260"
                :options="radialOptions"
                :series="[summary.engagement_score]"
              />
            </div>
            <p class="text-center small fw-medium text-body-secondary mb-0">
              This Month’s Goals
            </p>
            <div class="mt-4 pt-4 border-top border-opacity-10">
              <div class="mb-3">
                <div
                  class="d-flex justify-content-between small fw-medium text-body-secondary"
                >
                  <span>Active Users</span>
                  <span class="text-body">{{
                    nf.format(summary.metrics.active_users.value)
                  }}</span>
                </div>
                <div
                  class="vx-progress-thin mt-2 overflow-hidden rounded-pill"
                >
                  <div
                    class="vx-progress-bar--primary h-100 rounded-pill"
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
                <div
                  class="d-flex justify-content-between small fw-medium text-body-secondary"
                >
                  <span>Activity (30D)</span>
                  <span class="text-body">{{
                    nf.format(
                      (summary.chart.activity ?? []).reduce(
                        (a, b) => a + b,
                        0,
                      ),
                    )
                  }}</span>
                </div>
                <div
                  class="vx-progress-thin mt-2 overflow-hidden rounded-pill"
                >
                  <div
                    class="vx-progress-bar--teal h-100 rounded-pill"
                    :style="{ width: `${activityTrendBarPct}%` }"
                  />
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Donut + recent activity -->
      <div class="row g-4 mb-4">
        <div class="col-12 col-lg-6">
          <div class="vx-card p-4 h-100">
            <div class="vx-card-header-row">
              <h2 class="vx-card-title mb-0">Users By Status</h2>
              <button
                type="button"
                class="btn btn-sm btn-link text-secondary p-1"
                aria-label="Menu"
              >
                <span class="d-inline-block user-select-none fs-5 lh-1"
                  >⋯</span
                >
              </button>
            </div>
            <div
              class="mt-2 d-flex flex-column flex-md-row align-items-stretch gap-4"
            >
              <div class="min-w-0 flex-grow-1" style="max-width: 55%">
                <VueApexCharts
                  type="donut"
                  height="320"
                  :options="donutOptions"
                  :series="donutSeries"
                />
              </div>
              <ul
                class="list-unstyled mb-0 d-flex flex-column justify-content-center gap-3 flex-grow-1 small"
              >
                <li
                  v-for="slice in donutLegendSlices"
                  :key="slice.key"
                  class="d-flex gap-3"
                >
                  <span
                    class="mt-1 flex-shrink-0 rounded-circle"
                    style="width: 0.625rem; height: 0.625rem"
                    :style="{ backgroundColor: slice.color }"
                  />
                  <div class="min-w-0">
                    <p class="fw-medium text-body mb-0">{{ slice.key }}</p>
                    <p class="text-body-secondary small mb-0">
                      {{ slice.pct }}% • {{ nf.format(slice.count) }} Accounts
                    </p>
                  </div>
                </li>
              </ul>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-6">
          <div class="vx-card p-4 h-100">
            <div class="vx-card-header-row">
              <h2 class="vx-card-title mb-0">Recent Activity</h2>
              <button
                type="button"
                class="btn btn-sm btn-link text-secondary p-1"
                aria-label="Menu"
              >
                <span class="d-inline-block user-select-none fs-5 lh-1"
                  >⋯</span
                >
              </button>
            </div>
            <ul class="list-unstyled mb-0 mt-3">
              <li
                v-for="item in summary.recent_activity"
                :key="item.id"
                class="d-flex gap-3 py-3 border-bottom border-opacity-10"
              >
                <span
                  class="mt-1 flex-shrink-0 rounded border"
                  style="width: 1rem; height: 1rem"
                />
                <div class="min-w-0 flex-grow-1">
                  <p class="small fw-medium text-body-secondary mb-0">
                    {{ splitWhen(item.at).date }}
                    <span class="text-body">{{ splitWhen(item.at).time }}</span>
                  </p>
                  <p class="fw-semibold text-body mt-1 mb-0">
                    {{ item.title }}
                  </p>
                  <p
                    v-if="item.description"
                    class="small text-body-secondary mt-1 mb-0 vx-line-clamp-2"
                  >
                    {{ item.description }}
                  </p>
                </div>
              </li>
            </ul>
          </div>
        </div>
      </div>

      <p v-if="deleteError" class="small text-danger">
        {{ deleteError }}
      </p>

      <div class="vx-card overflow-hidden">
        <div class="border-bottom border-opacity-10 px-4 py-4 px-sm-5">
          <div
            class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3"
          >
            <div class="d-flex align-items-center gap-3">
              <div>
                <h2 class="h5 fw-bold text-body mb-1">Recent Staff</h2>
                <p class="small text-body-secondary mb-0">
                  Latest Accounts In The Directory
                </p>
              </div>
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary border-0"
                :disabled="loading"
                title="Refresh"
                aria-label="Refresh List"
                @click="refreshDashboardSummary"
              >
                <svg
                  width="20"
                  height="20"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                  />
                </svg>
              </button>
            </div>

            <div
              class="position-relative d-flex flex-shrink-0 align-items-center gap-2 justify-content-sm-end"
              data-recent-filter
            >
              <button
                type="button"
                class="btn btn-outline-secondary d-inline-flex align-items-center gap-2"
                :class="{ 'border-primary': recentUsersFilterOpen }"
                :aria-expanded="recentUsersFilterOpen"
                @click.stop="recentUsersFilterOpen = !recentUsersFilterOpen"
              >
                <svg
                  width="20"
                  height="20"
                  class="text-secondary"
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
              <Transition name="vx-fade-scale">
                <div
                  v-if="recentUsersFilterOpen"
                  class="position-absolute end-0 card shadow border-0 mt-2 p-3"
                  style="top: 100%; z-index: 30; width: 18rem"
                  @click.stop
                >
                  <div class="d-grid gap-3">
                    <div>
                      <label class="form-label small text-body-secondary mb-1"
                        >Status</label
                      >
                      <select
                        v-model="statusFilter"
                        class="form-select form-select-sm"
                      >
                        <option value="">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                      </select>
                    </div>
                    <div class="d-flex gap-2 pt-1">
                      <button
                        type="button"
                        class="btn btn-primary btn-sm flex-fill"
                        @click="recentUsersFilterOpen = false"
                      >
                        Apply
                      </button>
                      <button
                        type="button"
                        class="btn btn-outline-secondary btn-sm flex-fill"
                        @click="
                          statusFilter = '';
                          recentUsersFilterOpen = false;
                        "
                      >
                        Clear
                      </button>
                    </div>
                  </div>
                </div>
              </Transition>
            </div>
          </div>
        </div>

        <div class="px-4 py-4 px-sm-5 pb-sm-5">
          <div class="vx-card overflow-hidden p-0">
            <div class="border-bottom px-4 py-3 px-sm-4 bg-body">
              <div style="max-width: 28rem">
                <div class="input-group input-group-merge rounded-2">
                  <span class="input-group-text border-end-0 bg-body">
                    <svg
                      width="18"
                      height="18"
                      fill="none"
                      viewBox="0 0 20 20"
                      stroke="currentColor"
                      stroke-width="1.5"
                      class="text-secondary"
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
                    class="form-control border-start-0"
                    autocomplete="off"
                  />
                </div>
              </div>
            </div>

            <div class="table-responsive">
              <table
                class="table table-hover align-middle mb-0 small"
                style="min-width: 1024px"
              >
                <thead class="table-light">
                  <tr>
                    <th class="px-4 py-3 text-secondary fw-medium small">
                      Status
                    </th>
                    <th class="px-4 py-3 text-secondary fw-medium small">
                      User
                    </th>
                    <th class="px-4 py-3 text-secondary fw-medium small">
                      Position
                    </th>
                    <th class="px-4 py-3 text-secondary fw-medium small">
                      Birthday
                    </th>
                    <th class="px-4 py-3 text-secondary fw-medium small">
                      Hire Date
                    </th>
                    <th class="px-4 py-3 text-secondary fw-medium small">
                      Role
                    </th>
                    <th
                      v-if="showRowActions"
                      class="px-4 py-3 text-secondary fw-medium small text-end"
                      style="width: 4.75rem"
                    >
                      Action
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in filteredUsers" :key="row.id">
                    <td class="px-4 py-3">
                      <span
                        class="badge rounded-pill text-capitalize fw-medium"
                        :class="statusBadgeClass(row.status)"
                      >
                        {{ row.status }}
                      </span>
                    </td>
                    <td class="px-4 py-3">
                      <div class="d-flex align-items-center gap-3">
                        <span
                          class="position-relative flex-shrink-0 rounded-circle overflow-hidden bg-body-secondary"
                          style="width: 2.5rem; height: 2.5rem"
                        >
                          <img
                            v-if="row.avatar_url"
                            :src="resolvePublicUrl(row.avatar_url)"
                            alt=""
                            class="w-100 h-100 object-fit-cover rounded-circle"
                          />
                          <span
                            v-else
                            class="d-flex w-100 h-100 align-items-center justify-content-center small fw-semibold"
                            :class="avatarClassForUser(row.email)"
                          >
                            {{ initials(row.name) }}
                          </span>
                        </span>
                        <div class="min-w-0">
                          <RouterLink
                            :to="`/staff/${row.id}`"
                            class="d-block text-truncate fw-semibold text-body text-decoration-none"
                          >
                            {{ row.name }}
                          </RouterLink>
                          <RouterLink
                            :to="`/staff/${row.id}`"
                            class="d-block text-truncate small text-secondary text-decoration-none"
                          >
                            {{ row.email }}
                          </RouterLink>
                        </div>
                      </div>
                    </td>
                    <td
                      class="px-4 py-3 text-body-secondary text-truncate"
                      style="max-width: 11rem"
                      :title="row.job_position || undefined"
                    >
                      {{ row.job_position || "—" }}
                    </td>
                    <td class="px-4 py-3 text-body-secondary text-nowrap">
                      {{ formatBirthdayUs(row.birthday) }}
                    </td>
                  <td class="px-4 py-3 text-body-secondary text-nowrap">
                    {{ formatIsoDate(row.hire_date) }}
                  </td>
                  <td class="px-4 py-3 text-body-secondary">
                    <div class="d-flex align-items-center gap-2 min-w-0">
                      <StaffRoleIcon :roles="row.roles" />
                      <span class="text-truncate">{{ roleLabels(row) }}</span>
                    </div>
                  </td>
                  <td
                    v-if="showRowActions"
                    class="px-4 py-3 text-end position-relative"
                  >
                    <div
                      data-row-actions
                      class="position-relative d-inline-flex justify-content-end"
                    >
                      <button
                        type="button"
                        class="btn btn-outline-secondary btn-sm p-0 d-inline-flex align-items-center justify-content-center"
                        style="width: 2.5rem; height: 2.5rem"
                        :aria-expanded="manageOpenId === row.id"
                        aria-haspopup="true"
                        aria-label="Row Actions"
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
                    class="px-4 py-5 text-center text-secondary"
                  >
                    No Users Match Your Filters.
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
        title="Delete User"
        subtitle="This action is permanent and may be audited."
        :message="deleteMessage"
        confirm-label="Delete"
        cancel-label="Cancel"
        :busy="deleteBusy"
        @close="closeDeleteModal"
        @confirm="confirmDelete"
      />

      <Teleport to="body">
        <Transition name="vx-fade-scale">
          <div
            v-if="manageMenuUser"
            data-row-actions
            class="position-fixed overflow-hidden rounded-3 border bg-body py-1 shadow-lg"
            role="menu"
            :style="{
              top: `${manageMenuRect.top}px`,
              left: `${manageMenuRect.left}px`,
              zIndex: 300,
              width: '11rem',
            }"
            @click.stop
          >
            <button
              v-if="canUpdateUsers"
              type="button"
              class="btn btn-link w-100 text-start text-body text-decoration-none rounded-0 py-2 px-3 small fw-medium"
              role="menuitem"
              @click="openUserEditModal(manageMenuUser)"
            >
              Edit
            </button>
            <button
              v-if="canDeleteRow(manageMenuUser)"
              type="button"
              :class="[
                'btn btn-link w-100 text-start text-danger text-decoration-none rounded-0 py-2 px-3 small fw-medium',
                canUpdateUsers ? 'border-top' : '',
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
