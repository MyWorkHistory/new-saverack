import { createRouter, createWebHistory } from "vue-router";
import api from "../services/api";
import { crmIsAdmin } from "../utils/crmUser";
import { applyRouteMeta } from "../composables/useCrmPageMeta.js";

import LoginPage from "../pages/auth/LoginPage.vue";
import ForgotPasswordPage from "../pages/auth/ForgotPasswordPage.vue";
import ResetPasswordPage from "../pages/auth/ResetPasswordPage.vue";
import DashboardPage from "../pages/DashboardPage.vue";
import UsersListPage from "../pages/users/UsersListPage.vue";
import UserFormPage from "../pages/users/UserFormPage.vue";
import UserDetailPage from "../pages/users/UserDetailPage.vue";
import UserPermissionsPage from "../pages/users/UserPermissionsPage.vue";
import UserHistoryPage from "../pages/users/UserHistoryPage.vue";
import WebmasterTasksPage from "../pages/webmaster/WebmasterTasksPage.vue";
import WebmasterTaskDetailPage from "../pages/webmaster/WebmasterTaskDetailPage.vue";

const meta = {
  login: {
    title: "Save Rack | Sign In",
    description: "Sign In To Save Rack CRM.",
  },
  forgot: {
    title: "Save Rack | Forgot Password",
    description: "Reset Your Save Rack CRM Password.",
  },
  reset: {
    title: "Save Rack | Reset Password",
    description: "Choose A New Password For Save Rack CRM.",
  },
  dashboard: {
    title: "Save Rack | Dashboard",
    description: "CRM Dashboard — Activity, Metrics, And Recent Staff.",
  },
  staff: {
    title: "Save Rack | Staff",
    description: "Directory Of Admin And Staff Accounts.",
  },
  staffCreate: {
    title: "Save Rack | Add Staff",
    description: "Create A New Staff Account.",
  },
  webmaster: {
    title: "Save Rack | Webmaster",
    description: "Site Development Tasks And Board.",
  },
  webmasterTask: {
    title: "Save Rack | Webmaster Task",
    description: "Webmaster Task Details.",
  },
};

const routes = [
  {
    path: "/login",
    name: "login",
    component: LoginPage,
    meta: { public: true, ...meta.login },
  },
  {
    path: "/forgot-password",
    name: "forgot-password",
    component: ForgotPasswordPage,
    meta: { public: true, ...meta.forgot },
  },
  {
    path: "/reset-password",
    name: "reset-password",
    component: ResetPasswordPage,
    meta: { public: true, ...meta.reset },
  },
  { path: "/", redirect: "/dashboard" },
  {
    path: "/dashboard",
    name: "dashboard",
    component: DashboardPage,
    meta: meta.dashboard,
  },
  { path: "/staff", name: "staff", component: UsersListPage, meta: meta.staff },
  {
    path: "/staff/create",
    name: "staff-create",
    component: UserFormPage,
    meta: meta.staffCreate,
  },
  { path: "/staff/new", redirect: "/staff/create" },
  {
    path: "/staff/:id/edit",
    name: "staff-edit",
    redirect: (to) => ({
      path: `/staff/${to.params.id}`,
      query: { edit: "1" },
    }),
  },
  {
    path: "/staff/:id/permissions",
    name: "staff-permissions",
    component: UserPermissionsPage,
    props: true,
    meta: {
      title: "Save Rack | User Permissions",
      description: "Manage User Module Permissions.",
    },
  },
  {
    path: "/staff/:id/history",
    name: "staff-history",
    component: UserHistoryPage,
    props: true,
    meta: {
      title: "Save Rack | Staff History",
      description: "Staff Profile Change History.",
    },
  },
  {
    path: "/staff/:id",
    name: "staff-detail",
    component: UserDetailPage,
    props: true,
    meta: {
      title: "Save Rack | Staff",
      description: "Staff Profile.",
    },
  },
  { path: "/users/create", redirect: "/staff/create" },
  { path: "/users/new", redirect: "/staff/create" },
  {
    path: "/users/:id/edit",
    redirect: (to) => ({
      path: `/staff/${to.params.id}`,
      query: { edit: "1" },
    }),
  },
  { path: "/users", redirect: "/staff" },
  {
    path: "/users/:id/permissions",
    redirect: (to) => `/staff/${to.params.id}/permissions`,
  },
  { path: "/users/:id", redirect: (to) => `/staff/${to.params.id}` },
  {
    path: "/webmaster",
    name: "webmaster",
    component: WebmasterTasksPage,
    meta: meta.webmaster,
  },
  {
    path: "/webmaster/tasks/:id",
    name: "webmaster-task-detail",
    component: WebmasterTaskDetailPage,
    props: true,
    meta: meta.webmasterTask,
  },
  { path: "/tickets/board", redirect: "/dashboard" },
  { path: "/tickets/:id", redirect: "/dashboard" },
  { path: "/tickets", redirect: "/dashboard" },
];

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
});

let webmasterNavCache = null;

/** Users module: per-action permissions from /auth/me (see setUsersNavFromUser). */
let usersNavCache = null;

export function clearCrmOwnerCache() {
  webmasterNavCache = null;
  usersNavCache = null;
}

export function setWebmasterNavFromUser(user) {
  if (!user) {
    webmasterNavCache = null;
    return;
  }
  const keys = user.permission_keys;
  const perm = Array.isArray(keys) && keys.includes("webmaster.view");
  webmasterNavCache = perm || !!user.is_crm_owner || crmIsAdmin(user);
}

export function setUsersNavFromUser(user) {
  if (!user) {
    usersNavCache = null;
    return;
  }
  if (crmIsAdmin(user) || user.is_crm_owner) {
    usersNavCache = {
      view: true,
      create: true,
      update: true,
      delete: true,
    };
    return;
  }
  const k = Array.isArray(user.permission_keys) ? user.permission_keys : [];
  usersNavCache = {
    view: k.includes("users.view"),
    create: k.includes("users.create"),
    update: k.includes("users.update"),
    delete: k.includes("users.delete"),
  };
}

async function ensureUsersRouteAccess(path) {
  if (usersNavCache === null) {
    try {
      const { data } = await api.get("/auth/me");
      setUsersNavFromUser(data);
      setWebmasterNavFromUser(data);
    } catch (e) {
      if (e.response?.status === 401) {
        localStorage.removeItem("auth_token");
        usersNavCache = null;
      }
      return false;
    }
  }
  const staffCreate =
    path === "/staff/create" ||
    path === "/staff/new" ||
    path === "/users/create" ||
    path === "/users/new";
  if (staffCreate) {
    return usersNavCache.create === true;
  }
  if (/^\/staff\/[^/]+\/edit$/.test(path) || /^\/users\/[^/]+\/edit$/.test(path)) {
    return usersNavCache.update === true;
  }
  if (
    /^\/staff\/[^/]+\/permissions$/.test(path) ||
    /^\/users\/[^/]+\/permissions$/.test(path)
  ) {
    return usersNavCache.update === true;
  }
  if (
    /^\/staff\/[^/]+\/history$/.test(path) ||
    /^\/users\/[^/]+\/history$/.test(path)
  ) {
    return usersNavCache.view === true;
  }
  if (
    path === "/staff" ||
    path.startsWith("/staff/") ||
    path === "/users" ||
    path.startsWith("/users/")
  ) {
    return usersNavCache.view === true;
  }
  return true;
}

function userCanWebmaster(userLike) {
  if (!userLike) return false;
  if (userLike.is_crm_owner || crmIsAdmin(userLike)) return true;
  const keys = userLike.permission_keys;
  return Array.isArray(keys) && keys.includes("webmaster.view");
}

async function ensureWebmasterRouteAccess() {
  if (webmasterNavCache !== null) {
    return webmasterNavCache;
  }
  try {
    const { data } = await api.get("/auth/me");
    const ok = userCanWebmaster(data);
    webmasterNavCache = ok;
    return ok;
  } catch (e) {
    if (e.response?.status === 401) {
      localStorage.removeItem("auth_token");
      webmasterNavCache = null;
    } else {
      webmasterNavCache = false;
    }
    return false;
  }
}

router.beforeEach(async (to) => {
  const token = localStorage.getItem("auth_token");

  if (to.meta.public) {
    if (token && to.name === "login") {
      return { path: "/dashboard" };
    }
    return true;
  }

  if (!token) {
    return { name: "login", query: { redirect: to.fullPath } };
  }

  if (to.path === "/webmaster" || to.path.startsWith("/webmaster/")) {
    const ok = await ensureWebmasterRouteAccess();
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/dashboard" };
    }
  }

  if (to.path.startsWith("/users") || to.path.startsWith("/staff")) {
    const ok = await ensureUsersRouteAccess(to.path);
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/dashboard" };
    }
  }

  return true;
});

router.afterEach((to) => {
  applyRouteMeta(to);
});

export default router;
