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
import SettingsTermsPage from "../pages/settings/SettingsTermsPage.vue";
import ClientAccountTermsPage from "../pages/clients/ClientAccountTermsPage.vue";
import ClientAccountsListPage from "../pages/clients/ClientAccountsListPage.vue";
import ClientAccountDetailPage from "../pages/clients/ClientAccountDetailPage.vue";
import ClientAccountHistoryPage from "../pages/clients/ClientAccountHistoryPage.vue";
import ClientAccountUsersListPage from "../pages/clients/ClientAccountUsersListPage.vue";
import ProjectsListPage from "../pages/projects/ProjectsListPage.vue";
import ProjectDetailPage from "../pages/projects/ProjectDetailPage.vue";
import ClientAccountUserDetailPage from "../pages/clients/ClientAccountUserDetailPage.vue";
import BillingSummaryPage from "../pages/billing/BillingSummaryPage.vue";
import BillingInvoicesListPage from "../pages/billing/BillingInvoicesListPage.vue";
import BillingInvoiceDetailPage from "../pages/billing/BillingInvoiceDetailPage.vue";
import BillingCustomBillsListPage from "../pages/billing/BillingCustomBillsListPage.vue";
import BillingCustomBillDetailPage from "../pages/billing/BillingCustomBillDetailPage.vue";
import BillingReturnBillDetailPage from "../pages/billing/BillingReturnBillDetailPage.vue";
import BillingAsnBillDetailPage from "../pages/billing/BillingAsnBillDetailPage.vue";
import InventoryOnDemandPage from "../pages/inventory/InventoryOnDemandPage.vue";
import InventoryRestockPage from "../pages/inventory/InventoryRestockPage.vue";
import InventoryBetaListPage from "../pages/inventory/InventoryBetaListPage.vue";
import InventoryBetaDetailPage from "../pages/inventory/InventoryBetaDetailPage.vue";
import OrdersListPage from "../pages/orders/OrdersListPage.vue";
import OrderDetailPage from "../pages/orders/OrderDetailPage.vue";
import OrderDetailIframePage from "../pages/orders/OrderDetailIframePage.vue";
import FulfillmentPage from "../pages/orders/FulfillmentPage.vue";
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
    title: "Save Rack | Home",
    description: "Operations overview — holds and ASN.",
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
  settingsTerms: {
    title: "Save Rack | Terms of Service",
    description: "Default Terms of Service for client accounts.",
  },
  clientAccountTerms: {
    title: "Save Rack | Account Terms",
    description: "Account Terms and Conditions.",
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
  projects: {
    title: "Save Rack | Projects",
    description: "Client projects.",
  },
  projectDetail: {
    title: "Save Rack | Project",
    description: "Project detail.",
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
    description: "Custom, ASN, and Return bills for client accounts.",
  },
  billingCustomBillDetail: {
    title: "Save Rack | Custom Bill",
    description: "Custom bill detail.",
  },
  billingReturnBills: {
    title: "Save Rack | Returns Bills",
    description: "Return processing bills for client accounts.",
  },
  billingReturnBillDetail: {
    title: "Save Rack | Return Bill",
    description: "Return bill detail.",
  },
  billingAsnBills: {
    title: "Save Rack | ASN Bills",
    description: "Receiving fee lines for ASNs with billable charges.",
  },
  billingAsnBillDetail: {
    title: "Save Rack | ASN Bill",
    description: "ASN bill detail.",
  },
  inventory: {
    title: "Save Rack | Products",
    description: "CRM-stored product catalog with incremental account sync.",
  },
  inventoryDetail: {
    title: "Save Rack | Product Detail",
    description: "Catalog product detail with live ShipHero qty and locations.",
  },
  inventoryOnDemand: {
    title: "Save Rack | On-Demand Inventory",
    description: "Account On-Demand SKU catalog.",
  },
  inventoryRestock: {
    title: "Save Rack | Inventory | Restocks",
    description: "Inventory needing replenishment.",
  },
  inventoryOutOfStock: {
    title: "Save Rack | Inventory | Out of Stock",
    description: "Products with oversold quantity by client account.",
  },
  inventoryBeta: {
    title: "Save Rack | Inventory (Beta)",
    description: "CRM-stored product catalog with incremental account sync.",
  },
  inventoryBetaDetail: {
    title: "Save Rack | Inventory (Beta) Detail",
    description: "Catalog product detail with live ShipHero qty and locations.",
  },
  ordersSearch: {
    title: "Save Rack | Orders | Search",
    description: "Find a ShipHero order by order number; optionally filter by client account.",
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
    description: "On-hold orders by account and hold type.",
  },
  ordersOnHoldLegacy: {
    title: "Save Rack | Orders | On-Hold",
    description: "ShipHero on-hold orders.",
  },
  ordersShipped: {
    title: "Save Rack | Orders | Shipped",
    description: "ShipHero shipped orders.",
  },
  ordersFulfillment: {
    title: "Save Rack | Orders | Fulfillment",
    description: "Ready to ship and shipped orders by account.",
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
    title: "Save Rack | Inventory | Products",
    description: "CRM-stored product catalog with incremental account sync.",
  },
  userPortalInventoryOutOfStock: {
    title: "Save Rack | Inventory | Out of Stock",
    description: "Products with oversold quantity for your account.",
  },
  userPortalInventoryDetail: {
    title: "Save Rack | Inventory | Product Detail",
    description: "Catalog product detail with live ShipHero qty and locations.",
  },
  userPortalInventoryBeta: {
    title: "Save Rack | Inventory (Beta)",
    description: "CRM-stored product catalog with incremental account sync.",
  },
  userPortalInventoryBetaDetail: {
    title: "Save Rack | Inventory (Beta) | Product Detail",
    description: "Catalog product detail with live ShipHero qty and locations.",
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
    title: "Save Rack | Advanced Shipment Notice",
    description: "Search and manage advance shipping notices.",
  },
  adminAsnDetail: {
    title: "Save Rack | ASN Detail",
    description: "Admin ASN receiving and processing.",
  },
  adminPutAway: {
    title: "Save Rack | Put Away",
    description: "Move inventory from Receiving to warehouse locations.",
  },
  adminPutAwayDetail: {
    title: "Save Rack | Put Away Detail",
    description: "Put away product locations and transfers.",
  },
  adminProcessReturnsSearch: {
    title: "Save Rack | Process Returns",
    description: "Find pending returns to process.",
  },
  adminReturnCreate: {
    title: "Save Rack | Create Return",
    description: "Create a return for a client order.",
  },
  adminProcessReturnDetail: {
    title: "Save Rack | Process Return",
    description: "Process a pending return.",
  },
  wholesaleOrdersList: {
    title: "Save Rack | Wholesale Orders",
    description: "Manage wholesale fulfillment orders.",
  },
  wholesaleOrderDetail: {
    title: "Save Rack | Wholesale Order",
    description: "Wholesale order detail.",
  },
  wholesalePickList: {
    title: "Save Rack | Pick List",
    description: "Pick wholesale order line items.",
  },
  adminReturnedOrders: {
    title: "Save Rack | Returned Orders",
    description: "Processed returns by order.",
  },
  adminReturnedItems: {
    title: "Save Rack | Returned Items",
    description: "Processed return line items.",
  },
  adminReturnBins: {
    title: "Save Rack | Return Bins",
    description: "Physical return bins awaiting restock.",
  },
  adminReturnBinDetail: {
    title: "Save Rack | Return Bin",
    description: "Items in a return bin.",
  },
  resourcesTutorials: {
    title: "Save Rack | Tutorials",
    description: "Staff training tutorials.",
  },
  resourcesTutorialDetail: {
    title: "Save Rack | Tutorial",
    description: "Tutorial detail.",
  },
  resourcesPhotos: {
    title: "Save Rack | Photos",
    description: "Reference photos for staff training.",
  },
  resourcesCalendar: {
    title: "Save Rack | Calendar",
    description: "Staff calendar for meetings, holidays, and operations.",
  },
  resourcesCalendarEvents: {
    title: "Save Rack | Calendar Events",
    description: "List and manage staff calendar events.",
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
    path: "/admin/home",
    name: "dashboard",
    component: DashboardPage,
    meta: meta.dashboard,
  },
  { path: "/admin/dashboard", redirect: "/admin/home" },
  { path: "/admin/staff", name: "staff", component: UsersListPage, meta: meta.staff },
  { path: "/admin", redirect: "/admin/home" },
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
    path: "/admin/clients/accounts/:id/history",
    name: "client-account-history",
    component: ClientAccountHistoryPage,
    props: true,
    meta: meta.clientAccountDetail,
  },
  {
    path: "/admin/clients/accounts/:id/terms",
    name: "client-account-terms",
    component: ClientAccountTermsPage,
    props: true,
    meta: meta.clientAccountTerms,
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
  {
    path: "/admin/clients/projects",
    name: "projects",
    component: ProjectsListPage,
    meta: meta.projects,
  },
  {
    path: "/admin/clients/projects/:id",
    name: "project-detail",
    component: ProjectDetailPage,
    props: true,
    meta: meta.projectDetail,
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
  {
    path: "/admin/billing/return-bills",
    redirect: "/admin/billing/custom-bills",
  },
  {
    path: "/admin/billing/return-bills/:id",
    name: "billing-return-bill-detail",
    component: BillingReturnBillDetailPage,
    props: true,
    meta: meta.billingReturnBillDetail,
  },
  {
    path: "/admin/billing/asn-bills",
    redirect: "/admin/billing/custom-bills",
  },
  {
    path: "/admin/billing/asn-bills/:id",
    name: "billing-asn-bill-detail",
    component: BillingAsnBillDetailPage,
    props: true,
    meta: meta.billingAsnBillDetail,
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
  {
    path: "/admin/receiving/put-away",
    name: "admin-put-away",
    component: () => import("../pages/admin-put-away/AdminPutAwayListPage.vue"),
    meta: meta.adminPutAway,
  },
  {
    path: "/admin/receiving/put-away/:sku",
    name: "admin-put-away-detail",
    component: () => import("../pages/admin-put-away/PutAwayDetailPage.vue"),
    meta: meta.adminPutAwayDetail,
  },
  { path: "/admin/receiving", redirect: "/admin/receiving/asn" },
  {
    path: "/admin/returns/process",
    name: "admin-process-returns",
    component: () => import("../pages/admin-returns/AdminProcessReturnsSearchPage.vue"),
    meta: meta.adminProcessReturnsSearch,
  },
  {
    path: "/admin/returns/process/create/order/:shipheroOrderId",
    name: "admin-return-create",
    component: () => import("../pages/admin-returns/AdminReturnCreatePage.vue"),
    props: true,
    meta: meta.adminReturnCreate,
  },
  {
    path: "/admin/returns/process/:id",
    name: "admin-process-return-detail",
    component: () => import("../pages/admin-returns/AdminProcessReturnDetailPage.vue"),
    props: true,
    meta: meta.adminProcessReturnDetail,
  },
  {
    path: "/admin/returns/orders",
    name: "admin-returned-orders",
    component: () => import("../pages/admin-returns/AdminReturnedOrdersPage.vue"),
    meta: meta.adminReturnedOrders,
  },
  {
    path: "/admin/returns/items",
    name: "admin-returned-items",
    component: () => import("../pages/admin-returns/AdminReturnedItemsPage.vue"),
    meta: meta.adminReturnedItems,
  },
  {
    path: "/admin/returns/bins",
    name: "admin-return-bins",
    component: () => import("../pages/admin-returns/AdminReturnBinsListPage.vue"),
    meta: meta.adminReturnBins,
  },
  {
    path: "/admin/returns/bins/:binNumber",
    name: "admin-return-bin-detail",
    component: () => import("../pages/admin-returns/AdminReturnBinDetailPage.vue"),
    props: true,
    meta: meta.adminReturnBinDetail,
  },
  { path: "/admin/returns", redirect: "/admin/returns/process" },
  {
    path: "/admin/resources/tutorials",
    name: "resources-tutorials",
    component: () => import("../pages/resources/ResourcesTutorialsPage.vue"),
    meta: meta.resourcesTutorials,
  },
  {
    path: "/admin/resources/tutorials/:id",
    name: "resources-tutorial-detail",
    component: () => import("../pages/resources/ResourcesTutorialDetailPage.vue"),
    props: true,
    meta: meta.resourcesTutorialDetail,
  },
  {
    path: "/admin/resources/photos",
    name: "resources-photos",
    component: () => import("../pages/resources/ResourcesPhotosPage.vue"),
    meta: meta.resourcesPhotos,
  },
  {
    path: "/admin/resources/calendar/events",
    name: "resources-calendar-events",
    component: () => import("../pages/resources/ResourcesCalendarEventsListPage.vue"),
    meta: meta.resourcesCalendarEvents,
  },
  {
    path: "/admin/resources/calendar",
    name: "resources-calendar",
    component: () => import("../pages/resources/ResourcesCalendarPage.vue"),
    meta: meta.resourcesCalendar,
  },
  { path: "/admin/resources", redirect: "/admin/resources/tutorials" },
  {
    path: "/admin/inventory",
    name: "inventory",
    component: InventoryBetaListPage,
    meta: meta.inventory,
  },
  {
    path: "/admin/inventory/out-of-stock",
    name: "inventory-out-of-stock",
    component: () => import("../pages/user-inventory/UserInventoryOutOfStockPage.vue"),
    meta: meta.inventoryOutOfStock,
  },
  {
    path: "/admin/inventory/restock",
    name: "inventory-restock",
    component: InventoryRestockPage,
    meta: meta.inventoryRestock,
  },
  { path: "/admin/inventory/restock-beta", redirect: "/admin/inventory/restock" },
  { path: "/admin/inventory-beta", redirect: "/admin/inventory" },
  {
    path: "/admin/inventory-beta/:sku",
    redirect: (to) => ({
      path: `/admin/inventory/${encodeURIComponent(String(to.params.sku || ""))}`,
      query: to.query,
    }),
  },
  {
    path: "/admin/inventory/on-demand",
    name: "inventory-on-demand",
    component: InventoryOnDemandPage,
    meta: meta.inventoryOnDemand,
  },
  {
    path: "/admin/inventory/:sku",
    name: "inventory-detail",
    component: InventoryBetaDetailPage,
    props: true,
    meta: meta.inventoryDetail,
  },
  {
    path: "/admin/orders",
    redirect: "/admin/orders/search",
  },
  {
    path: "/admin/orders/create",
    name: "orders-create",
    component: () => import("../pages/orders/OrderCreatePage.vue"),
    meta: {
      title: "Save Rack | Orders | Draft Orders",
      description: "Local order drafts not yet sent to ShipHero.",
      requiresOrdersUpdate: true,
    },
  },
  {
    path: "/admin/orders/wholesale",
    name: "wholesale-orders",
    component: () => import("../pages/orders/WholesaleOrdersListPage.vue"),
    meta: meta.wholesaleOrdersList,
  },
  {
    path: "/admin/orders/wholesale/pick-list",
    name: "wholesale-pick-list",
    component: () => import("../pages/orders/WholesalePickListPage.vue"),
    meta: meta.wholesalePickList,
  },
  {
    path: "/admin/orders/wholesale/:id",
    name: "wholesale-order-detail",
    component: () => import("../pages/orders/WholesaleOrderDetailPage.vue"),
    meta: meta.wholesaleOrderDetail,
  },
  {
    path: "/admin/orders/search",
    name: "orders-search",
    component: OrdersListPage,
    meta: { ...meta.ordersSearch, orderTab: "search" },
  },
  {
    path: "/admin/orders/fulfillment",
    name: "orders-fulfillment",
    component: FulfillmentPage,
    meta: meta.ordersFulfillment,
  },
  {
    path: "/admin/orders/all",
    redirect: "/admin/orders/search",
  },
  {
    path: "/admin/orders/manage",
    redirect: "/admin/orders/search",
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
    component: () => import("../pages/orders/OrdersOnHoldOverviewPage.vue"),
    meta: meta.ordersOnHold,
  },
  {
    path: "/admin/orders/on-hold-old",
    name: "orders-on-hold-old",
    component: OrdersListPage,
    meta: { ...meta.ordersOnHoldLegacy, orderTab: "on_hold" },
  },
  {
    path: "/admin/orders/backorder",
    name: "orders-backorder",
    component: () => import("../pages/orders/OrdersBackorderOverviewPage.vue"),
    meta: {
      title: "Save Rack | Orders | Backorder",
      description: "Backorder orders and out-of-stock inventory by account.",
    },
  },
  {
    path: "/admin/orders/backorder/list",
    name: "orders-backorder-list",
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
    path: "/admin/settings/terms",
    name: "settings-terms",
    component: SettingsTermsPage,
    meta: meta.settingsTerms,
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
  {
    path: "/users/orders/create",
    name: "user-orders-create",
    component: () => import("../pages/orders/OrderCreatePage.vue"),
    meta: {
      title: "Save Rack | Orders | Draft Orders",
      description: "Local order drafts not yet sent to ShipHero.",
      userPortal: true,
    },
  },
  { path: "/users/orders/:shipheroOrderId", name: "user-order-detail", component: () => import("../pages/user-orders/UserOrderDetailPage.vue"), props: true, meta: { ...meta.orderDetail, userPortal: true } },
  { path: "/users/inventory/out-of-stock", name: "user-inventory-out-of-stock", component: () => import("../pages/user-inventory/UserInventoryOutOfStockPage.vue"), meta: { ...meta.userPortalInventoryOutOfStock, userPortal: true } },
  { path: "/users/inventory", name: "user-inventory", component: InventoryBetaListPage, meta: { ...meta.userPortalInventory, userPortal: true } },
  { path: "/users/inventory/:sku", name: "user-inventory-detail", component: InventoryBetaDetailPage, props: true, meta: { ...meta.userPortalInventoryDetail, userPortal: true } },
  { path: "/users/inventory-beta", redirect: "/users/inventory" },
  {
    path: "/users/inventory-beta/:sku",
    redirect: (to) => ({
      path: `/users/inventory/${encodeURIComponent(String(to.params.sku || ""))}`,
      query: to.query,
    }),
  },
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
  { path: "/tickets/board", redirect: "/admin/home" },
  { path: "/tickets/:id", redirect: "/admin/home" },
  { path: "/tickets", redirect: "/admin/home" },
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

/** Resources module: permissions from /auth/me (see setResourcesNavFromUser). */
let resourcesNavCache = null;

/** Orders module: permissions from /auth/me (see setOrdersNavFromUser). */
let ordersNavCache = null;

/** Inventory (ShipHero): permissions from /auth/me (see setInventoryNavFromUser). */
let inventoryNavCache = null;

/** Receiving (ASN / put-away): permissions from /auth/me (see setReceivingNavFromUser). */
let receivingNavCache = null;

/** Returns: permissions from /auth/me (see setReturnsNavFromUser). */
let returnsNavCache = null;

/** True when the signed-in CRM user is an administrator (`crmIsAdmin`); used for permissions routes only. */
let usersMeIsAdmin = false;
let authUserCache = null;

export function clearCrmOwnerCache() {
  webmasterNavCache = null;
  settingsNavCache = null;
  usersNavCache = null;
  clientsNavCache = null;
  billingNavCache = null;
  resourcesNavCache = null;
  ordersNavCache = null;
  inventoryNavCache = null;
  receivingNavCache = null;
  returnsNavCache = null;
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
      pages: {
        summary: true,
        invoices: true,
        customBills: true,
        asnBills: true,
        returnBills: true,
      },
    };
    return;
  }
  const k = Array.isArray(user.permission_keys) ? user.permission_keys : [];
  const page = (key) => k.includes(key) || k.includes("billing.view");
  billingNavCache = {
    view:
      page("billing_summary.view") ||
      page("billing_invoices.view") ||
      page("billing_custom_bills.view") ||
      page("billing_asn_bills.view") ||
      page("billing_return_bills.view") ||
      k.includes("billing.view"),
    create:
      k.includes("billing_invoices.create") ||
      k.includes("billing_custom_bills.create") ||
      k.includes("billing_asn_bills.create") ||
      k.includes("billing.create"),
    update:
      k.includes("billing_invoices.update") ||
      k.includes("billing_custom_bills.update") ||
      k.includes("billing_asn_bills.update") ||
      k.includes("billing_return_bills.update") ||
      k.includes("billing.update"),
    delete:
      k.includes("billing_invoices.delete") ||
      k.includes("billing_custom_bills.delete") ||
      k.includes("billing_asn_bills.delete") ||
      k.includes("billing_return_bills.delete") ||
      k.includes("billing.delete"),
    pages: {
      summary: page("billing_summary.view"),
      invoices: page("billing_invoices.view"),
      customBills:
        page("billing_custom_bills.view") ||
        page("billing_asn_bills.view") ||
        page("billing_return_bills.view"),
      asnBills: page("billing_asn_bills.view"),
      returnBills: page("billing_return_bills.view"),
    },
  };
}

export function setResourcesNavFromUser(user) {
  if (!user) {
    resourcesNavCache = null;
    return;
  }
  if (crmIsAdmin(user) || user.is_crm_owner) {
    resourcesNavCache = {
      view: true,
      create: true,
      update: true,
      delete: true,
      pages: {
        tutorials: true,
        photos: true,
        calendar: true,
        events: true,
      },
    };
    return;
  }
  const k = Array.isArray(user.permission_keys) ? user.permission_keys : [];
  const page = (key) => k.includes(key) || k.includes("resources.view");
  resourcesNavCache = {
    view:
      page("resources_tutorials.view") ||
      page("resources_photos.view") ||
      page("resources_calendar.view") ||
      page("resources_events.view") ||
      k.includes("resources.view"),
    create:
      k.includes("resources_tutorials.create") ||
      k.includes("resources_photos.create") ||
      k.includes("resources_calendar.create") ||
      k.includes("resources_events.create") ||
      k.includes("resources.create"),
    update:
      k.includes("resources_tutorials.update") ||
      k.includes("resources_calendar.update") ||
      k.includes("resources_events.update") ||
      k.includes("resources.update"),
    delete:
      k.includes("resources_tutorials.delete") ||
      k.includes("resources_photos.delete") ||
      k.includes("resources_events.delete") ||
      k.includes("resources.delete"),
    pages: {
      tutorials: page("resources_tutorials.view"),
      photos: page("resources_photos.view"),
      calendar: page("resources_calendar.view"),
      events: page("resources_events.view"),
    },
  };
}

export function setOrdersNavFromUser(user) {
  if (!user) {
    ordersNavCache = null;
    return;
  }
  if (crmIsAdmin(user) || user.is_crm_owner) {
    ordersNavCache = {
      view: true,
      update: true,
      pages: {
        search: true,
        fulfillment: true,
        awaiting: true,
        onHold: true,
        backorder: true,
        shipped: true,
        wholesale: true,
        create: true,
      },
    };
    return;
  }
  if (crmIsPortalUser(user)) {
    ordersNavCache = {
      view: true,
      update: false,
      pages: {
        search: true,
        fulfillment: true,
        awaiting: true,
        onHold: true,
        backorder: true,
        shipped: true,
        wholesale: true,
        create: false,
      },
    };
    return;
  }
  const k = Array.isArray(user.permission_keys) ? user.permission_keys : [];
  const page = (key) => k.includes(key) || k.includes("orders.view");
  ordersNavCache = {
    view:
      page("orders_search.view") ||
      page("orders_fulfillment.view") ||
      page("orders_awaiting.view") ||
      page("orders_on_hold.view") ||
      page("orders_backorder.view") ||
      page("orders_shipped.view") ||
      page("orders_wholesale.view") ||
      page("orders_create.view") ||
      k.includes("orders.view"),
    update:
      k.includes("orders_create.update") ||
      k.includes("orders_search.update") ||
      k.includes("orders_fulfillment.update") ||
      k.includes("orders_awaiting.update") ||
      k.includes("orders_on_hold.update") ||
      k.includes("orders_backorder.update") ||
      k.includes("orders_shipped.update") ||
      k.includes("orders_wholesale.update") ||
      k.includes("orders.update"),
    pages: {
      search: page("orders_search.view"),
      fulfillment: page("orders_fulfillment.view"),
      awaiting: page("orders_awaiting.view"),
      onHold: page("orders_on_hold.view"),
      backorder: page("orders_backorder.view"),
      shipped: page("orders_shipped.view"),
      wholesale: page("orders_wholesale.view"),
      create: k.includes("orders_create.create") || k.includes("orders_create.update") || k.includes("orders.update") || k.includes("orders.create"),
    },
  };
}

export function setInventoryNavFromUser(user) {
  if (!user) {
    inventoryNavCache = null;
    return;
  }
  if (crmIsAdmin(user) || user.is_crm_owner) {
    inventoryNavCache = {
      view: true,
      update: true,
      pages: {
        products: true,
        outOfStock: true,
        restock: true,
        onDemand: true,
      },
    };
    return;
  }
  if (crmIsPortalUser(user)) {
    inventoryNavCache = {
      view: true,
      update: true,
      pages: {
        products: true,
        outOfStock: true,
        restock: false,
        onDemand: false,
      },
    };
    return;
  }
  const k = Array.isArray(user.permission_keys) ? user.permission_keys : [];
  const page = (key) => k.includes(key) || k.includes("inventory.view");
  inventoryNavCache = {
    view:
      page("inventory_products.view") ||
      page("inventory_out_of_stock.view") ||
      page("inventory_restock.view") ||
      page("inventory_on_demand.view") ||
      k.includes("inventory.view"),
    update:
      k.includes("inventory_products.update") ||
      k.includes("inventory_out_of_stock.update") ||
      k.includes("inventory_restock.update") ||
      k.includes("inventory_on_demand.update") ||
      k.includes("inventory.update"),
    pages: {
      products: page("inventory_products.view"),
      outOfStock: page("inventory_out_of_stock.view"),
      restock: page("inventory_restock.view"),
      onDemand: page("inventory_on_demand.view"),
    },
  };
}

export function setReceivingNavFromUser(user) {
  if (!user) {
    receivingNavCache = null;
    return;
  }
  if (crmIsAdmin(user) || user.is_crm_owner) {
    receivingNavCache = {
      view: true,
      update: true,
      pages: { asn: true, putAway: true },
    };
    return;
  }
  if (crmIsPortalUser(user)) {
    receivingNavCache = {
      view: false,
      update: false,
      pages: { asn: false, putAway: false },
    };
    return;
  }
  const k = Array.isArray(user.permission_keys) ? user.permission_keys : [];
  const asn =
    k.includes("receiving_asn.view") || k.includes("receiving.view");
  const putAway =
    k.includes("receiving_put_away.view") || k.includes("receiving.view");
  receivingNavCache = {
    view: asn || putAway,
    update:
      k.includes("receiving_asn.update") ||
      k.includes("receiving_put_away.update") ||
      k.includes("receiving.update"),
    pages: { asn, putAway },
  };
}

export function setReturnsNavFromUser(user) {
  if (!user) {
    returnsNavCache = null;
    return;
  }
  if (crmIsAdmin(user) || user.is_crm_owner) {
    returnsNavCache = {
      view: true,
      update: true,
      pages: {
        process: true,
        orders: true,
        items: true,
        bins: true,
      },
    };
    return;
  }
  if (crmIsPortalUser(user)) {
    returnsNavCache = {
      view: true,
      update: false,
      pages: {
        process: true,
        orders: true,
        items: true,
        bins: true,
      },
    };
    return;
  }
  const k = Array.isArray(user.permission_keys) ? user.permission_keys : [];
  const page = (key) => k.includes(key) || k.includes("returns.view");
  returnsNavCache = {
    view:
      page("returns_process.view") ||
      page("returns_orders.view") ||
      page("returns_items.view") ||
      page("returns_bins.view") ||
      k.includes("returns.view"),
    update:
      k.includes("returns_process.update") ||
      k.includes("returns_orders.update") ||
      k.includes("returns_items.update") ||
      k.includes("returns_bins.update") ||
      k.includes("returns.update"),
    pages: {
      process: page("returns_process.view"),
      orders: page("returns_orders.view"),
      items: page("returns_items.view"),
      bins: page("returns_bins.view"),
    },
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
  setResourcesNavFromUser(data);
  setOrdersNavFromUser(data);
  setInventoryNavFromUser(data);
  setReceivingNavFromUser(data);
  setReturnsNavFromUser(data);
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
      setResourcesNavFromUser(data);
      setOrdersNavFromUser(data);
      setInventoryNavFromUser(data);
      setReceivingNavFromUser(data);
  setReturnsNavFromUser(data);
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
      setResourcesNavFromUser(data);
      setOrdersNavFromUser(data);
      setInventoryNavFromUser(data);
      setReceivingNavFromUser(data);
  setReturnsNavFromUser(data);
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
    setResourcesNavFromUser(data);
    setOrdersNavFromUser(data);
    setInventoryNavFromUser(data);
    setReceivingNavFromUser(data);
  setReturnsNavFromUser(data);
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
    setResourcesNavFromUser(data);
    setOrdersNavFromUser(data);
    setInventoryNavFromUser(data);
    setReceivingNavFromUser(data);
  setReturnsNavFromUser(data);
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
      setResourcesNavFromUser(data);
      setOrdersNavFromUser(data);
      setInventoryNavFromUser(data);
      setReceivingNavFromUser(data);
  setReturnsNavFromUser(data);
    } catch (e) {
      if (e.response?.status === 401) {
        localStorage.removeItem("auth_token");
        billingNavCache = null;
        resourcesNavCache = null;
        inventoryNavCache = null;
      }
      return false;
    }
  }
  if (path === "/admin/billing" || path.startsWith("/admin/billing/")) {
    const pages = billingNavCache.pages || {};
    if (path.startsWith("/admin/billing/summary")) return pages.summary === true;
    if (path.startsWith("/admin/billing/invoices")) return pages.invoices === true;
    if (path.startsWith("/admin/billing/custom-bills")) return pages.customBills === true;
    if (path.startsWith("/admin/billing/asn-bills")) return pages.asnBills === true;
    if (path.startsWith("/admin/billing/return-bills")) return pages.returnBills === true;
    return billingNavCache.view === true;
  }
  return true;
}

async function ensureResourcesRouteAccess(path) {
  if (resourcesNavCache === null) {
    try {
      const { data } = await api.get("/auth/me");
      setUsersNavFromUser(data);
      setClientsNavFromUser(data);
      setWebmasterNavFromUser(data);
      setSettingsNavFromUser(data);
      setBillingNavFromUser(data);
      setResourcesNavFromUser(data);
      setOrdersNavFromUser(data);
      setInventoryNavFromUser(data);
      setReceivingNavFromUser(data);
  setReturnsNavFromUser(data);
    } catch (e) {
      if (e.response?.status === 401) {
        localStorage.removeItem("auth_token");
        resourcesNavCache = null;
        billingNavCache = null;
        inventoryNavCache = null;
      }
      return false;
    }
  }
  if (path === "/admin/resources" || path.startsWith("/admin/resources/")) {
    const pages = resourcesNavCache.pages || {};
    if (path.startsWith("/admin/resources/tutorials")) return pages.tutorials === true;
    if (path.startsWith("/admin/resources/photos")) return pages.photos === true;
    if (path.startsWith("/admin/resources/calendar/events")) return pages.events === true;
    if (path.startsWith("/admin/resources/calendar")) return pages.calendar === true;
    return resourcesNavCache.view === true;
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
      setResourcesNavFromUser(data);
      setOrdersNavFromUser(data);
      setInventoryNavFromUser(data);
      setReceivingNavFromUser(data);
  setReturnsNavFromUser(data);
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
    path === "/admin/inventory-beta" ||
    path.startsWith("/admin/inventory-beta/") ||
    path === "/users/inventory" ||
    path.startsWith("/users/inventory/") ||
    path === "/users/inventory-beta" ||
    path.startsWith("/users/inventory-beta/") ||
    path === "/users/asn" ||
    path.startsWith("/users/asn/") ||
    path === "/users/dashboard" ||
    path.startsWith("/users/dashboard/")
  ) {
    if (path.startsWith("/users/")) {
      return inventoryNavCache.view === true;
    }
    const pages = inventoryNavCache.pages || {};
    if (path.startsWith("/admin/inventory/out-of-stock")) return pages.outOfStock === true;
    if (path.startsWith("/admin/inventory/restock")) return pages.restock === true;
    if (path.startsWith("/admin/inventory/on-demand")) return pages.onDemand === true;
    if (path === "/admin/inventory" || path.startsWith("/admin/inventory/")) {
      return pages.products === true || inventoryNavCache.view === true;
    }
    return inventoryNavCache.view === true;
  }
  return true;
}

async function ensureReturnsRouteAccess(path) {
  if (returnsNavCache === null) {
    try {
      const { data } = await api.get("/auth/me");
      setUsersNavFromUser(data);
      setClientsNavFromUser(data);
      setWebmasterNavFromUser(data);
      setSettingsNavFromUser(data);
      setBillingNavFromUser(data);
      setResourcesNavFromUser(data);
      setOrdersNavFromUser(data);
      setInventoryNavFromUser(data);
      setReceivingNavFromUser(data);
      setReturnsNavFromUser(data);
    } catch (e) {
      if (e.response?.status === 401) {
        localStorage.removeItem("auth_token");
        returnsNavCache = null;
        billingNavCache = null;
      }
      return false;
    }
  }
  if (path.startsWith("/admin/returns") || path.startsWith("/users/returns")) {
    if (path.startsWith("/users/returns")) {
      return returnsNavCache.view === true;
    }
    const pages = returnsNavCache.pages || {};
    if (path.startsWith("/admin/returns/process")) return pages.process === true;
    if (path.startsWith("/admin/returns/orders")) return pages.orders === true;
    if (path.startsWith("/admin/returns/items")) return pages.items === true;
    if (path.startsWith("/admin/returns/bins")) return pages.bins === true;
    return returnsNavCache.view === true;
  }
  return true;
}

async function ensureReceivingRouteAccess(path) {
  if (receivingNavCache === null) {
    try {
      const { data } = await api.get("/auth/me");
      setUsersNavFromUser(data);
      setClientsNavFromUser(data);
      setWebmasterNavFromUser(data);
      setSettingsNavFromUser(data);
      setBillingNavFromUser(data);
      setResourcesNavFromUser(data);
      setOrdersNavFromUser(data);
      setInventoryNavFromUser(data);
      setReceivingNavFromUser(data);
  setReturnsNavFromUser(data);
    } catch (e) {
      if (e.response?.status === 401) {
        localStorage.removeItem("auth_token");
        receivingNavCache = null;
      }
      return false;
    }
  }
  if (path.startsWith("/admin/receiving")) {
    if (path.startsWith("/admin/receiving/put-away")) {
      return receivingNavCache.pages?.putAway === true;
    }
    // ASN hub and detail (default receiving section)
    return receivingNavCache.pages?.asn === true;
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
      setResourcesNavFromUser(data);
      setOrdersNavFromUser(data);
      setInventoryNavFromUser(data);
      setReceivingNavFromUser(data);
  setReturnsNavFromUser(data);
    } catch (e) {
      if (e.response?.status === 401) {
        localStorage.removeItem("auth_token");
        ordersNavCache = null;
      }
      return false;
    }
  }
  if (path === "/admin/orders/create") {
    return ordersNavCache.pages?.create === true;
  }
  if (path.startsWith("/admin/orders") || path.startsWith("/users/orders")) {
    const pages = ordersNavCache.pages || {};
    if (path.startsWith("/admin/orders/fulfillment")) return pages.fulfillment === true;
    if (path.startsWith("/admin/orders/awaiting")) return pages.awaiting === true;
    if (path.startsWith("/admin/orders/on-hold")) return pages.onHold === true;
    if (path.startsWith("/admin/orders/backorder")) return pages.backorder === true;
    if (path.startsWith("/admin/orders/shipped")) return pages.shipped === true;
    if (path.startsWith("/admin/orders/wholesale")) return pages.wholesale === true;
    if (path.startsWith("/admin/orders/search") || path === "/admin/orders") {
      return pages.search === true;
    }
    // Order detail / other paths: any orders view
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
        return { path: crmIsPortalUser(me) ? crmPortalPostAuthPath(me) : "/admin/home" };
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
    return { path: "/admin/home" };
  }

  if (to.path.startsWith("/admin/settings")) {
    const ok = await ensureSettingsRouteAccess();
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/admin/home" };
    }
  }

  if (to.path === "/admin/webmaster" || to.path.startsWith("/admin/webmaster/")) {
    const ok = await ensureWebmasterRouteAccess();
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/admin/home" };
    }
  }

  if (to.path.startsWith("/admin/staff")) {
    const ok = await ensureUsersRouteAccess(to.path);
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/admin/home" };
    }
  }

  if (to.path.startsWith("/admin/clients")) {
    const ok = await ensureClientsRouteAccess(to.path);
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/admin/home" };
    }
  }

  if (to.path.startsWith("/admin/billing")) {
    const ok = await ensureBillingRouteAccess(to.path);
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/admin/home" };
    }
  }

  if (to.path.startsWith("/admin/resources")) {
    const ok = await ensureResourcesRouteAccess(to.path);
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/admin/home" };
    }
  }

  if (to.path.startsWith("/admin/orders") || to.path.startsWith("/users/orders")) {
    const ok = await ensureOrdersRouteAccess(to.path);
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: crmIsPortalUser(me) ? "/users/dashboard" : "/admin/home" };
    }
  }

  if (to.path.startsWith("/admin/receiving")) {
    const ok = await ensureReceivingRouteAccess(to.path);
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/admin/home" };
    }
  }

  if (
    to.path.startsWith("/admin/inventory") ||
    to.path.startsWith("/users/inventory") ||
    to.path.startsWith("/users/inventory-beta") ||
    to.path.startsWith("/users/asn")
  ) {
    const ok = await ensureInventoryRouteAccess(to.path);
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: crmIsPortalUser(me) ? "/users/dashboard" : "/admin/home" };
    }
  }

  if (to.path.startsWith("/admin/returns") || to.path.startsWith("/users/returns")) {
    const ok = await ensureReturnsRouteAccess(to.path);
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: crmIsPortalUser(me) ? "/users/dashboard" : "/admin/home" };
    }
  }

  if (to.path === "/users/dashboard" || to.path.startsWith("/users/dashboard/")) {
    const ok = await ensureInventoryRouteAccess(to.path);
    if (!ok) {
      if (!localStorage.getItem("auth_token")) {
        return { name: "login", query: { redirect: to.fullPath } };
      }
      return { path: "/users/dashboard" };
    }
  }

  return true;
});

router.afterEach((to) => {
  applyRouteMeta(to);
});

export default router;
