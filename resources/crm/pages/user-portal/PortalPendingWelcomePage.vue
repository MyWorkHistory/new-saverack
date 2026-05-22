<script setup>
import { computed, inject, onMounted, onUnmounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import PortalOnboardingAccountModal from "../../components/user-portal/PortalOnboardingAccountModal.vue";
import PortalOnboardingBillingModal from "../../components/user-portal/PortalOnboardingBillingModal.vue";
import { useToast } from "../../composables/useToast";

const router = useRouter();
const route = useRoute();
const toast = useToast();
const crmUser = inject("crmUser", ref(null));

const loading = ref(true);
const refreshingBilling = ref(false);
const onboarding = ref(null);
const accountModalOpen = ref(false);
const billingModalOpen = ref(false);

const profile = computed(() => onboarding.value?.profile || null);
const tasks = computed(() => onboarding.value?.tasks || []);
const progress = computed(() => onboarding.value?.progress || { total: 2, completed: 0, remaining: 2 });
const manualInstructions = computed(
  () => onboarding.value?.manual_payment_instructions || null,
);

function signOut() {
  localStorage.removeItem("auth_token");
  router.push("/login");
}

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

function taskIcon(task) {
  if (task?.icon === "billing") {
    return "billing";
  }
  return "account";
}

function openTask(task) {
  if (!task || typeof task !== "object") return;
  if (task.id === "account_information") {
    accountModalOpen.value = true;
    return;
  }
  if (task.id === "billing_information") {
    billingModalOpen.value = true;
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

function onBillingSaved(data) {
  applyOnboardingPayload(data);
  loadOnboarding({ quiet: true });
}

function recalcProgress(taskList) {
  const total = taskList.length || 2;
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

let billingPollTimer = null;

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Account setup",
    description: "Complete your account onboarding checklist.",
  });
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
  if (billingPollTimer) window.clearTimeout(billingPollTimer);
});
</script>

<template>
  <div class="staff-page staff-page--wide portal-welcome-page">
    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner />
    </div>

    <template v-else>
      <div class="row g-4">
        <div class="col-lg-8">
          <div class="portal-welcome-page__intro mb-4">
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

          <p
            v-if="refreshingBilling"
            class="small text-secondary mb-3"
          >
            Refreshing payment status…
          </p>

          <div class="portal-onboard-tasks d-flex flex-column gap-3">
            <button
              v-for="task in tasks"
              :key="task.id"
              type="button"
              class="portal-onboard-task staff-table-card text-start border-0 w-100 p-3 p-md-4"
              @click="openTask(task)"
            >
              <div class="d-flex align-items-start gap-3">
                <div
                  class="portal-onboard-task__icon flex-shrink-0"
                  :class="`portal-onboard-task__icon--${taskIcon(task)}`"
                  aria-hidden="true"
                />
                <div class="flex-grow-1 min-w-0">
                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
                    <h2 class="h6 fw-semibold mb-0">{{ task.title }}</h2>
                    <span
                      class="portal-onboard-status badge rounded-pill"
                      :class="statusClass(task.status)"
                    >
                      {{ statusLabel(task.status) }}
                    </span>
                  </div>
                  <p class="small text-secondary mb-0">{{ task.description }}</p>
                </div>
              </div>
            </button>
          </div>

          <div class="mt-4">
            <button
              type="button"
              class="btn btn-outline-secondary orders-toolbar-outline-btn"
              @click="signOut"
            >
              Sign Out
            </button>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="staff-table-card p-4 mb-3 portal-welcome-page__progress">
            <h2 class="h6 fw-semibold mb-2">Your progress</h2>
            <p class="display-6 fw-semibold mb-1">
              {{ progress.completed }} of {{ progress.total }}
            </p>
            <p class="small text-secondary mb-0">tasks complete</p>
          </div>

          <div class="staff-table-card p-4 portal-welcome-page__help">
            <h2 class="h6 fw-semibold mb-2">Need help?</h2>
            <p class="small text-secondary mb-0">
              Questions about onboarding? Contact your Save Rack account manager or email
              <a href="mailto:billing@saverack.com" class="auth-vuexy-link">billing@saverack.com</a>.
            </p>
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
  </div>
</template>

<style scoped>
.portal-onboard-task {
  cursor: pointer;
  transition: box-shadow 0.15s ease;
}

.portal-onboard-task:hover {
  box-shadow: 0 0.25rem 0.75rem rgba(15, 23, 42, 0.08);
}

.portal-onboard-task__icon {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.5rem;
  background: rgba(var(--bs-primary-rgb), 0.12);
  position: relative;
}

.portal-onboard-task__icon--account::after,
.portal-onboard-task__icon--billing::after {
  content: "";
  position: absolute;
  inset: 0.55rem;
  background: var(--bs-primary);
  mask-size: contain;
  mask-repeat: no-repeat;
  mask-position: center;
}

.portal-onboard-task__icon--account::after {
  mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='black' d='M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z'/%3E%3C/svg%3E");
}

.portal-onboard-task__icon--billing::after {
  mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='black' d='M20 4H4c-1.11 0-2 .89-2 2v12c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4V6h16v12z'/%3E%3C/svg%3E");
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
</style>
