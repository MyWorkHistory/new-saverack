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
import WebmasterTasksPage from "../pages/webmaster/WebmasterTasksPage.vue";
import WebmasterTaskDetailPage from "../pages/webmaster/WebmasterTaskDetailPage.vue";

const meta = {
  login: {
    title: "SaveRack | Sign in",
    description: "Sign in to SaveRack CRM.",
  },
  forgot: {
    title: "SaveRack | Forgot password",
    description: "Reset your SaveRack CRM password.",
  },
  reset: {
    title: "SaveRack | Reset password",
    description: "Choose a new password for SaveRack CRM.",
  },
  dashboard: {
    title: "SaveRack | Dashboard",
    description: "CRM dashboard — activity, metrics, and recent staff.",
  },
  staff: {
    title: "SaveRack | Staff",
    description: "Directory of admin and staff accounts.",
  },
  staffCreate: {
    title: "SaveRack | Add staff",
    description: "Create a new staff account.",
  },
  webmaster: {
    title: "SaveRack | Webmaster",
    description: "Site development tasks and board.",
  },
  webmasterTask: {
    title: "SaveRack | Webmaster task",
    description: "Webmaster task details.",
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
    path: "/staff/:id",
    name: "staff-detail",
    component: UserDetailPage,
    props: true,
    meta: {
      title: "SaveRack | Staff",
      description: "Staff profile.",
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
