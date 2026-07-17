<script setup>
import { computed, inject, onMounted, onUnmounted, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import PortalOnboardingAccountModal from "../../components/user-portal/PortalOnboardingAccountModal.vue";
import PortalOnboardingBillingModal from "../../components/user-portal/PortalOnboardingBillingModal.vue";
import PortalOnboardingSectionModal from "../../components/user-portal/PortalOnboardingSectionModal.vue";
import PortalFulfillmentAgreementModal from "../../components/user-portal/PortalFulfillmentAgreementModal.vue";
import PortalFulfillmentPricingModal from "../../components/user-portal/PortalFulfillmentPricingModal.vue";
import { useToast } from "../../composables/useToast";
import { PORTAL_MATERIAL_ICON } from "../../constants/portalMaterialIcons.js";
import {
  PORTAL_ONBOARDING_SECTION_IDS,
  PORTAL_ONBOARDING_TASK_ICON_KEYS,
} from "../../constants/portalOnboardingSections.js";
import { parseCalendarDay } from "../../utils/formatUserDates.js";

const router = useRouter();
const route = useRoute();
const toast = useToast();
const crmUser = inject("crmUser", ref(null));

const loading = ref(true);
const refreshingBilling = ref(false);
const onboarding = ref(null);
const accountModalOpen = ref(false);
const billingModalOpen = ref(false);
const sectionModalOpen = ref(false);
const agreementModalOpen = ref(false);
const pricingModalOpen = ref(false);
const activeSectionId = ref("");

const profile = computed(() => onboarding.value?.profile || null);
const tasks = computed(() => onboarding.value?.tasks || []);
const preferences = computed(() => onboarding.value?.preferences || {});
const brandLogoUrl = computed(() => onboarding.value?.brand_logo_url || "");
const progress = computed(() => onboarding.value?.progress || { total: 11, completed: 0, remaining: 11 });
const fulfillmentAgreement = computed(() => onboarding.value?.fulfillment_agreement || null);
const fulfillmentPricing = computed(() => onboarding.value?.fulfillment_pricing || null);
const agreementCompleted = computed(
  () => fulfillmentAgreement.value?.status === "completed",
);
const agreementDefaultCompany = computed(() => profile.value?.company_name || "");
const agreementDefaultRepName = computed(() => {
  const name = String(profile.value?.name || "").trim();
  if (name) return name;
  return [
    String(profile.value?.contact_first_name || "").trim(),
    String(profile.value?.contact_last_name || "").trim(),
  ]
    .filter(Boolean)
    .join(" ");
});
const agreementAcceptedLabel = computed(() => {
  const raw = fulfillmentAgreement.value?.accepted_at;
  const d = parseCalendarDay(raw) || (raw ? new Date(raw) : null);
  if (!d || Number.isNaN(d.getTime())) return "";
  const formatted = new Intl.DateTimeFormat("en-US", {
    month: "long",
    day: "numeric",
    year: "numeric",
  }).format(d);
  return `Agreement accepted on ${formatted}`;
});
const manualInstructions = computed(
  () => onboarding.value?.manual_payment_instructions || null,
);

/** Portal dashboard stat tiles: light blue tile, dark blue icon */
const PROGRESS_ICON_STYLE = { background: "#dbeafe", color: "#1e3a8a" };

function statusLabel(status) {
  if (status === "completed") return "Completed";
  if (status === "processing") return "Processing";
  return "Not Completed";
}

function statusClass(status) {
  if (status === "completed") return "portal-onboard-status--completed";
  if (status === "processing") return "portal-onboard-status--processing";
  return "portal-onboard-status--pending";
}

function taskIconPath(task) {
  const key = PORTAL_ONBOARDING_TASK_ICON_KEYS[task?.icon] || task?.icon || "account";
  return PORTAL_MATERIAL_ICON[key] || PORTAL_MATERIAL_ICON.account;
}

function openTask(task) {
  if (!task || typeof task !== "object") return;
  if (task.id === "account_information") {
    accountModalOpen.value = true;
    return;
  }
  if (task.id === "billing_information") {
    billingModalOpen.value = true;
    return;
  }
  if (task.id === "fulfillment_agreement") {
    agreementModalOpen.value = true;
    return;
  }
  if (task.id === "fulfillment_pricing") {
    pricingModalOpen.value = true;
    return;
  }
  if (PORTAL_ONBOARDING_SECTION_IDS.includes(task.id)) {
    activeSectionId.value = task.id;
    sectionModalOpen.value = true;
  }
}

function applyOnboardingPayload(data) {
  if (data && typeof data === "object") {
    onboarding.value = data;
  }
}

function onAccountSaved(profileData) {
  if (onboarding.value?.profile) {
    onboarding.value = {
      ...onboarding.value,
      profile: { ...onboarding.value.profile, ...profileData },
      tasks: (onboarding.value.tasks || []).map((t) =>
        t.id === "account_information"
          ? {
              ...t,
              status: profileData?.account_information_complete ? "completed" : "not_completed",
            }
          : t,
      ),
      progress: recalcProgress(
        (onboarding.value.tasks || []).map((t) =>
          t.id === "account_information"
            ? {
                ...t,
                status: profileData?.account_information_complete ? "completed" : "not_completed",
              }
            : t,
        ),
      ),
    };
  }
  loadOnboarding({ quiet: true });
}

function onSectionSaved(data) {
  applyOnboardingPayload(data);
}

function onBillingSaved(data) {
  applyOnboardingPayload(data);
  loadOnboarding({ quiet: true });
}

function onAgreementAccepted(data) {
  applyOnboardingPayload(data);
}

function onPricingAccepted(data) {
  applyOnboardingPayload(data);
}

function openAgreementModal() {
  agreementModalOpen.value = true;
}

function recalcProgress(taskList) {
  const total = taskList.length || 8;
  const completed = taskList.filter((t) => t.status === "completed").length;
  return { total, completed, remaining: total - completed };
}

async function loadOnboarding({ quiet = false } = {}) {
  if (!quiet) loading.value = true;
  try {
    const { data } = await api.get("/portal/onboarding");
    applyOnboardingPayload(data);
  } catch (e) {
    toast.errorFrom(e, "Could not load onboarding.");
  } finally {
    if (!quiet) loading.value = false;
  }
}

async function refreshAfterBillingReturn() {
  refreshingBilling.value = true;
  let attempts = 0;
  const poll = async () => {
    attempts += 1;
    await loadOnboarding({ quiet: true });
    const billingTask = (onboarding.value?.tasks || []).find(
      (t) => t.id === "billing_information",
    );
    const done =
      billingTask?.status === "completed" || billingTask?.status === "processing";
    if (done || attempts >= 8) {
      refreshingBilling.value = false;
      if (route.query.billing) {
        router.replace({ path: "/users/welcome", query: {} });
      }
      return;
    }
    window.setTimeout(poll, 2000);
  };
  await poll();
}

function onWindowFocus() {
  if (document.visibilityState === "visible") {
    loadOnboarding({ quiet: true });
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Account setup",
    description: "Complete your account onboarding checklist.",
  });
  document.addEventListener("visibilitychange", onWindowFocus);
  window.addEventListener("focus", onWindowFocus);
  loadOnboarding().then(() => {
    const billing = String(route.query.billing || "");
    if (billing === "success") {
      refreshAfterBillingReturn();
    } else if (billing === "cancel") {
      toast.warning("Payment setup was cancelled.");
      router.replace({ path: "/users/welcome", query: {} });
    }
  });
});

onUnmounted(() => {
  document.removeEventListener("visibilitychange", onWindowFocus);
  window.removeEventListener("focus", onWindowFocus);
});
</script>

<template>
  <div class="staff-page staff-page--wide portal-welcome-page">
    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner />
    </div>

    <template v-else>
      <div class="portal-welcome-layout row g-3 g-lg-4">
        <div class="col-lg-8">
          <div class="d-flex flex-column gap-3 gap-md-4">
            <div class="staff-table-card staff-datatable-card--white p-4 p-md-4 portal-welcome-page__intro">
              <h1 class="h4 fw-semibold mb-3">Welcome to Save Rack Fulfillment!</h1>
              <p class="text-secondary mb-2">
                Your account has been created, but a few setup steps are required before we can start
                receiving inventory and shipping orders for your store.
              </p>
              <p class="text-secondary mb-0">
                Please complete the onboarding checklist below so our team can prepare your account,
                connect your store, and make sure your fulfillment process is set up correctly.
              </p>
            </div>

            <p v-if="refreshingBilling" class="small text-secondary mb-0">
              Refreshing payment status…
            </p>

            <div class="portal-onboard-tasks d-flex flex-column gap-3">
              <button
                v-for="task in tasks"
                :key="task.id"
                type="button"
                class="portal-onboard-task staff-table-card staff-datatable-card--white text-start border-0 w-100 p-3 p-md-4"
                @click="openTask(task)"
              >
                <div class="portal-onboard-task__grid">
                  <div class="portal-onboard-task__lead d-flex align-items-start gap-3 min-w-0">
                    <div class="portal-onboard-task__icon flex-shrink-0" aria-hidden="true">
                      <svg class="portal-onboard-task__icon-svg" fill="currentColor" viewBox="0 0 24 24">
                        <path :d="taskIconPath(task)" />
                      </svg>
                    </div>
                    <div class="min-w-0">
                      <h2 class="h6 fw-semibold mb-1">{{ task.title }}</h2>
                      <p class="small text-secondary mb-0">{{ task.description }}</p>
                    </div>
                  </div>
                  <div class="portal-onboard-task__status-wrap">
                    <span
                      class="portal-onboard-status badge rounded-pill"
                      :class="statusClass(task.status)"
                    >
                      {{ statusLabel(task.status) }}
                    </span>
                  </div>
                </div>
              </button>
            </div>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="portal-welcome-sidebar d-flex flex-column gap-3">
            <div class="staff-table-card staff-datatable-card--white p-3 p-md-4 portal-welcome-page__account-status">
              <div class="d-flex align-items-center justify-content-between gap-3">
                <div class="min-w-0">
                  <h2 class="h6 fw-semibold mb-2">Account Status: Onboarding</h2>
                  <p class="small text-secondary mb-0">
                    Your account is currently in the onboarding stage. Once all onboarding tasks have been completed
                    and verified by your account manager, you will receive a notification confirming that your account
                    is active and ready for all fulfillment services.
                  </p>
                </div>
                <div
                  class="portal-welcome-page__panel-icon portal-welcome-page__panel-icon--account-status flex-shrink-0"
                  aria-hidden="true"
                >
                  <svg class="portal-welcome-page__icon-svg" fill="currentColor" viewBox="0 0 24 24">
                    <path :d="PORTAL_MATERIAL_ICON.account" />
                  </svg>
                </div>
              </div>
            </div>
            <div class="staff-stat-card portal-welcome-page__progress">
              <div class="portal-welcome-page__progress-body">
                <div class="portal-welcome-page__progress-copy min-w-0">
                  <p class="staff-stat-card__label">Your progress</p>
                  <p class="staff-stat-card__value">
                    {{ progress.completed }} of {{ progress.total }}
                  </p>
                  <p class="staff-stat-card__sub">Onboarding tasks complete</p>
                </div>
                <div
                  class="portal-welcome-page__progress-icon"
                  :style="PROGRESS_ICON_STYLE"
                  aria-hidden="true"
                >
                  <svg class="portal-welcome-page__progress-icon-svg" fill="currentColor" viewBox="0 0 24 24">
                    <path :d="PORTAL_MATERIAL_ICON.hourglass" />
                  </svg>
                </div>
              </div>
            </div>

            <div
              class="staff-table-card staff-datatable-card--white p-3 p-md-4 portal-fulfillment-agreement"
              :class="{
                'portal-fulfillment-agreement--completed': agreementCompleted,
                'portal-fulfillment-agreement--pending': !agreementCompleted,
              }"
            >
              <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                <div class="min-w-0">
                  <h2 class="h6 fw-semibold mb-2">Fulfillment Agreement</h2>
                  <p class="small text-secondary mb-0">
                    Download, upload, or e-sign our fulfillment agreement to proceed with your onboarding.
                  </p>
                </div>
                <div
                  class="portal-welcome-page__panel-icon portal-fulfillment-agreement__doc-icon flex-shrink-0"
                  aria-hidden="true"
                >
                  <svg class="portal-welcome-page__icon-svg" fill="currentColor" viewBox="0 0 24 24">
                    <path :d="PORTAL_MATERIAL_ICON.description" />
                  </svg>
                </div>
              </div>

              <div class="portal-fulfillment-agreement__status d-flex align-items-start gap-2 mb-3">
                <span
                  class="portal-fulfillment-agreement__status-icon flex-shrink-0"
                  aria-hidden="true"
                >
                  <svg
                    v-if="agreementCompleted"
                    class="portal-fulfillment-agreement__status-svg"
                    fill="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"
                    />
                  </svg>
                  <svg
                    v-else
                    class="portal-fulfillment-agreement__status-svg"
                    fill="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path :d="PORTAL_MATERIAL_ICON.cancel" />
                  </svg>
                </span>
                <div class="min-w-0">
                  <p
                    class="portal-fulfillment-agreement__status-label mb-0 fw-semibold"
                  >
                    {{ agreementCompleted ? "Completed" : "Not Completed" }}
                  </p>
                  <p
                    v-if="agreementCompleted && agreementAcceptedLabel"
                    class="small text-secondary mb-0 mt-1"
                  >
                    {{ agreementAcceptedLabel }}
                  </p>
                </div>
              </div>

              <button
                type="button"
                class="portal-fulfillment-agreement__action w-100"
                @click="openAgreementModal"
              >
                <span class="d-inline-flex align-items-center gap-2 min-w-0">
                  <svg
                    class="portal-fulfillment-agreement__action-icon flex-shrink-0"
                    fill="currentColor"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                  >
                    <path :d="PORTAL_MATERIAL_ICON.description" />
                  </svg>
                  <span class="fw-semibold">View Agreement</span>
                </span>
                <svg
                  class="portal-fulfillment-agreement__chevron flex-shrink-0"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path :d="PORTAL_MATERIAL_ICON.chevronRight" />
                </svg>
              </button>
            </div>

            <div class="staff-table-card staff-datatable-card--white p-3 p-md-4 portal-welcome-page__support">
              <div class="d-flex align-items-center justify-content-between gap-3">
                <div class="min-w-0">
                  <h2 class="h6 fw-semibold mb-2">Support</h2>
                  <p class="small text-secondary mb-2">
                    Questions about onboarding? Contact your Save Rack account manager or email
                    <a href="mailto:support@saverack.com" class="auth-vuexy-link">support@saverack.com</a>.
                  </p>
                  <RouterLink to="/users/support" class="small auth-vuexy-link text-decoration-none">
                    Visit Support
                  </RouterLink>
                </div>
                <div
                  class="portal-welcome-page__panel-icon portal-welcome-page__panel-icon--support flex-shrink-0"
                  aria-hidden="true"
                >
                  <svg class="portal-welcome-page__icon-svg" fill="currentColor" viewBox="0 0 24 24">
                    <path :d="PORTAL_MATERIAL_ICON.supportAgent" />
                  </svg>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <PortalOnboardingAccountModal
      v-model:open="accountModalOpen"
      :profile="profile"
      @saved="onAccountSaved"
    />
    <PortalOnboardingBillingModal
      v-model:open="billingModalOpen"
      :profile="profile"
      :manual-instructions="manualInstructions"
      @saved="onBillingSaved"
    />
    <PortalOnboardingSectionModal
      v-model:open="sectionModalOpen"
      :section-id="activeSectionId"
      :preferences="preferences"
      :brand-logo-url="brandLogoUrl"
      :profile="profile"
      @saved="onSectionSaved"
    />
    <PortalFulfillmentAgreementModal
      v-model:open="agreementModalOpen"
      :agreement="fulfillmentAgreement"
      :default-company="agreementDefaultCompany"
      :default-rep-name="agreementDefaultRepName"
      @accepted="onAgreementAccepted"
    />
    <PortalFulfillmentPricingModal
      v-model:open="pricingModalOpen"
      :pricing="fulfillmentPricing"
      @accepted="onPricingAccepted"
    />
  </div>
</template>

<style scoped>
.portal-welcome-layout {
  width: 100%;
  align-items: flex-start;
}

@media (min-width: 992px) {
  .portal-welcome-sidebar {
    position: sticky;
    top: 1rem;
  }
}

.portal-onboard-task {
  cursor: pointer;
  transition: box-shadow 0.15s ease;
}

.portal-onboard-task:hover {
  box-shadow: 0 0.25rem 0.75rem rgba(15, 23, 42, 0.08);
}

.portal-onboard-task__grid {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem 1rem;
}

.portal-onboard-task__status-wrap {
  flex-shrink: 0;
  display: flex;
  justify-content: flex-end;
  margin-left: 0.5rem;
  padding-top: 0.125rem;
}

.portal-onboard-task__lead {
  flex: 1 1 auto;
  min-width: 0;
}

.portal-onboard-task__icon {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.5rem;
  background: rgba(var(--bs-primary-rgb), 0.12);
  color: var(--bs-primary);
  display: flex;
  align-items: center;
  justify-content: center;
}

.portal-onboard-task__icon-svg {
  width: 1.35rem;
  height: 1.35rem;
  display: block;
}

.portal-onboard-status--pending {
  background: rgba(234, 84, 85, 0.12);
  color: #ea5455;
}

.portal-onboard-status--completed {
  background: rgba(40, 199, 111, 0.12);
  color: #28c76f;
}

.portal-onboard-status--processing {
  background: rgba(255, 159, 67, 0.15);
  color: #ff9f43;
}

.portal-welcome-page__icon-svg {
  width: 1.25rem;
  height: 1.25rem;
  display: block;
  overflow: visible;
}

.portal-welcome-page__progress {
  padding: 0.875rem 1rem;
  min-height: 4.5rem;
}

.portal-welcome-page__progress-body {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.portal-welcome-page__progress-copy .staff-stat-card__value {
  font-size: 1.375rem;
  margin-top: 0.15rem;
}

.portal-welcome-page__progress-copy .staff-stat-card__label {
  font-size: 0.875rem;
}

.portal-welcome-page__progress-copy .staff-stat-card__sub {
  margin-top: 0.2rem;
  font-size: 0.8125rem;
}

.portal-welcome-page__progress-icon {
  flex-shrink: 0;
  width: 2.875rem;
  height: 2.875rem;
  border-radius: 0.4375rem;
  display: flex;
  align-items: center;
  justify-content: center;
}

.portal-welcome-page__progress-icon-svg {
  width: 1.4375rem;
  height: 1.4375rem;
  display: block;
  overflow: visible;
}

.portal-welcome-page__panel-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 0.4375rem;
}

.portal-welcome-page__panel-icon--support {
  background: rgba(37, 99, 235, 0.12);
  color: #2563eb;
}

.portal-welcome-page__panel-icon--account-status {
  background: rgba(245, 158, 11, 0.14);
  color: #b45309;
}

.portal-welcome-page__panel-icon .portal-welcome-page__icon-svg {
  width: 1.4375rem;
  height: 1.4375rem;
}

.portal-fulfillment-agreement__doc-icon {
  background: rgba(40, 199, 111, 0.14);
  color: #28c76f;
}

.portal-fulfillment-agreement__status-icon {
  width: 1.25rem;
  height: 1.25rem;
  margin-top: 0.1rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.portal-fulfillment-agreement__status-svg {
  width: 1.25rem;
  height: 1.25rem;
  display: block;
}

.portal-fulfillment-agreement--completed .portal-fulfillment-agreement__status-icon,
.portal-fulfillment-agreement--completed .portal-fulfillment-agreement__status-label {
  color: #28c76f;
}

.portal-fulfillment-agreement--pending .portal-fulfillment-agreement__status-icon,
.portal-fulfillment-agreement--pending .portal-fulfillment-agreement__status-label {
  color: #ea5455;
}

.portal-fulfillment-agreement__action {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 0.9rem;
  border: 1px solid rgba(47, 43, 61, 0.12);
  border-radius: 0.5rem;
  background: #fff;
  color: #2f2b3d;
  text-align: left;
}

.portal-fulfillment-agreement__action:hover {
  background: #f8f9fb;
}

.portal-fulfillment-agreement__action-icon {
  width: 1.125rem;
  height: 1.125rem;
  color: #2573ba;
}

.portal-fulfillment-agreement__chevron {
  width: 1.125rem;
  height: 1.125rem;
  color: #9ca3af;
}
</style>
