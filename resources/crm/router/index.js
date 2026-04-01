import { createRouter, createWebHistory } from "vue-router";
import api from "../services/api";

import LoginPage from "../pages/auth/LoginPage.vue";
import ForgotPasswordPage from "../pages/auth/ForgotPasswordPage.vue";
import ResetPasswordPage from "../pages/auth/ResetPasswordPage.vue";
import DashboardPage from "../pages/DashboardPage.vue";
import UsersListPage from "../pages/users/UsersListPage.vue";
import UserFormPage from "../pages/users/UserFormPage.vue";
import TicketsListPage from "../pages/tickets/TicketsListPage.vue";
import TicketsBoardPage from "../pages/tickets/TicketsBoardPage.vue";
import TicketDetailPage from "../pages/tickets/TicketDetailPage.vue";
import WebmasterTasksPage from "../pages/webmaster/WebmasterTasksPage.vue";

const routes = [
  { path: "/login", name: "login", component: LoginPage, meta: { public: true } },
  {
    path: "/forgot-password",
    name: "forgot-password",
    component: ForgotPasswordPage,
    meta: { public: true },
  },
  {
    path: "/reset-password",
    name: "reset-password",
    component: ResetPasswordPage,
    meta: { public: true },
  },
  { path: "/", redirect: "/dashboard" },
  { path: "/dashboard", name: "dashboard", component: DashboardPage },
  { path: "/users", name: "users", component: UsersListPage },
  { path: "/users/create", name: "users-create", component: UserFormPage },
  { path: "/users/new", redirect: "/users/create" },
  { path: "/users/:id/edit", name: "users-edit", component: UserFormPage, props: true },
  { path: "/tickets/board", name: "tickets-board", component: TicketsBoardPage },
  { path: "/tickets/:id", name: "ticket-detail", component: TicketDetailPage, props: true },
  { path: "/tickets", name: "tickets", component: TicketsListPage },
  { path: "/webmaster", name: "webmaster", component: WebmasterTasksPage },
];

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
});

/** Cached result of whether /auth/me allows ticket routes (tickets.view permission or legacy CRM owner). */
let ticketNavCache = null;

/** Cached result of whether /auth/me allows Webmaster routes (webmaster.view or CRM owner). */
let webmasterNavCache = null;

export function clearCrmOwnerCache() {
  ticketNavCache = null;
  webmasterNavCache = null;
}

/**
 * @param {boolean|null} value — legacy: true/false from old is_crm_owner-only checks
 */
export function setCrmOwnerCache(value) {
  ticketNavCache = value === null ? null : !!value;
}

/** Prefer this after login: derives access from permission_keys (tickets.view). */
export function setTicketNavFromUser(user) {
  if (!user) {
    ticketNavCache = null;
    return;
  }
  const keys = user.permission_keys;
  ticketNavCache = Array.isArray(keys) && keys.includes("tickets.view");
}

export function setWebmasterNavFromUser(user) {
  if (!user) {
    webmasterNavCache = null;
    return;
  }
  const keys = user.permission_keys;
  const perm =
    Array.isArray(keys) && keys.includes("webmaster.view");
  webmasterNavCache = perm || !!user.is_crm_owner;
}

function userCanWebmaster(userLike) {
  if (!userLike) return false;
  if (userLike.is_crm_owner) return true;
  const keys = userLike.permission_keys;
  return Array.isArray(keys) && keys.includes("webmaster.view");
}

function userCanTickets(userLike) {
  if (!userLike || !Array.isArray(userLike.permission_keys)) {
    return false;
  }
  return userLike.permission_keys.includes("tickets.view");
}

async function ensureTicketRouteAccess() {
  if (ticketNavCache !== null) {
    return ticketNavCache;
  }
  try {
    const { data } = await api.get("/auth/me");
    const ok = userCanTickets(data);
    ticketNavCache = ok;
    return ok;
  } catch (e) {
    if (e.response?.status === 401) {
      localStorage.removeItem("auth_token");
      ticketNavCache = null;
    } else {
      ticketNavCache = false;
    }
    return false;
  }
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

  if (to.path === "/tickets" || to.path.startsWith("/tickets/")) {
    const ok = await ensureTicketRouteAccess();
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/dashboard" };
    }
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

  return true;
});

export default router;
