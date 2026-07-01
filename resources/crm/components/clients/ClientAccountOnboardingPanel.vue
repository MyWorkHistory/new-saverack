<script setup>
import { computed, ref } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import PortalOnboardingAccountModal from "../user-portal/PortalOnboardingAccountModal.vue";
import PortalOnboardingBillingModal from "../user-portal/PortalOnboardingBillingModal.vue";
import PortalOnboardingSectionModal from "../user-portal/PortalOnboardingSectionModal.vue";
import { useToast } from "../../composables/useToast";
import { PORTAL_MATERIAL_ICON } from "../../constants/portalMaterialIcons.js";
import {
  PORTAL_ONBOARDING_SECTION_IDS,
  PORTAL_ONBOARDING_TASK_ICON_KEYS,
} from "../../constants/portalOnboardingSections.js";

const props = defineProps({
  clientAccountId: { type: [String, Number], required: true },
  canEdit: { type: Boolean, default: false },
});

const emit = defineEmits(["account-updated"]);

const toast = useToast();
const loading = ref(true);
const verifyingTaskId = ref("");
const onboarding = ref(null);

const accountModalOpen = ref(false);
const billingModalOpen = ref(false);
const sectionModalOpen = ref(false);
const activeTask = ref(null);

const profile = computed(() => onboarding.value?.profile || null);
const tasks = computed(() => onboarding.value?.tasks || []);
const preferences = computed(() => onboarding.value?.preferences || {});
const brandLogoUrl = computed(() => onboarding.value?.brand_logo_url || "");
const manualInstructions = computed(() => onboarding.value?.manual_payment_instructions || null);
const activeSectionId = computed(() => activeTask.value?.id || "");

const activeTaskVerified = computed(() => !!activeTask.value?.verified);

function adminOnboardingBase() {
  return `/client-accounts/${props.clientAccountId}/onboarding`;
}

function completionLabel(status) {
  if (status === "completed") return "Completed";
  if (status === "processing") return "Processing";
  return "Not Completed";
}

const BILLING_METHOD_LABELS = {
  credit_card: "Credit Card (3.5% Processing Fee)",
  ach: "ACH / Bank Transfer (No Fee)",
  manual: "Manual Payments",
};

function billingMethodSummary(task) {
  if (task?.id !== "billing_information") return "";
  const raw = String(profile.value?.onboarding_billing_method || "").trim();
  const label = BILLING_METHOD_LABELS[raw];
  return label ? `Selected: ${label}` : "";
}

function completionClass(status) {
  if (status === "completed") return "portal-onboard-status--completed";
  if (status === "processing") return "portal-onboard-status--processing";
  return "portal-onboard-status--pending";
}

function verificationLabel(task) {
  return task?.verified ? "Verified" : "Not Verified";
}

function verificationClass(task) {
  return task?.verified ? "portal-onboard-status--verified" : "portal-onboard-status--not-verified";
}

function taskIconPath(task) {
  const key = PORTAL_ONBOARDING_TASK_ICON_KEYS[task?.icon] || task?.icon || "account";
  return PORTAL_MATERIAL_ICON[key] || PORTAL_MATERIAL_ICON.account;
}

function applyOnboardingPayload(data) {
  if (data && typeof data === "object") {
    onboarding.value = data;
    syncActiveTaskFromPayload(data);
  }
}

function syncActiveTaskFromPayload(data) {
  const id = activeTask.value?.id;
  if (!id || !Array.isArray(data?.tasks)) return;
  const fresh = data.tasks.find((t) => t?.id === id);
  if (fresh) {
    activeTask.value = fresh;
  }
}

async function loadOnboarding() {
  loading.value = true;
  try {
    const { data } = await api.get(adminOnboardingBase());
    applyOnboardingPayload(data);
  } catch (e) {
    toast.errorFrom(e, "Could not load onboarding.");
  } finally {
    loading.value = false;
  }
}

function openTask(task) {
  if (!props.canEdit || !task) return;
  activeTask.value = task;
  if (task.id === "account_information") {
    accountModalOpen.value = true;
    return;
  }
  if (task.id === "billing_information") {
    billingModalOpen.value = true;
    return;
  }
  if (PORTAL_ONBOARDING_SECTION_IDS.includes(task.id)) {
    sectionModalOpen.value = true;
  }
}

function onSaved(data) {
  const previousLogo = onboarding.value?.brand_logo_url || "";
  applyOnboardingPayload(data);
  const nextLogo = data?.brand_logo_url || "";
  if (nextLogo && nextLogo !== previousLogo) {
    emit("account-updated", { brand_logo_url: nextLogo });
  }
}

async function verifyActiveTask() {
  const task = activeTask.value;
  if (!task?.id || verifyingTaskId.value) return;
  verifyingTaskId.value = task.id;
  try {
    const { data } = await api.patch(
      `${adminOnboardingBase()}/tasks/${task.id}/verification`,
      { verified: true },
    );
    applyOnboardingPayload(data);
    toast.success("Marked as verified.");
  } catch (e) {
    toast.errorFrom(e, "Could not verify task.");
  } finally {
    verifyingTaskId.value = "";
  }
}

async function unverifyActiveTask() {
  const task = activeTask.value;
  if (!task?.id || verifyingTaskId.value) return;
  verifyingTaskId.value = task.id;
  try {
    const { data } = await api.patch(
      `${adminOnboardingBase()}/tasks/${task.id}/verification`,
      { verified: false },
    );
    applyOnboardingPayload(data);
    toast.success("Verification removed.");
  } catch (e) {
    toast.errorFrom(e, "Could not update verification.");
  } finally {
    verifyingTaskId.value = "";
  }
}

loadOnboarding();
</script>

<template>
  <div class="client-account-onboarding">
    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner />
    </div>

    <template v-else>
      <p class="text-secondary small mb-3">
        Review and complete onboarding steps for this account. Changes are saved to the same data
        the client sees on their welcome checklist.
      </p>

      <div class="portal-onboard-tasks d-flex flex-column gap-3">
        <button
          v-for="task in tasks"
          :key="task.id"
          type="button"
          class="portal-onboard-task portal-onboard-task--bordered staff-table-card staff-datatable-card--white text-start w-100 p-3 p-md-4"
          :class="{ 'portal-onboard-task--readonly': !canEdit }"
          :disabled="!canEdit"
          @click="openTask(task)"
        >
          <div class="portal-onboard-task__grid portal-onboard-task__grid--admin">
            <div class="portal-onboard-task__lead d-flex align-items-start gap-3 min-w-0">
              <div class="portal-onboard-task__icon flex-shrink-0" aria-hidden="true">
                <svg class="portal-onboard-task__icon-svg" fill="currentColor" viewBox="0 0 24 24">
                  <path :d="taskIconPath(task)" />
                </svg>
              </div>
              <div class="min-w-0">
                <h2 class="h6 fw-semibold mb-1">{{ task.title }}</h2>
                <p class="small text-secondary mb-0">{{ task.description }}</p>
                <p v-if="billingMethodSummary(task)" class="small text-body mb-0 mt-1">
                  {{ billingMethodSummary(task) }}
                </p>
              </div>
            </div>
            <div class="portal-onboard-task__status-col">
              <span
                class="portal-onboard-status badge rounded-pill"
                :class="completionClass(task.status)"
              >
                {{ completionLabel(task.status) }}
              </span>
            </div>
            <div class="portal-onboard-task__status-col">
              <span
                class="portal-onboard-status badge rounded-pill"
                :class="verificationClass(task)"
              >
                {{ verificationLabel(task) }}
              </span>
            </div>
          </div>
        </button>
      </div>
    </template>

    <PortalOnboardingAccountModal
      v-model:open="accountModalOpen"
      :profile="profile"
      :client-account-id="clientAccountId"
      admin-mode
      :task-id="activeTask?.id || 'account_information'"
      :task-verified="activeTaskVerified"
      :verifying="verifyingTaskId === 'account_information'"
      @saved="onSaved"
      @verify="verifyActiveTask"
      @unverify="unverifyActiveTask"
    />
    <PortalOnboardingBillingModal
      v-model:open="billingModalOpen"
      :profile="profile"
      :manual-instructions="manualInstructions"
      :client-account-id="clientAccountId"
      admin-mode
      :task-id="activeTask?.id || 'billing_information'"
      :task-verified="activeTaskVerified"
      :verifying="verifyingTaskId === 'billing_information'"
      @saved="onSaved"
      @verify="verifyActiveTask"
      @unverify="unverifyActiveTask"
    />
    <PortalOnboardingSectionModal
      v-model:open="sectionModalOpen"
      :section-id="activeSectionId"
      :preferences="preferences"
      :brand-logo-url="brandLogoUrl"
      :profile="profile"
      :client-account-id="clientAccountId"
      admin-mode
      :task-id="activeSectionId"
      :task-verified="activeTaskVerified"
      :verifying="!!verifyingTaskId && verifyingTaskId === activeSectionId"
      @saved="onSaved"
      @verify="verifyActiveTask"
      @unverify="unverifyActiveTask"
    />
  </div>
</template>

<style scoped>
.portal-onboard-task--readonly {
  cursor: default;
  opacity: 0.92;
}

.portal-onboard-task:not(.portal-onboard-task--readonly) {
  cursor: pointer;
  transition: box-shadow 0.15s ease;
}

.portal-onboard-task:not(.portal-onboard-task--readonly):hover {
  box-shadow: 0 0.25rem 0.75rem rgba(15, 23, 42, 0.08);
}

.portal-onboard-task--bordered {
  border: 1px solid var(--bs-border-color) !important;
  border-radius: 0.5rem;
  background: var(--bs-body-bg, #fff);
}

.portal-onboard-task__grid--admin {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto;
  gap: 0.75rem 1.25rem;
  align-items: center;
}

@media (max-width: 767.98px) {
  .portal-onboard-task__grid--admin {
    grid-template-columns: 1fr;
  }
}

.portal-onboard-task__status-col {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: flex-end;
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

.portal-onboard-status--pending,
.portal-onboard-status--not-verified {
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

.portal-onboard-status--verified {
  background: rgba(40, 199, 111, 0.12);
  color: #28c76f;
}
</style>
