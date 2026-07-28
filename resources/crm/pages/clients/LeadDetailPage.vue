<script setup>
import { computed, inject, onMounted, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import LeadFeesPanel from "../../components/leads/LeadFeesPanel.vue";
import LeadStatusUpdateModal from "../../components/leads/LeadStatusUpdateModal.vue";
import {
  LEAD_FOLLOW_UP_DAY_OPTIONS,
  LEAD_STATUSES,
  formatFollowUpDays,
  leadStatusLabel,
} from "../../constants/leads.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatDateTimeUs, formatDateUs } from "../../utils/formatUserDates.js";

const props = defineProps({
  id: { type: [String, Number], required: true },
});

const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const route = useRoute();
const router = useRouter();

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canUpdate = computed(() => userHasPerm("leads.update"));

const TAB_FEES = "fees";
const TAB_HISTORY = "history";

const leadTabList = [
  { id: TAB_FEES, label: "Fees" },
  { id: TAB_HISTORY, label: "History" },
];

function leadTabIconPath(tabId) {
  if (tabId === TAB_FEES) {
    return "M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z";
  }
  if (tabId === TAB_HISTORY) {
    return "M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z";
  }
  return "M4.5 6.75h15M4.5 12h15m-15 5.25h15";
}

function statusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "open") return "bg-success-subtle text-success";
  if (s === "contacted") return "bg-primary-subtle text-primary";
  if (s === "interested") return "bg-warning-subtle text-warning-emphasis";
  if (s === "future_opportunity") return "bg-info-subtle text-info";
  if (s === "follow_up") return "bg-danger-subtle text-danger";
  if (s === "non_responsive" || s === "not_interested") {
    return "bg-secondary-subtle text-secondary";
  }
  if (s === "not_qualified") return "bg-body-secondary text-body-secondary";
  return "bg-body-secondary text-body-secondary";
}

const loading = ref(true);
const lead = ref(null);
const historyItems = ref([]);
const historyLoading = ref(false);
const followUpSaving = ref(false);

const statusModalOpen = ref(false);
const statusModalStatus = ref("open");
const statusModalFollowUpDays = ref(1);
const statusBusy = ref(false);

const statuses = ref([...LEAD_STATUSES]);
const followUpDayOptions = ref([...LEAD_FOLLOW_UP_DAY_OPTIONS]);

const activeTab = computed(() => {
  const t = String(route.query.tab || TAB_FEES).toLowerCase();
  return t === TAB_HISTORY ? TAB_HISTORY : TAB_FEES;
});

setCrmPageMeta({
  title: "Save Rack | Lead",
  description: "Lead detail.",
});

function setTab(tab) {
  router.replace({
    name: "lead-detail",
    params: { id: props.id },
    query: { ...route.query, tab },
  });
}

function display(value) {
  const s = String(value ?? "").trim();
  return s !== "" ? s : "—";
}

function websiteHref(website) {
  const raw = String(website || "").trim();
  if (!raw) return "";
  if (/^https?:\/\//i.test(raw)) return raw;
  return `https://${raw}`;
}

function avatarClassForTimelineActor(label) {
  const s = String(label || "");
  let hash = 0;
  for (let i = 0; i < s.length; i += 1) {
    hash = (hash + s.charCodeAt(i) * (i + 1)) % 5;
  }
  const classes = [
    "bg-primary-subtle text-primary",
    "bg-success-subtle text-success",
    "bg-warning-subtle text-warning",
    "bg-info-subtle text-info",
    "bg-secondary-subtle text-secondary",
  ];
  return classes[hash] || classes[0];
}

function historyItemBody(row) {
  return row?.body || row?.line || "";
}

function timelineActorAvatarUrl(row) {
  return row?.actor_avatar_url || null;
}

async function loadLead() {
  loading.value = true;
  try {
    const { data } = await api.get(`/leads/${props.id}`);
    lead.value = data;
    setCrmPageMeta({
      title: `Save Rack | ${data.company_name || "Lead"}`,
      description: "Lead detail.",
    });
  } catch (e) {
    toast.errorFrom(e, "Could not load lead.");
    lead.value = null;
  } finally {
    loading.value = false;
  }
}

async function loadHistory() {
  historyLoading.value = true;
  try {
    const { data } = await api.get(`/leads/${props.id}/history`);
    historyItems.value = Array.isArray(data?.items) ? data.items : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load history.");
    historyItems.value = [];
  } finally {
    historyLoading.value = false;
  }
}

async function saveFollowUpDays(event) {
  if (!canUpdate.value || !lead.value?.id || followUpSaving.value) return;
  const days = Number(event?.target?.value ?? lead.value.follow_up_days);
  if (days === Number(lead.value.follow_up_days)) return;
  followUpSaving.value = true;
  try {
    const { data } = await api.patch(`/leads/${lead.value.id}`, {
      follow_up_days: days,
    });
    lead.value = data;
    toast.success("Follow up updated.");
    if (activeTab.value === TAB_HISTORY) {
      await loadHistory();
    }
  } catch (e) {
    toast.errorFrom(e, "Could not update follow up.");
  } finally {
    followUpSaving.value = false;
  }
}

function openStatusModal() {
  if (!canUpdate.value || !lead.value) return;
  statusModalStatus.value = lead.value.status || "open";
  statusModalFollowUpDays.value = Number(lead.value.follow_up_days || 1);
  statusModalOpen.value = true;
}

async function saveStatusFromModal() {
  if (!lead.value?.id || statusBusy.value) return;
  statusBusy.value = true;
  try {
    const { data } = await api.patch(`/leads/${lead.value.id}`, {
      status: statusModalStatus.value,
      follow_up_days: Number(statusModalFollowUpDays.value),
    });
    lead.value = data;
    statusModalOpen.value = false;
    toast.success("Lead updated.");
    if (activeTab.value === TAB_HISTORY) {
      await loadHistory();
    }
  } catch (e) {
    toast.errorFrom(e, "Could not update lead.");
  } finally {
    statusBusy.value = false;
  }
}

function onFeesUpdated(payload) {
  if (payload && typeof payload === "object") {
    lead.value = payload;
  }
}

watch(
  () => props.id,
  async () => {
    await loadLead();
    if (activeTab.value === TAB_HISTORY) {
      await loadHistory();
    } else {
      historyItems.value = [];
    }
  },
);

watch(activeTab, async (tab) => {
  if (tab === TAB_HISTORY && !historyItems.value.length) {
    await loadHistory();
  }
});

onMounted(async () => {
  try {
    const { data } = await api.get("/leads/meta");
    if (Array.isArray(data?.statuses) && data.statuses.length) {
      statuses.value = data.statuses;
    }
    if (Array.isArray(data?.follow_up_day_options) && data.follow_up_day_options.length) {
      followUpDayOptions.value = data.follow_up_day_options;
    }
  } catch {
    /* use defaults */
  }
  await loadLead();
  if (activeTab.value === TAB_HISTORY) {
    await loadHistory();
  }
});
</script>

<template>
  <div class="staff-page">
    <LeadStatusUpdateModal
      v-model:open="statusModalOpen"
      v-model:status="statusModalStatus"
      v-model:follow-up-days="statusModalFollowUpDays"
      :statuses="statuses"
      :follow-up-day-options="followUpDayOptions"
      :busy="statusBusy"
      @save="saveStatusFromModal"
    />

    <div class="mb-3">
      <RouterLink to="/admin/clients/leads" class="small text-decoration-none">
        ← Back to Leads
      </RouterLink>
    </div>

    <div v-if="loading" class="text-secondary py-5 text-center">
      <CrmLoadingSpinner class="me-2" />
      Loading…
    </div>

    <template v-else-if="lead">
      <div class="account-detail-header d-flex flex-row align-items-center gap-3 mb-3">
        <div class="d-flex flex-wrap align-items-center gap-2 min-w-0 flex-shrink-0">
          <h1 class="staff-user-view__title account-detail-header__title mb-0 text-break">
            {{ lead.company_name }}
          </h1>
          <button
            v-if="canUpdate"
            type="button"
            class="staff-status-badge"
            :class="statusBadgeClass(lead.status)"
            title="Change lead status"
            @click="openStatusModal"
          >
            {{ leadStatusLabel(lead.status) }}
          </button>
          <span
            v-else
            class="staff-status-badge"
            :class="statusBadgeClass(lead.status)"
          >
            {{ leadStatusLabel(lead.status) }}
          </span>
        </div>
        <div
          class="staff-detail-tab-bar-wrap staff-detail-tab-bar-wrap--nav ms-lg-auto flex-grow-1"
          role="presentation"
        >
          <div class="staff-detail-tab-bar staff-detail-tab-bar--nav" role="tablist">
            <button
              v-for="t in leadTabList"
              :key="t.id"
              type="button"
              class="staff-detail-tab-btn"
              :class="{ 'staff-detail-tab-btn--active': activeTab === t.id }"
              role="tab"
              :aria-selected="activeTab === t.id"
              :title="t.label"
              @click="setTab(t.id)"
            >
              <svg
                class="staff-detail-tab-btn__icon"
                width="18"
                height="18"
                fill="none"
                stroke="currentColor"
                stroke-width="1.75"
                viewBox="0 0 24 24"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  :d="leadTabIconPath(t.id)"
                />
              </svg>
              <span class="staff-detail-tab-btn__label">{{ t.label }}</span>
            </button>
          </div>
        </div>
      </div>

      <p class="text-secondary small mb-3">
        <a v-if="lead.email" :href="`mailto:${lead.email}`" class="text-decoration-none">
          {{ lead.email }}
        </a>
        <span v-else>—</span>
      </p>

      <div class="row g-4">
        <div class="col-lg-7">
          <div class="staff-surface p-3 p-md-4 mb-4">
            <dl class="row mb-0 gy-3 small">
              <div class="col-sm-6">
                <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                  Created Date
                </dt>
                <dd class="mb-0 fw-semibold">{{ formatDateUs(lead.created_at) }}</dd>
              </div>
              <div class="col-sm-6">
                <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                  Follow Up
                </dt>
                <dd class="mb-0">
                  <select
                    v-if="canUpdate"
                    class="form-select form-select-sm"
                    style="max-width: 10rem"
                    :value="lead.follow_up_days"
                    :disabled="followUpSaving"
                    aria-label="Follow Up Days"
                    @change="saveFollowUpDays"
                  >
                    <option
                      v-for="days in followUpDayOptions"
                      :key="days"
                      :value="days"
                    >
                      {{ formatFollowUpDays(days) }}
                    </option>
                  </select>
                  <span v-else class="fw-semibold">{{ formatFollowUpDays(lead.follow_up_days) }}</span>
                </dd>
              </div>
              <div class="col-sm-6">
                <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                  Website
                </dt>
                <dd class="mb-0 fw-semibold text-break">
                  <a
                    v-if="lead.website"
                    :href="websiteHref(lead.website)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-decoration-none"
                  >
                    {{ lead.website }}
                  </a>
                  <span v-else>{{ display(lead.website) }}</span>
                </dd>
              </div>
              <div class="col-sm-6">
                <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                  Name
                </dt>
                <dd class="mb-0 fw-semibold">{{ display(lead.name) }}</dd>
              </div>
              <div v-if="lead.comment" class="col-12">
                <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                  Comment
                </dt>
                <dd class="mb-0" style="white-space: pre-wrap">{{ lead.comment }}</dd>
              </div>
            </dl>
          </div>

          <div class="staff-surface p-3 p-md-4">
            <template v-if="activeTab === TAB_FEES">
              <h2 class="h6 fw-semibold mb-3">Fees</h2>
              <LeadFeesPanel
                :lead-id="lead.id"
                :lead="lead"
                @fees-updated="onFeesUpdated"
              />
            </template>
            <template v-else>
              <h2 class="h6 fw-semibold mb-3">History</h2>
              <div v-if="historyLoading" class="text-secondary py-3">
                <CrmLoadingSpinner small class="me-1" />
                Loading…
              </div>
              <div
                v-else-if="historyItems.length"
                class="staff-user-timeline staff-user-timeline--flat"
              >
                <div
                  v-for="row in historyItems"
                  :key="row.id"
                  class="staff-user-timeline__item"
                >
                  <img
                    v-if="timelineActorAvatarUrl(row)"
                    :src="timelineActorAvatarUrl(row)"
                    alt=""
                    class="staff-user-timeline__avatar-img rounded-circle flex-shrink-0 object-fit-cover"
                    width="36"
                    height="36"
                  />
                  <span
                    v-else
                    class="staff-user-timeline__avatar-img rounded-circle flex-shrink-0 d-inline-flex align-items-center justify-content-center small fw-semibold"
                    style="width: 36px; height: 36px; font-size: 0.6875rem"
                    :class="avatarClassForTimelineActor(row.actor_name)"
                    aria-hidden="true"
                  >{{ row.actor_initials || "?" }}</span>
                  <div class="staff-user-timeline__content min-w-0 flex-grow-1">
                    <div class="staff-user-timeline__row">
                      <h3 class="staff-user-timeline__heading">
                        {{ row.actor_name || "System" }}
                      </h3>
                      <time class="staff-user-timeline__time" :datetime="row.created_at">{{
                        formatDateTimeUs(row.created_at)
                      }}</time>
                    </div>
                    <p class="staff-user-timeline__body">{{ historyItemBody(row) }}</p>
                  </div>
                </div>
              </div>
              <p v-else class="staff-user-timeline__empty mb-0">No activity logged yet.</p>
            </template>
          </div>
        </div>

        <div class="col-lg-5">
          <div class="staff-surface p-3 p-md-4 h-100">
            <h2 class="h6 fw-semibold mb-3">Email Templates</h2>
            <textarea
              class="form-control"
              rows="16"
              placeholder="Email templates coming soon…"
              disabled
              aria-label="Email Templates"
            />
          </div>
        </div>
      </div>
    </template>

    <div v-else class="alert alert-warning">Lead not found.</div>
  </div>
</template>
