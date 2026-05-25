<script setup>
import { computed, inject, onMounted, onUnmounted, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import PortalOnboardingAccountModal from "../../components/user-portal/PortalOnboardingAccountModal.vue";
import PortalOnboardingBillingModal from "../../components/user-portal/PortalOnboardingBillingModal.vue";
import PortalOnboardingSectionModal from "../../components/user-portal/PortalOnboardingSectionModal.vue";
import { useToast } from "../../composables/useToast";
import { PORTAL_MATERIAL_ICON } from "../../constants/portalMaterialIcons.js";
import {
  PORTAL_ONBOARDING_SECTION_IDS,
  PORTAL_ONBOARDING_TASK_ICON_KEYS,
} from "../../constants/portalOnboardingSections.js";

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
const activeSectionId = ref("");

const profile = computed(() => onboarding.value?.profile || null);
const tasks = computed(() => onboarding.value?.tasks || []);
const preferences = computed(() => onboarding.value?.preferences || {});
const brandLogoUrl = computed(() => onboarding.value?.brand_logo_url || "");
const progress = computed(() => onboarding.value?.progress || { total: 11, completed: 0, remaining: 11 });
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

function recalcProgress(taskList) {
  const total = taskList.length || 11;
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
      toast.info("Payment setup was cancelled.");
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
      <div class="portal-welcome-page__stack d-flex flex-column gap-3 gap-md-4">
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

        <div class="staff-stat-card portal-welcome-page__progress w-100">
          <p class="staff-stat-card__label">Your progress</p>
          <p class="staff-stat-card__value">
            {{ progress.completed }} of {{ progress.total }}
          </p>
          <p class="staff-stat-card__sub">Onboarding tasks complete</p>
          <div
            class="staff-stat-card__icon user-dashboard-stat-icon portal-welcome-page__progress-icon"
            :style="PROGRESS_ICON_STYLE"
            aria-hidden="true"
          >
            <svg class="user-dashboard-stat-svg" fill="currentColor" viewBox="0 0 24 24">
              <path :d="PORTAL_MATERIAL_ICON.hourglass" />
            </svg>
          </div>
        </div>

        <p
          v-if="refreshingBilling"
          class="small text-secondary mb-0"
        >
          Refreshing payment status…
        </p>

        <div class="portal-onboard-tasks d-flex flex-column gap-3 w-100">
          <button
            v-for="task in tasks"
            :key="task.id"
            type="button"
            class="portal-onboard-task staff-table-card staff-datatable-card--white text-start border-0 w-100 p-3 p-md-4"
            @click="openTask(task)"
          >
            <div class="portal-onboard-task__grid">
              <div class="portal-onboard-task__lead d-flex align-items-start gap-3 min-w-0">
                <div
                  class="portal-onboard-task__icon flex-shrink-0"
                  aria-hidden="true"
                >
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

        <div class="staff-table-card staff-datatable-card--white p-3 p-md-4 portal-welcome-page__support w-100">
          <div class="d-flex align-items-start gap-3">
            <div
              class="portal-welcome-page__panel-icon portal-welcome-page__panel-icon--support flex-shrink-0"
              aria-hidden="true"
            >
              <svg class="portal-welcome-page__icon-svg" fill="currentColor" viewBox="0 0 24 24">
                <path :d="PORTAL_MATERIAL_ICON.supportAgent" />
              </svg>
            </div>
            <div class="min-w-0">
              <h2 class="h6 fw-semibold mb-2">Support</h2>
              <p class="small text-secondary mb-2">
                Questions about onboarding? Contact your Save Rack account manager or email
                <a href="mailto:support@saverack.com" class="auth-vuexy-link">support@saverack.com</a>.
              </p>
              <RouterLink
                to="/users/support"
                class="small auth-vuexy-link text-decoration-none"
              >
                Visit Support
              </RouterLink>
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
      :manual-instructions="manualInstructions"
      @saved="onBillingSaved"
    />
    <PortalOnboardingSectionModal
      v-model:open="sectionModalOpen"
      :section-id="activeSectionId"
      :preferences="preferences"
      :brand-logo-url="brandLogoUrl"
      @saved="onSectionSaved"
    />
  </div>
</template>

<style scoped>
.portal-welcome-page__stack {
  width: 100%;
}

.portal-onboard-task {
  cursor: pointer;
  transition: box-shadow 0.15s ease;
}

.portal-onboard-task:hover {
  box-shadow: 0 0.25rem 0.75rem rgba(15, 23, 42, 0.08);
}

.portal-onboard-task__grid {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  gap: 0.75rem 1rem;
}

.portal-onboard-task__status-wrap {
  grid-column: 2;
  justify-self: center;
}

.portal-onboard-task__lead {
  grid-column: 1 / -1;
}

@media (max-width: 575.98px) {
  .portal-onboard-task__grid {
    grid-template-columns: 1fr;
  }

  .portal-onboard-task__status-wrap {
    grid-column: 1;
    justify-self: center;
    margin-top: 0.25rem;
  }
}

@media (min-width: 576px) {
  .portal-onboard-task__lead {
    grid-column: 1;
  }

  .portal-onboard-task__status-wrap {
    grid-column: 2;
  }
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
  position: relative;
}

.portal-welcome-page__progress .staff-stat-card__value {
  font-size: 1.375rem;
  margin-top: 0.15rem;
}

.portal-welcome-page__progress .staff-stat-card__label {
  font-size: 0.875rem;
}

.portal-welcome-page__progress .staff-stat-card__sub {
  margin-top: 0.2rem;
  font-size: 0.8125rem;
}

.portal-welcome-page__progress-icon {
  top: 50%;
  right: 1.125rem;
  transform: translateY(-50%);
  width: 2.875rem;
  height: 2.875rem;
  border-radius: 0.4375rem;
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

.portal-welcome-page__panel-icon .portal-welcome-page__icon-svg {
  width: 1.4375rem;
  height: 1.4375rem;
}
</style>
