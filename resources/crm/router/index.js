import { createRouter, createWebHistory } from "vue-router";
import api from "../services/api";
import { crmIsAdmin, crmIsPortalUser, crmPortalNeedsWelcome, crmPortalPostAuthPath } from "../utils/crmUser.js";
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
import SettingsPricingPage from "../pages/settings/SettingsPricingPage.vue";
import ClientAccountsListPage from "../pages/clients/ClientAccountsListPage.vue";
import ClientAccountDetailPage from "../pages/clients/ClientAccountDetailPage.vue";
import ClientAccountUsersListPage from "../pages/clients/ClientAccountUsersListPage.vue";
import ClientAccountUserDetailPage from "../pages/clients/ClientAccountUserDetailPage.vue";
import BillingSummaryPage from "../pages/billing/BillingSummaryPage.vue";
import BillingInvoicesListPage from "../pages/billing/BillingInvoicesListPage.vue";
import BillingInvoiceDetailPage from "../pages/billing/BillingInvoiceDetailPage.vue";
import BillingCustomBillsListPage from "../pages/billing/BillingCustomBillsListPage.vue";
import BillingCustomBillDetailPage from "../pages/billing/BillingCustomBillDetailPage.vue";
import InventoryListPage from "../pages/inventory/InventoryListPage.vue";
import InventoryDetailPage from "../pages/inventory/InventoryDetailPage.vue";
import InventoryOnDemandPage from "../pages/inventory/InventoryOnDemandPage.vue";
import OrdersListPage from "../pages/orders/OrdersListPage.vue";
import OrderDetailPage from "../pages/orders/OrderDetailPage.vue";
import OrderDetailIframePage from "../pages/orders/OrderDetailIframePage.vue";
import UserDashboardPage from "../pages/user-portal/UserDashboardPage.vue";
import PortalPendingWelcomePage from "../pages/user-portal/PortalPendingWelcomePage.vue";

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
  settingsPricing: {
    title: "Save Rack | Pricing",
    description: "Default fees applied to new client accounts.",
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
  billingCustomBills: {
    title: "Save Rack | Custom Bills",
    description: "Custom bills for client accounts.",
  },
  billingCustomBillDetail: {
    title: "Save Rack | Custom Bill",
    description: "Custom bill detail.",
  },
  inventory: {
    title: "Save Rack | Inventory",
    description: "ShipHero live inventory.",
  },
  inventoryDetail: {
    title: "Save Rack | Inventory Detail",
    description: "ShipHero product inventory detail.",
  },
  inventoryOnDemand: {
    title: "Save Rack | On-Demand Inventory",
    description: "Account On-Demand SKU catalog.",
  },
  ordersManage: {
    title: "Save Rack | Orders | Manage",
    description:
      "ShipHero orders for the account with your filters; all queues at once (not only ready to ship, on-hold, backorder, or shipped). Order date defaults to today.",
  },
  ordersAwaiting: {
    title: "Save Rack | Orders | Ready to Ship",
    description: "ShipHero ready to ship orders.",
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
  userPortalDashboard: {
    title: "Save Rack | Dashboard",
    description: "Your account ShipHero order queue summary.",
  },
  userPortalInventory: {
    title: "Save Rack | Products | Inventory",
    description: "ShipHero live inventory for your account.",
  },
  userPortalInventoryOutOfStock: {
    title: "Save Rack | Products | Out of Stock",
    description: "Products with oversold quantity for your account.",
  },
  userPortalInventoryDetail: {
    title: "Save Rack | Products | Inventory Detail",
    description: "ShipHero product inventory detail.",
  },
  userAsnList: {
    title: "Save Rack | ASN",
    description: "Advance shipping notices for your account.",
  },
  userAsnDetail: {
    title: "Save Rack | ASN Detail",
    description: "ASN line items, tracking, and warehouse details.",
  },
  adminAsnHub: {
    title: "Save Rack | ASN",
    description: "Receiving advance shipping notices.",
  },
  adminAsnDetail: {
    title: "Save Rack | ASN Detail",
    description: "Admin ASN receiving and processing.",
  },
  userReturnOrdersList: {
    title: "Save Rack | Return Orders",
    description: "View returned orders that are pending processing or completed.",
  },
  userReturnItemsList: {
    title: "Save Rack | Return Items",
    description: "Line items on returns for your account.",
  },
  userReturnCreateSearch: {
    title: "Save Rack | Create Return",
    description: "Search for an order to start a return.",
  },
  userReturnCreateOrder: {
    title: "Save Rack | Order",
    description: "Review order before creating a return.",
  },
  userReturnCreate: {
    title: "Save Rack | Create Return",
    description: "Select items and complete your return.",
  },
  userReturnCreateManual: {
    title: "Save Rack | Manual Return",
    description: "Create a return without a ShipHero order.",
  },
  userReturnDetail: {
    title: "Save Rack | Return",
    description: "Return detail and documents.",
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
  { path: "/", redirect: "/login" },
  {
    path: "/admin/dashboard",
    name: "dashboard",
    component: DashboardPage,
    meta: meta.dashboard,
  },
  { path: "/admin/staff", name: "staff", component: UsersListPage, meta: meta.staff },
  { path: "/admin", redirect: "/admin/dashboard" },
  {
    path: "/admin/staff/create",
    name: "staff-create",
    component: UserFormPage,
    meta: meta.staffCreate,
  },
  { path: "/admin/staff/new", redirect: "/admin/staff/create" },
  {
    path: "/admin/staff/:id/edit",
    name: "staff-edit",
    redirect: (to) => `/admin/staff/${to.params.id}`,
  },
  {
    path: "/admin/staff/:id/permissions",
    name: "staff-permissions",
    component: UserPermissionsPage,
    props: true,
    meta: {
      title: "Save Rack | User Permissions",
      description: "Manage User Module Permissions.",
    },
  },
  {
    path: "/admin/staff/:id/history",
    name: "staff-history",
    component: UserHistoryPage,
    props: true,
    meta: {
      title: "Save Rack | Staff History",
      description: "Staff Profile Change History.",
    },
  },
  {
    path: "/admin/staff/:id",
    name: "staff-detail",
    component: UserDetailPage,
    props: true,
    meta: {
      title: "Save Rack | Staff",
      description: "Staff Profile.",
    },
  },
  {
    path: "/admin/clients/accounts",
    name: "client-accounts",
    component: ClientAccountsListPage,
    meta: meta.clientAccounts,
  },
  {
    path: "/admin/clients/accounts/:id",
    name: "client-account-detail",
    component: ClientAccountDetailPage,
    props: true,
    meta: meta.clientAccountDetail,
  },
  {
    path: "/admin/clients/users",
    name: "client-users",
    component: ClientAccountUsersListPage,
    meta: meta.clientUsers,
  },
  {
    path: "/admin/clients/users/:accountId/:userId",
    name: "client-account-user-detail",
    component: ClientAccountUserDetailPage,
    props: true,
    meta: meta.clientAccountUserDetail,
  },
  { path: "/admin/clients", redirect: "/admin/clients/accounts" },
  {
    path: "/admin/billing/summary",
    name: "billing-summary",
    component: BillingSummaryPage,
    meta: meta.billingSummary,
  },
  {
    path: "/admin/billing/invoices",
    name: "billing-invoices",
    component: BillingInvoicesListPage,
    meta: meta.billingInvoices,
  },
  {
    path: "/admin/billing/invoices/:id",
    name: "billing-invoice-detail",
    component: BillingInvoiceDetailPage,
    props: true,
    meta: meta.billingInvoiceDetail,
  },
  {
    path: "/admin/billing/custom-bills",
    name: "billing-custom-bills",
    component: BillingCustomBillsListPage,
    meta: meta.billingCustomBills,
  },
  {
    path: "/admin/billing/custom-bills/:id",
    name: "billing-custom-bill-detail",
    component: BillingCustomBillDetailPage,
    props: true,
    meta: meta.billingCustomBillDetail,
  },
  { path: "/admin/billing", redirect: "/admin/billing/summary" },
  {
    path: "/admin/receiving/asn",
    name: "admin-asn-hub",
    component: () => import("../pages/admin-asn/AdminAsnHubPage.vue"),
    meta: meta.adminAsnHub,
  },
  {
    path: "/admin/receiving/asn/:id",
    name: "admin-asn-detail",
    component: () => import("../pages/admin-asn/AdminAsnDetailPage.vue"),
    props: true,
    meta: meta.adminAsnDetail,
  },
  { path: "/admin/receiving", redirect: "/admin/receiving/asn" },
  {
    path: "/admin/inventory",
    name: "inventory",
    component: InventoryListPage,
    meta: meta.inventory,
  },
  {
    path: "/admin/inventory/:sku",
    name: "inventory-detail",
    component: InventoryDetailPage,
    props: true,
    meta: meta.inventoryDetail,
  },
  {
    path: "/admin/inventory/on-demand",
    name: "inventory-on-demand",
    component: InventoryOnDemandPage,
    meta: meta.inventoryOnDemand,
  },
  {
    path: "/admin/orders",
    redirect: "/admin/orders/all",
  },
  {
    path: "/admin/orders/create",
    name: "orders-create",
    component: () => import("../pages/orders/OrderCreatePage.vue"),
    meta: {
      title: "Save Rack | Orders | Create Order",
      description: "Create a new ShipHero order for a client account.",
      requiresOrdersUpdate: true,
    },
  },
  {
    path: "/admin/orders/all",
    name: "orders-all",
    component: OrdersListPage,
    meta: { ...meta.ordersManage, orderTab: "manage" },
  },
  {
    path: "/admin/orders/manage",
    redirect: "/admin/orders/all",
  },
  {
    path: "/admin/orders/awaiting",
    name: "orders-awaiting",
    component: OrdersListPage,
    meta: { ...meta.ordersAwaiting, orderTab: "awaiting" },
  },
  {
    path: "/admin/orders/on-hold",
    name: "orders-on-hold",
    component: OrdersListPage,
    meta: { ...meta.ordersOnHold, orderTab: "on_hold" },
  },
  {
    path: "/admin/orders/backorder",
    name: "orders-out-of-stock",
    component: OrdersListPage,
    meta: {
      title: "Save Rack | Orders | Backorder",
      description: "ShipHero backorder orders.",
      orderTab: "backorder",
    },
  },
  {
    path: "/admin/orders/shipped",
    name: "orders-shipped",
    component: OrdersListPage,
    meta: { ...meta.ordersShipped, orderTab: "shipped" },
  },
  {
    path: "/admin/orders/:shipheroOrderId",
    name: "order-detail",
    component: OrderDetailPage,
    props: true,
    meta: meta.orderDetail,
  },
  {
    path: "/admin/orders/:accountSlug/:orderNumber",
    name: "order-detail-iframe",
    component: OrderDetailIframePage,
    props: true,
    meta: meta.orderDetail,
  },
  {
    path: "/admin/settings",
    redirect: "/admin/settings/pricing",
  },
  {
    path: "/admin/settings/pricing",
    name: "settings-pricing",
    component: SettingsPricingPage,
    meta: meta.settingsPricing,
  },
  {
    path: "/admin/webmaster",
    name: "webmaster",
    component: WebmasterTasksPage,
    meta: meta.webmaster,
  },
  {
    path: "/admin/webmaster/tasks/:id",
    name: "webmaster-task-detail",
    component: WebmasterTaskDetailPage,
    props: true,
    meta: meta.webmasterTask,
  },
  {
    path: "/users/welcome",
    name: "user-welcome",
    component: PortalPendingWelcomePage,
    meta: { title: "Save Rack | Account setup", description: "Account setup in progress.", userPortal: true },
  },
  {
    path: "/users/dashboard",
    name: "user-dashboard",
    component: UserDashboardPage,
    meta: { ...meta.userPortalDashboard, userPortal: true },
  },
  {
    path: "/users/account-settings",
    name: "user-account-settings",
    component: () => import("../pages/user-portal/UserPortalAccountSettingsPage.vue"),
    meta: { title: "Save Rack | Account Settings", description: "Portal account settings.", userPortal: true },
  },
  {
    path: "/users/support",
    name: "user-support",
    component: () => import("../pages/user-portal/UserPortalSupportPage.vue"),
    meta: { title: "Save Rack | Support", description: "Portal support.", userPortal: true },
  },
  {
    path: "/users/billing/invoices",
    name: "user-billing-invoices",
    component: () => import("../pages/user-portal/UserBillingInvoicesPage.vue"),
    meta: { title: "Save Rack | Invoices", description: "Your fulfillment invoices.", userPortal: true },
  },
  { path: "/users/billing", redirect: "/users/billing/invoices" },
  { path: "/users/orders", name: "user-orders", component: OrdersListPage, meta: { ...meta.ordersAwaiting, orderTab: "awaiting", userPortal: true } },
  { path: "/users", redirect: "/users/dashboard" },
  { path: "/users/orders/ready-to-ship", name: "user-orders-awaiting", component: OrdersListPage, meta: { ...meta.ordersAwaiting, orderTab: "awaiting", userPortal: true } },
  { path: "/users/orders/on-hold", name: "user-orders-on-hold", component: OrdersListPage, meta: { ...meta.ordersOnHold, orderTab: "on_hold", userPortal: true } },
  { path: "/users/orders/backorder", name: "user-orders-backorder", component: OrdersListPage, meta: { title: "Save Rack | Orders | Backorder", description: "ShipHero backorder orders.", orderTab: "backorder", userPortal: true } },
  { path: "/users/orders/shipped", name: "user-orders-shipped", component: OrdersListPage, meta: { ...meta.ordersShipped, orderTab: "shipped", userPortal: true } },
  { path: "/users/orders/:shipheroOrderId", name: "user-order-detail", component: () => import("../pages/user-orders/UserOrderDetailPage.vue"), props: true, meta: { ...meta.orderDetail, userPortal: true } },
  { path: "/users/inventory/out-of-stock", name: "user-inventory-out-of-stock", component: () => import("../pages/user-inventory/UserInventoryOutOfStockPage.vue"), meta: { ...meta.userPortalInventoryOutOfStock, userPortal: true } },
  { path: "/users/inventory", name: "user-inventory", component: InventoryListPage, meta: { ...meta.userPortalInventory, userPortal: true } },
  { path: "/users/inventory/:sku", name: "user-inventory-detail", component: () => import("../pages/user-inventory/UserInventoryDetailPage.vue"), props: true, meta: { ...meta.userPortalInventoryDetail, userPortal: true } },
  { path: "/users/asn", name: "user-asn-list", component: () => import("../pages/user-asn/UserAsnListPage.vue"), meta: { ...meta.userAsnList, userPortal: true } },
  { path: "/users/asn/:id", name: "user-asn-detail", component: () => import("../pages/user-asn/UserAsnDetailPage.vue"), props: true, meta: { ...meta.userAsnDetail, userPortal: true } },
  { path: "/users/asn/:id/print-shipping-label", name: "user-asn-print-shipping-label", component: () => import("../pages/user-asn/UserAsnPrintShippingLabelPage.vue"), props: true, meta: { title: "Save Rack | Print Shipping Label", description: "4x6 shipping label.", userPortal: true, bareLayout: true } },
  { path: "/users/asn/:id/print-packing-slip", name: "user-asn-print-packing-slip", component: () => import("../pages/user-asn/UserAsnPrintPackingSlipPage.vue"), props: true, meta: { title: "Save Rack | Packing Slip", description: "ASN packing slip.", userPortal: true, bareLayout: true } },
  { path: "/users/asn/:asnId/print-barcode/:lineId", name: "user-asn-print-barcode", component: () => import("../pages/user-asn/UserAsnPrintBarcodePage.vue"), props: true, meta: { title: "Save Rack | Barcode", description: "Product barcode.", userPortal: true, bareLayout: true } },
  { path: "/users/returns/orders", name: "user-return-orders", component: () => import("../pages/user-returns/UserReturnOrdersListPage.vue"), meta: { ...meta.userReturnOrdersList, userPortal: true } },
  { path: "/users/returns/items", name: "user-return-items", component: () => import("../pages/user-returns/UserReturnItemsListPage.vue"), meta: { ...meta.userReturnItemsList, userPortal: true } },
  { path: "/users/returns/create", name: "user-return-create-search", component: () => import("../pages/user-returns/UserReturnCreateSearchPage.vue"), meta: { ...meta.userReturnCreateSearch, userPortal: true } },
  { path: "/users/returns/create/manual", name: "user-return-create-manual", component: () => import("../pages/user-returns/UserReturnManualCreatePage.vue"), meta: { ...meta.userReturnCreateManual, userPortal: true } },
  { path: "/users/returns/create/order/:shipheroOrderId", name: "user-return-create-order", component: () => import("../pages/user-returns/UserReturnCreateOrderPage.vue"), props: true, meta: { ...meta.userReturnCreateOrder, userPortal: true } },
  { path: "/users/returns/create/order/:shipheroOrderId/new", name: "user-return-create", component: () => import("../pages/user-returns/UserReturnCreatePage.vue"), props: true, meta: { ...meta.userReturnCreate, userPortal: true } },
  { path: "/users/returns/:id", name: "user-return-detail", component: () => import("../pages/user-returns/UserReturnDetailPage.vue"), props: true, meta: { ...meta.userReturnDetail, userPortal: true } },
  { path: "/users/returns/:id/print-packing-slip", name: "user-return-print-packing-slip", component: () => import("../pages/user-returns/UserReturnPrintPackingSlipPage.vue"), props: true, meta: { title: "Save Rack | Return Packing Slip", description: "Return packing slip.", userPortal: true, bareLayout: true } },
  { path: "/users/returns/:id/print-shipping-label", name: "user-return-print-shipping-label", component: () => import("../pages/user-returns/UserReturnPrintShippingLabelPage.vue"), props: true, meta: { title: "Save Rack | Shipping Label", description: "4x6 return shipping label.", userPortal: true, bareLayout: true } },
  { path: "/tickets/board", redirect: "/admin/dashboard" },
  { path: "/tickets/:id", redirect: "/admin/dashboard" },
  { path: "/tickets", redirect: "/admin/dashboard" },
];

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
});

let webmasterNavCache = null;

/** Settings module: permissions from /auth/me (see setSettingsNavFromUser). */
let settingsNavCache = null;

/** Users module: per-action permissions from /auth/me (see setUsersNavFromUser). */
let usersNavCache = null;

/** Clients module: per-action permissions from /auth/me (see setClientsNavFromUser). */
let clientsNavCache = null;

/** Billing module: permissions from /auth/me (see setBillingNavFromUser). */
let billingNavCache = null;

/** Orders module: permissions from /auth/me (see setOrdersNavFromUser). */
let ordersNavCache = null;

/** Inventory (ShipHero): permissions from /auth/me (see setInventoryNavFromUser). */
let inventoryNavCache = null;

/** True when the signed-in CRM user is an administrator (`crmIsAdmin`); used for permissions routes only. */
let usersMeIsAdmin = false;
let authUserCache = null;

export function clearCrmOwnerCache() {
  webmasterNavCache = null;
  settingsNavCache = null;
  usersNavCache = null;
  clientsNavCache = null;
  billingNavCache = null;
  ordersNavCache = null;
  inventoryNavCache = null;
  usersMeIsAdmin = false;
  authUserCache = null;
}

export function setWebmasterNavFromUser(user) {
  if (!user) {
    webmasterNavCache = null;
    return;
  }
  webmasterNavCache = !!user.is_crm_owner || crmIsAdmin(user);
}

export function setSettingsNavFromUser(user) {
  if (!user) {
    settingsNavCache = null;
    return;
  }
  settingsNavCache = !!user.is_crm_owner || crmIsAdmin(user);
}

export function setUsersNavFromUser(user) {
  if (!user) {
    usersNavCache = null;
    usersMeIsAdmin = false;
    return;
  }
  usersMeIsAdmin = crmIsAdmin(user) || !!user.is_crm_owner;
  if (usersMeIsAdmin) {
    usersNavCache = {
      view: true,
      create: true,
      update: true,
      delete: true,
    };
    return;
  }
  usersNavCache = {
    view: false,
    create: false,
    update: false,
    delete: false,
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

export function setOrdersNavFromUser(user) {
  if (!user) {
    ordersNavCache = null;
    return;
  }
  if (crmIsAdmin(user) || user.is_crm_owner) {
    ordersNavCache = { view: true, update: true };
    return;
  }
  if (crmIsPortalUser(user)) {
    ordersNavCache = { view: true, update: false };
    return;
  }
  const k = Array.isArray(user.permission_keys) ? user.permission_keys : [];
  ordersNavCache = {
    view: k.includes("orders.view"),
    update: k.includes("orders.update"),
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
  if (crmIsPortalUser(user)) {
    inventoryNavCache = { view: true, update: false };
    return;
  }
  const k = Array.isArray(user.permission_keys) ? user.permission_keys : [];
  inventoryNavCache = {
    view: k.includes("inventory.view"),
    update: k.includes("inventory.update"),
  };
}

async function ensureAuthUser() {
  if (authUserCache) return authUserCache;
  const { data } = await api.get("/auth/me");
  authUserCache = data;
  setUsersNavFromUser(data);
  setClientsNavFromUser(data);
  setWebmasterNavFromUser(data);
  setSettingsNavFromUser(data);
  setBillingNavFromUser(data);
  setOrdersNavFromUser(data);
  setInventoryNavFromUser(data);
  return data;
}

async function ensureClientsRouteAccess(path) {
  if (clientsNavCache === null) {
    try {
      const { data } = await api.get("/auth/me");
      setUsersNavFromUser(data);
      setClientsNavFromUser(data);
      setWebmasterNavFromUser(data);
      setSettingsNavFromUser(data);
      setBillingNavFromUser(data);
      setOrdersNavFromUser(data);
      setInventoryNavFromUser(data);
    } catch (e) {
      if (e.response?.status === 401) {
        localStorage.removeItem("auth_token");
        clientsNavCache = null;
        billingNavCache = null;
        inventoryNavCache = null;
        settingsNavCache = null;
      }
      return false;
    }
  }
  if (path === "/admin/clients" || path.startsWith("/admin/clients/")) {
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
      setSettingsNavFromUser(data);
      setBillingNavFromUser(data);
      setOrdersNavFromUser(data);
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
    path === "/admin/staff/create" ||
    path === "/admin/staff/new";
  if (staffCreate) {
    return usersNavCache.create === true;
  }
  const me = await ensureAuthUser();
  const myId = me?.id != null ? String(me.id) : null;

  const permMatch = /^\/admin\/staff\/([^/]+)\/permissions$/.exec(path);
  if (permMatch) {
    if (myId && permMatch[1] === myId) {
      return false;
    }
    return usersMeIsAdmin === true;
  }
  if (/^\/admin\/staff\/[^/]+\/edit$/.test(path)) {
    return usersNavCache.update === true;
  }
  if (
    /^\/admin\/staff\/[^/]+\/history$/.test(path)
  ) {
    return usersNavCache.view === true;
  }
  const staffDetailMatch = /^\/admin\/staff\/(\d+)$/.exec(path);
  if (staffDetailMatch && myId && staffDetailMatch[1] === myId) {
    return true;
  }
  if (
    path === "/admin/staff" ||
    path.startsWith("/admin/staff/")
  ) {
    return usersNavCache.view === true;
  }
  return true;
}

function userCanWebmaster(userLike) {
  if (!userLike) return false;
  return !!userLike.is_crm_owner || crmIsAdmin(userLike);
}

function userCanSettings(userLike) {
  if (!userLike) return false;
  return !!userLike.is_crm_owner || crmIsAdmin(userLike);
}

async function ensureSettingsRouteAccess() {
  if (settingsNavCache !== null) {
    return settingsNavCache;
  }
  try {
    const { data } = await api.get("/auth/me");
    setUsersNavFromUser(data);
    setClientsNavFromUser(data);
    setWebmasterNavFromUser(data);
    setSettingsNavFromUser(data);
    setBillingNavFromUser(data);
    setOrdersNavFromUser(data);
    setInventoryNavFromUser(data);
    const ok = userCanSettings(data);
    settingsNavCache = ok;
    return ok;
  } catch (e) {
    if (e.response?.status === 401) {
      localStorage.removeItem("auth_token");
      settingsNavCache = null;
    } else {
      settingsNavCache = false;
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
    setUsersNavFromUser(data);
    setClientsNavFromUser(data);
    setWebmasterNavFromUser(data);
    setBillingNavFromUser(data);
    setOrdersNavFromUser(data);
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
      setSettingsNavFromUser(data);
      setBillingNavFromUser(data);
      setOrdersNavFromUser(data);
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
  if (path === "/admin/billing" || path.startsWith("/admin/billing/")) {
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
      setSettingsNavFromUser(data);
      setBillingNavFromUser(data);
      setOrdersNavFromUser(data);
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
    path === "/admin/inventory" ||
    path.startsWith("/admin/inventory/") ||
    path.startsWith("/admin/receiving/") ||
    path === "/users/inventory" ||
    path.startsWith("/users/inventory/") ||
    path === "/users/asn" ||
    path.startsWith("/users/asn/") ||
    path === "/users/dashboard" ||
    path.startsWith("/users/dashboard/") ||
    path === "/users/orders" ||
    path.startsWith("/users/orders/")
  ) {
    return inventoryNavCache.view === true;
  }
  return true;
}

async function ensureOrdersRouteAccess(path) {
  if (ordersNavCache === null) {
    try {
      const { data } = await api.get("/auth/me");
      setUsersNavFromUser(data);
      setClientsNavFromUser(data);
      setWebmasterNavFromUser(data);
      setSettingsNavFromUser(data);
      setBillingNavFromUser(data);
      setOrdersNavFromUser(data);
      setInventoryNavFromUser(data);
    } catch (e) {
      if (e.response?.status === 401) {
        localStorage.removeItem("auth_token");
        ordersNavCache = null;
      }
      return false;
    }
  }
  if (path === "/admin/orders/create") {
    return ordersNavCache.update === true;
  }
  if (path.startsWith("/admin/orders")) {
    return ordersNavCache.view === true;
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
    if (token && to.name === "login") {
      try {
        const me = await ensureAuthUser();
        return { path: crmIsPortalUser(me) ? crmPortalPostAuthPath(me) : "/admin/dashboard" };
      } catch {
        localStorage.removeItem("auth_token");
        clearCrmOwnerCache();
        return true;
      }
    }
    return true;
  }

  if (!token) {
    return { name: "login", query: { redirect: to.fullPath } };
  }

  let me = null;
  try {
    me = await ensureAuthUser();
  } catch {
    localStorage.removeItem("auth_token");
    clearCrmOwnerCache();
    return { name: "login", query: { redirect: to.fullPath } };
  }

  if (crmIsPortalUser(me)) {
    if (!to.path.startsWith("/users/")) {
      return { path: crmPortalPostAuthPath(me) };
    }
    if (
      crmPortalNeedsWelcome(me) &&
      to.name !== "user-welcome" &&
      to.path !== "/users/welcome"
    ) {
      return { path: "/users/welcome" };
    }
    if (
      !crmPortalNeedsWelcome(me) &&
      (to.name === "user-welcome" || to.path === "/users/welcome")
    ) {
      return { path: "/users/dashboard" };
    }
  } else if (to.path.startsWith("/users/")) {
    return { path: "/admin/dashboard" };
  }

  if (to.path.startsWith("/admin/settings")) {
    const ok = await ensureSettingsRouteAccess();
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/admin/dashboard" };
    }
  }

  if (to.path === "/admin/webmaster" || to.path.startsWith("/admin/webmaster/")) {
    const ok = await ensureWebmasterRouteAccess();
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/admin/dashboard" };
    }
  }

  if (to.path.startsWith("/admin/staff")) {
    const ok = await ensureUsersRouteAccess(to.path);
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/admin/dashboard" };
    }
  }

  if (to.path.startsWith("/admin/clients")) {
    const ok = await ensureClientsRouteAccess(to.path);
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/admin/dashboard" };
    }
  }

  if (to.path.startsWith("/admin/billing")) {
    const ok = await ensureBillingRouteAccess(to.path);
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/admin/dashboard" };
    }
  }

  if (to.path.startsWith("/admin/orders")) {
    const ok = await ensureOrdersRouteAccess(to.path);
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/admin/dashboard" };
    }
  }

  if (
    to.path.startsWith("/admin/inventory") ||
    to.path.startsWith("/admin/receiving") ||
    to.path.startsWith("/users/inventory") ||
    to.path.startsWith("/users/asn")
  ) {
    const ok = await ensureInventoryRouteAccess(to.path);
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: crmIsPortalUser(me) ? "/users/dashboard" : "/admin/dashboard" };
    }
  }

  if (
    to.path.startsWith("/users/orders") ||
    to.path === "/users/dashboard" ||
    to.path.startsWith("/users/asn")
  ) {
    const ok = await ensureInventoryRouteAccess(to.path);
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: crmIsPortalUser(me) ? "/users/dashboard" : "/admin/dashboard" };
    }
  }

  return true;
});

router.afterEach((to) => {
  applyRouteMeta(to);
});

export default router;
