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
      <div
        class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center gap-3 mb-3"
      >
        <div class="min-w-0 flex-grow-1">
          <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
            <h1 class="h4 mb-0 fw-semibold text-body text-break">{{ lead.company_name }}</h1>
            <button
              v-if="canUpdate"
              type="button"
              class="btn btn-sm btn-outline-secondary"
              @click="openStatusModal"
            >
              {{ leadStatusLabel(lead.status) }}
            </button>
            <span v-else class="badge text-bg-light border">{{ leadStatusLabel(lead.status) }}</span>
          </div>
          <p class="text-secondary small mb-0">
            <a v-if="lead.email" :href="`mailto:${lead.email}`" class="text-decoration-none">
              {{ lead.email }}
            </a>
            <span v-else>—</span>
          </p>
        </div>

        <div class="staff-detail-tab-bar-wrap staff-detail-tab-bar-wrap--nav ms-lg-auto flex-grow-1">
          <div class="staff-detail-tab-bar staff-detail-tab-bar--nav" role="tablist">
            <button
              type="button"
              class="staff-detail-tab"
              role="tab"
              :aria-selected="activeTab === TAB_FEES"
              :class="{ 'staff-detail-tab--active': activeTab === TAB_FEES }"
              @click="setTab(TAB_FEES)"
            >
              Fees
            </button>
            <button
              type="button"
              class="staff-detail-tab"
              role="tab"
              :aria-selected="activeTab === TAB_HISTORY"
              :class="{ 'staff-detail-tab--active': activeTab === TAB_HISTORY }"
              @click="setTab(TAB_HISTORY)"
            >
              History
            </button>
          </div>
        </div>
      </div>

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
