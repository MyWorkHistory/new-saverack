import { createRouter, createWebHistory } from "vue-router";
import api from "../services/api";
import { crmIsAdmin } from "../utils/crmUser";
import { applyRouteMeta } from "../composables/useCrmPageMeta.js";
import {
  getPublicSignupUrl,
  isRootSpaBundle,
} from "../utils/publicSignupUrl.js";

import LoginPage from "../pages/auth/LoginPage.vue";
import CreateAccountPage from "../pages/auth/CreateAccountPage.vue";
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
import ClientAccountsListPage from "../pages/clients/ClientAccountsListPage.vue";
import ClientAccountDetailPage from "../pages/clients/ClientAccountDetailPage.vue";
import ClientAccountUsersListPage from "../pages/clients/ClientAccountUsersListPage.vue";
import ClientAccountUserDetailPage from "../pages/clients/ClientAccountUserDetailPage.vue";
import BillingSummaryPage from "../pages/billing/BillingSummaryPage.vue";
import BillingInvoicesListPage from "../pages/billing/BillingInvoicesListPage.vue";
import BillingInvoiceDetailPage from "../pages/billing/BillingInvoiceDetailPage.vue";
import InventoryPage from "../pages/inventory/InventoryPage.vue";
import InventoryOnDemandPage from "../pages/inventory/InventoryOnDemandPage.vue";
import OrdersListPage from "../pages/orders/OrdersListPage.vue";
import OrderDetailPage from "../pages/orders/OrderDetailPage.vue";
import OrderDetailIframePage from "../pages/orders/OrderDetailIframePage.vue";

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
  createAccount: {
    title: "Save Rack | Create Account",
    description: "Create Your Save Rack 3PL Client Account.",
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
  clientAccounts: {
    title: "Save Rack | Accounts",
    description: "Accounts directory.",
  },
  clientAccountDetail: {
    title: "Save Rack | Account",
    description: "Client account profile.",
  },
  clientUsers: {
    title: "Save Rack | Client users",
    description: "Portal logins for client accounts.",
  },
  clientAccountUserDetail: {
    title: "Save Rack | Client user",
    description: "Portal user profile.",
  },
  billingSummary: {
    title: "Save Rack | Billing Summary",
    description: "Billing overview.",
  },
  billingInvoices: {
    title: "Save Rack | Invoices",
    description: "Client invoices.",
  },
  billingInvoiceDetail: {
    title: "Save Rack | Invoice",
    description: "Invoice detail.",
  },
  inventory: {
    title: "Save Rack | Inventory",
    description: "ShipHero live inventory.",
  },
  inventoryOnDemand: {
    title: "Save Rack | On-Demand Inventory",
    description: "Account On-Demand SKU catalog.",
  },
  ordersManage: {
    title: "Save Rack | Orders | Manage",
    description: "ShipHero orders management.",
  },
  ordersAwaiting: {
    title: "Save Rack | Orders | Awaiting Shipment",
    description: "ShipHero orders awaiting shipment.",
  },
  ordersOnHold: {
    title: "Save Rack | Orders | On-Hold",
    description: "ShipHero on-hold orders.",
  },
  ordersShipped: {
    title: "Save Rack | Orders | Shipped",
    description: "ShipHero shipped orders.",
  },
  orderDetail: {
    title: "Save Rack | Order",
    description: "ShipHero order detail.",
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
    path: "/create",
    name: "create-account",
    component: CreateAccountPage,
    meta: { public: true, ...meta.createAccount },
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
    redirect: (to) => `/staff/${to.params.id}`,
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
    redirect: (to) => `/staff/${to.params.id}`,
  },
  { path: "/users", redirect: "/staff" },
  {
    path: "/users/:id/permissions",
    redirect: (to) => `/staff/${to.params.id}/permissions`,
  },
  { path: "/users/:id", redirect: (to) => `/staff/${to.params.id}` },
  {
    path: "/clients/accounts",
    name: "client-accounts",
    component: ClientAccountsListPage,
    meta: meta.clientAccounts,
  },
  {
    path: "/clients/accounts/:id",
    name: "client-account-detail",
    component: ClientAccountDetailPage,
    props: true,
    meta: meta.clientAccountDetail,
  },
  {
    path: "/clients/users",
    name: "client-users",
    component: ClientAccountUsersListPage,
    meta: meta.clientUsers,
  },
  {
    path: "/clients/users/:accountId/:userId",
    name: "client-account-user-detail",
    component: ClientAccountUserDetailPage,
    props: true,
    meta: meta.clientAccountUserDetail,
  },
  { path: "/clients", redirect: "/clients/accounts" },
  {
    path: "/billing/summary",
    name: "billing-summary",
    component: BillingSummaryPage,
    meta: meta.billingSummary,
  },
  {
    path: "/billing/invoices",
    name: "billing-invoices",
    component: BillingInvoicesListPage,
    meta: meta.billingInvoices,
  },
  {
    path: "/billing/invoices/:id",
    name: "billing-invoice-detail",
    component: BillingInvoiceDetailPage,
    props: true,
    meta: meta.billingInvoiceDetail,
  },
  { path: "/billing", redirect: "/billing/summary" },
  {
    path: "/inventory",
    name: "inventory",
    component: InventoryPage,
    meta: meta.inventory,
  },
  {
    path: "/inventory/on-demand",
    name: "inventory-on-demand",
    component: InventoryOnDemandPage,
    meta: meta.inventoryOnDemand,
  },
  {
    path: "/orders/manage",
    name: "orders-manage",
    component: OrdersListPage,
    meta: { ...meta.ordersManage, orderTab: "manage" },
  },
  {
    path: "/orders/awaiting",
    name: "orders-awaiting",
    component: OrdersListPage,
    meta: { ...meta.ordersAwaiting, orderTab: "awaiting" },
  },
  {
    path: "/orders/on-hold",
    name: "orders-on-hold",
    component: OrdersListPage,
    meta: { ...meta.ordersOnHold, orderTab: "on_hold" },
  },
  {
    path: "/orders/shipped",
    name: "orders-shipped",
    component: OrdersListPage,
    meta: { ...meta.ordersShipped, orderTab: "shipped" },
  },
  {
    path: "/orders/:shipheroOrderId",
    name: "order-detail",
    component: OrderDetailPage,
    props: true,
    meta: meta.orderDetail,
  },
  {
    path: "/orders/:accountSlug/:orderNumber",
    name: "order-detail-iframe",
    component: OrderDetailIframePage,
    props: true,
    meta: meta.orderDetail,
  },
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

/** Clients module: per-action permissions from /auth/me (see setClientsNavFromUser). */
let clientsNavCache = null;

/** Billing module: permissions from /auth/me (see setBillingNavFromUser). */
let billingNavCache = null;

/** Inventory (ShipHero): permissions from /auth/me (see setInventoryNavFromUser). */
let inventoryNavCache = null;

/** True when the signed-in CRM user is an administrator (`crmIsAdmin`); used for permissions routes only. */
let usersMeIsAdmin = false;

export function clearCrmOwnerCache() {
  webmasterNavCache = null;
  usersNavCache = null;
  clientsNavCache = null;
  billingNavCache = null;
  inventoryNavCache = null;
  usersMeIsAdmin = false;
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
    usersMeIsAdmin = false;
    return;
  }
  usersMeIsAdmin = crmIsAdmin(user);
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

export function setClientsNavFromUser(user) {
  if (!user) {
    clientsNavCache = null;
    return;
  }
  if (crmIsAdmin(user) || user.is_crm_owner) {
    clientsNavCache = {
      view: true,
      create: true,
      update: true,
      delete: true,
    };
    return;
  }
  const k = Array.isArray(user.permission_keys) ? user.permission_keys : [];
  const canSeeClientsSection =
    k.includes("clients.view") || k.includes("client_users.view");
  clientsNavCache = {
    view: canSeeClientsSection,
    create: k.includes("clients.create"),
    update: k.includes("clients.update"),
    delete: k.includes("clients.delete"),
  };
}

export function setBillingNavFromUser(user) {
  if (!user) {
    billingNavCache = null;
    return;
  }
  if (crmIsAdmin(user) || user.is_crm_owner) {
    billingNavCache = {
      view: true,
      create: true,
      update: true,
      delete: true,
    };
    return;
  }
  const k = Array.isArray(user.permission_keys) ? user.permission_keys : [];
  billingNavCache = {
    view: k.includes("billing.view"),
    create: k.includes("billing.create"),
    update: k.includes("billing.update"),
    delete: k.includes("billing.delete"),
  };
}

export function setInventoryNavFromUser(user) {
  if (!user) {
    inventoryNavCache = null;
    return;
  }
  if (crmIsAdmin(user) || user.is_crm_owner) {
    inventoryNavCache = { view: true, update: true };
    return;
  }
  const k = Array.isArray(user.permission_keys) ? user.permission_keys : [];
  inventoryNavCache = {
    view: k.includes("inventory.view"),
    update: k.includes("inventory.update"),
  };
}

async function ensureClientsRouteAccess(path) {
  if (clientsNavCache === null) {
    try {
      const { data } = await api.get("/auth/me");
      setUsersNavFromUser(data);
      setClientsNavFromUser(data);
      setWebmasterNavFromUser(data);
      setBillingNavFromUser(data);
      setInventoryNavFromUser(data);
    } catch (e) {
      if (e.response?.status === 401) {
        localStorage.removeItem("auth_token");
        clientsNavCache = null;
        billingNavCache = null;
        inventoryNavCache = null;
      }
      return false;
    }
  }
  if (path === "/clients" || path.startsWith("/clients/")) {
    return clientsNavCache.view === true;
  }
  return true;
}

async function ensureUsersRouteAccess(path) {
  if (usersNavCache === null) {
    try {
      const { data } = await api.get("/auth/me");
      setUsersNavFromUser(data);
      setClientsNavFromUser(data);
      setWebmasterNavFromUser(data);
      setBillingNavFromUser(data);
      setInventoryNavFromUser(data);
    } catch (e) {
      if (e.response?.status === 401) {
        localStorage.removeItem("auth_token");
        usersNavCache = null;
        billingNavCache = null;
        inventoryNavCache = null;
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
    return usersMeIsAdmin === true;
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
    setUsersNavFromUser(data);
    setClientsNavFromUser(data);
    setWebmasterNavFromUser(data);
    setBillingNavFromUser(data);
    setInventoryNavFromUser(data);
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

async function ensureBillingRouteAccess(path) {
  if (billingNavCache === null) {
    try {
      const { data } = await api.get("/auth/me");
      setUsersNavFromUser(data);
      setClientsNavFromUser(data);
      setWebmasterNavFromUser(data);
      setBillingNavFromUser(data);
      setInventoryNavFromUser(data);
    } catch (e) {
      if (e.response?.status === 401) {
        localStorage.removeItem("auth_token");
        billingNavCache = null;
        inventoryNavCache = null;
      }
      return false;
    }
  }
  if (path === "/billing" || path.startsWith("/billing/")) {
    return billingNavCache.view === true;
  }
  return true;
}

async function ensureInventoryRouteAccess(path) {
  if (inventoryNavCache === null) {
    try {
      const { data } = await api.get("/auth/me");
      setUsersNavFromUser(data);
      setClientsNavFromUser(data);
      setWebmasterNavFromUser(data);
      setBillingNavFromUser(data);
      setInventoryNavFromUser(data);
    } catch (e) {
      if (e.response?.status === 401) {
        localStorage.removeItem("auth_token");
        inventoryNavCache = null;
        billingNavCache = null;
      }
      return false;
    }
  }
  if (
    path === "/inventory" ||
    path.startsWith("/inventory/") ||
    path === "/orders" ||
    path.startsWith("/orders/")
  ) {
    return inventoryNavCache.view === true;
  }
  return true;
}

router.beforeEach(async (to) => {
  if (to.name === "create-account" && !isRootSpaBundle()) {
    if (typeof window !== "undefined") {
      window.location.replace(getPublicSignupUrl());
    }
    return false;
  }

  const token = localStorage.getItem("auth_token");

  if (to.meta.public) {
    // Keep /create reachable while signed in (e.g. staff verifying the form); only skip /login when already authenticated.
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

  if (to.path.startsWith("/clients")) {
    const ok = await ensureClientsRouteAccess(to.path);
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/dashboard" };
    }
  }

  if (to.path.startsWith("/billing")) {
    const ok = await ensureBillingRouteAccess(to.path);
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/dashboard" };
    }
  }

  if (to.path.startsWith("/inventory")) {
    const ok = await ensureInventoryRouteAccess(to.path);
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/dashboard" };
    }
  }

  if (to.path.startsWith("/orders")) {
    const ok = await ensureInventoryRouteAccess(to.path);
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
