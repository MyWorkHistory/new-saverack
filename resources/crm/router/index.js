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
];

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
});

let crmOwnerCache = null;

export function clearCrmOwnerCache() {
  crmOwnerCache = null;
}

export function setCrmOwnerCache(value) {
  crmOwnerCache = value === null ? null : !!value;
}

async function ensureCrmOwner() {
  if (crmOwnerCache !== null) {
    return crmOwnerCache;
  }
  try {
    const { data } = await api.get("/auth/me");
    crmOwnerCache = !!data.is_crm_owner;
    return crmOwnerCache;
  } catch (e) {
    if (e.response?.status === 401) {
      localStorage.removeItem("auth_token");
      crmOwnerCache = null;
    } else {
      crmOwnerCache = false;
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
    const ok = await ensureCrmOwner();
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
