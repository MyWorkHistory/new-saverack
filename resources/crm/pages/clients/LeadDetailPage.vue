<script setup>
import { computed, inject, onMounted, reactive, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import AccountDetailSectionHead from "../../components/clients/AccountDetailSectionHead.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import EmailTemplatesGroupedList from "../../components/settings/EmailTemplatesGroupedList.vue";
import LeadFeesPanel from "../../components/leads/LeadFeesPanel.vue";
import LeadStatusUpdateModal from "../../components/leads/LeadStatusUpdateModal.vue";
import {
  LEAD_FOLLOW_UP_DAY_OPTIONS,
  LEAD_STATUSES,
  formatFollowUpDays,
  leadStatusLabel,
} from "../../constants/leads.js";
import { EMAIL_TEMPLATE_CATEGORIES } from "../../constants/emailTemplates.js";
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

const TAB_LEAD_INFO = "lead-info";
const TAB_FEES = "fees";
const TAB_HISTORY = "history";
const TAB_EMAIL_TEMPLATES = "email-templates";

const leadTabList = [
  { id: TAB_LEAD_INFO, label: "Lead Info" },
  { id: TAB_FEES, label: "Fees" },
  { id: TAB_HISTORY, label: "History" },
  { id: TAB_EMAIL_TEMPLATES, label: "Email Templates" },
];

function leadTabIconPath(tabId) {
  switch (tabId) {
    case TAB_LEAD_INFO:
      return "M3 7.5A2.25 2.25 0 015.25 5.25h13.5A2.25 2.25 0 0121 7.5v9A2.25 2.25 0 0118.75 18.75H5.25A2.25 2.25 0 013 16.5v-9zM8.25 9.75h7.5M8.25 12.75h4.5";
    case TAB_FEES:
      return "M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z";
    case TAB_HISTORY:
      return "M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z";
    case TAB_EMAIL_TEMPLATES:
      return "M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75";
    default:
      return "M4.5 6.75h15M4.5 12h15m-15 5.25h15";
  }
}

function tabFromRouteQuery(tab) {
  const t = String(tab || "").toLowerCase();
  if (t === TAB_FEES) return TAB_FEES;
  if (t === TAB_HISTORY) return TAB_HISTORY;
  if (t === TAB_EMAIL_TEMPLATES || t === "email") return TAB_EMAIL_TEMPLATES;
  if (t === TAB_LEAD_INFO || t === "overview" || t === "info") return TAB_LEAD_INFO;
  return TAB_LEAD_INFO;
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

function avatarClassForEmail(email) {
  const s = String(email || "");
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

function initials(name) {
  const parts = String(name || "")
    .trim()
    .split(/\s+/)
    .filter(Boolean);
  if (!parts.length) return "?";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[1][0]).toUpperCase();
}

const loading = ref(true);
const errorMsg = ref("");
const lead = ref(null);
const historyItems = ref([]);
const historyLoading = ref(false);
const followUpSaving = ref(false);

const emailTemplateGroups = ref([]);
const emailTemplatesLoading = ref(false);
const emailTemplatesLoaded = ref(false);
const emailTemplatesCollapsed = reactive({});
EMAIL_TEMPLATE_CATEGORIES.forEach((cat) => {
  emailTemplatesCollapsed[cat] = false;
});

const emailHighlightCategory = computed(() => {
  const status = String(lead.value?.status || "").toLowerCase();
  return EMAIL_TEMPLATE_CATEGORIES.includes(status) ? status : "";
});

const statusModalOpen = ref(false);
const statusModalStatus = ref("open");
const statusModalFollowUpDays = ref(1);
const statusBusy = ref(false);

const statuses = ref([...LEAD_STATUSES]);
const followUpDayOptions = ref([...LEAD_FOLLOW_UP_DAY_OPTIONS]);

const activeTab = ref(TAB_LEAD_INFO);

const timelinePreview = computed(() => historyItems.value.slice(0, 5));

setCrmPageMeta({
  title: "Save Rack | Lead",
  description: "Lead detail.",
});

function setActiveTab(tabId) {
  activeTab.value = tabId;
  const q = String(route.query.tab || "");
  if (q !== tabId) {
    router.replace({
      name: "lead-detail",
      params: { id: props.id },
      query: { ...route.query, tab: tabId },
    });
  }
}

function syncTabFromRoute() {
  const next = tabFromRouteQuery(route.query.tab);
  if (activeTab.value !== next) {
    activeTab.value = next;
  }
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
  return avatarClassForEmail(label);
}

function historyItemBody(row) {
  return row?.body || row?.line || "";
}

function timelineActorAvatarUrl(row) {
  return row?.actor_avatar_url || null;
}

async function loadLead() {
  loading.value = true;
  errorMsg.value = "";
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
    errorMsg.value = "Lead not found.";
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

function syncEmailTemplateCollapse(highlight) {
  const key = String(highlight || "").toLowerCase();
  EMAIL_TEMPLATE_CATEGORIES.forEach((cat) => {
    emailTemplatesCollapsed[cat] = key ? cat !== key : false;
  });
}

async function loadEmailTemplates(force = false) {
  if (emailTemplatesLoading.value) return;
  if (emailTemplatesLoaded.value && !force) return;
  emailTemplatesLoading.value = true;
  try {
    const { data } = await api.get("/settings/email-templates", {
      params: { grouped: 1 },
    });
    emailTemplateGroups.value = Array.isArray(data?.groups) ? data.groups : [];
    emailTemplatesLoaded.value = true;
    syncEmailTemplateCollapse(emailHighlightCategory.value);
  } catch (e) {
    toast.errorFrom(e, "Could not load email templates.");
    emailTemplateGroups.value = [];
  } finally {
    emailTemplatesLoading.value = false;
  }
}

function toggleEmailTemplateGroup(category) {
  emailTemplatesCollapsed[category] = !emailTemplatesCollapsed[category];
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
    await loadHistory();
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
    await loadHistory();
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
  () => route.query.tab,
  () => {
    syncTabFromRoute();
  },
);

watch(
  () => props.id,
  async () => {
    historyItems.value = [];
    emailTemplateGroups.value = [];
    emailTemplatesLoaded.value = false;
    syncTabFromRoute();
    await loadLead();
    await loadHistory();
    if (activeTab.value === TAB_EMAIL_TEMPLATES) {
      await loadEmailTemplates(true);
    }
  },
);

watch(emailHighlightCategory, (cat) => {
  if (emailTemplatesLoaded.value) {
    syncEmailTemplateCollapse(cat);
  }
});

watch(activeTab, async (tab) => {
  if (tab === TAB_HISTORY && !historyItems.value.length && !historyLoading.value) {
    await loadHistory();
  }
  if (tab === TAB_EMAIL_TEMPLATES) {
    await loadEmailTemplates();
  }
});

onMounted(async () => {
  syncTabFromRoute();
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
  await loadHistory();
  if (activeTab.value === TAB_EMAIL_TEMPLATES) {
    await loadEmailTemplates();
  }
});
</script>

<template>
  <div class="staff-user-view staff-page--wide account-detail-page lead-detail-page">
    <LeadStatusUpdateModal
      v-model:open="statusModalOpen"
      v-model:status="statusModalStatus"
      v-model:follow-up-days="statusModalFollowUpDays"
      :statuses="statuses"
      :follow-up-day-options="followUpDayOptions"
      :busy="statusBusy"
      @save="saveStatusFromModal"
    />

    <div class="account-detail-page-head">
      <nav
        class="staff-user-view__breadcrumb account-detail-page-head__breadcrumb d-flex flex-wrap align-items-center gap-1"
        aria-label="Breadcrumb"
      >
        <RouterLink to="/admin/home">Home</RouterLink>
        <span class="text-secondary" aria-hidden="true">/</span>
        <RouterLink to="/admin/clients/leads">Leads</RouterLink>
        <span class="text-secondary" aria-hidden="true">/</span>
        <span class="text-body-secondary">{{ lead?.company_name || "Lead" }}</span>
      </nav>

      <div v-if="loading" class="d-flex justify-content-center py-4">
        <CrmLoadingSpinner message="Loading lead…" />
      </div>

      <template v-else-if="errorMsg">
        <p class="text-danger small mb-2">{{ errorMsg }}</p>
        <RouterLink to="/admin/clients/leads" class="small">Back to Leads</RouterLink>
      </template>

      <div
        v-else-if="lead"
        class="account-detail-header d-flex flex-row align-items-center gap-3"
      >
        <h1 class="staff-user-view__title account-detail-header__title mb-0 flex-shrink-0">
          {{ lead.company_name }}
        </h1>
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
              @click="setActiveTab(t.id)"
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
    </div>

    <template v-if="!loading && !errorMsg && lead">
      <div class="row g-3">
        <div class="col-12 col-xl-4">
          <aside class="staff-user-profile">
            <div class="staff-user-profile__avatar-wrap">
              <span
                class="staff-user-profile__avatar staff-user-profile__avatar--initials"
                :class="avatarClassForEmail(lead.email || lead.company_name)"
              >
                {{ initials(lead.company_name) }}
              </span>
            </div>
            <h2 class="staff-user-profile__name">
              {{ lead.company_name }}
            </h2>
            <div class="text-center mb-3">
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

            <AccountDetailSectionHead
              title="Details"
              icon="details"
              icon-style="plain"
              title-class="staff-user-profile__details-title mb-0"
              head-class="mb-2"
            />

            <dl class="staff-user-profile__dl mb-4">
              <div>
                <dt class="staff-user-profile__dt">Created Date</dt>
                <dd class="staff-user-profile__dd text-end">
                  {{ formatDateUs(lead.created_at) }}
                </dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Follow Up</dt>
                <dd class="staff-user-profile__dd text-end">
                  <select
                    v-if="canUpdate"
                    class="form-select form-select-sm ms-auto"
                    style="max-width: 8.5rem"
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
                  <span v-else>{{ formatFollowUpDays(lead.follow_up_days) }}</span>
                </dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Email</dt>
                <dd class="staff-user-profile__dd text-end text-break">
                  <a
                    v-if="lead.email"
                    :href="`mailto:${lead.email}`"
                    class="link-primary text-decoration-none text-break"
                  >
                    {{ lead.email }}
                  </a>
                  <template v-else>{{ display(lead.email) }}</template>
                </dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Website</dt>
                <dd class="staff-user-profile__dd text-end text-break">
                  <a
                    v-if="lead.website"
                    :href="websiteHref(lead.website)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="link-primary text-decoration-none text-break"
                  >
                    {{ lead.website }}
                  </a>
                  <template v-else>{{ display(lead.website) }}</template>
                </dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Name</dt>
                <dd class="staff-user-profile__dd text-end">
                  {{ display(lead.name) }}
                </dd>
              </div>
            </dl>

            <section class="staff-user-profile__activity" aria-labelledby="lead-sidebar-activity-heading">
              <AccountDetailSectionHead
                title="Lead Activity"
                icon="activity"
                title-class="staff-user-profile__details-title mb-0"
                head-class="mb-2"
                heading-id="lead-sidebar-activity-heading"
              >
                <template #actions>
                  <button
                    type="button"
                    class="small link-primary text-decoration-none btn btn-link p-0"
                    @click="setActiveTab(TAB_HISTORY)"
                  >
                    View All
                  </button>
                </template>
              </AccountDetailSectionHead>
              <div v-if="historyLoading && !timelinePreview.length" class="small text-secondary">
                Loading…
              </div>
              <div
                v-else-if="timelinePreview.length"
                class="staff-user-timeline staff-user-timeline--sidebar"
              >
                <div
                  v-for="row in timelinePreview"
                  :key="row.id"
                  class="staff-user-timeline__item"
                >
                  <img
                    v-if="timelineActorAvatarUrl(row)"
                    :src="timelineActorAvatarUrl(row)"
                    alt=""
                    class="staff-user-timeline__avatar-img rounded-circle flex-shrink-0 object-fit-cover"
                    width="28"
                    height="28"
                  />
                  <span
                    v-else
                    class="staff-user-timeline__avatar-img rounded-circle flex-shrink-0 d-inline-flex align-items-center justify-content-center small fw-semibold"
                    style="width: 28px; height: 28px; font-size: 0.625rem"
                    :class="avatarClassForTimelineActor(row.actor_name)"
                    aria-hidden="true"
                  >{{ row.actor_initials || "?" }}</span>
                  <div class="staff-user-timeline__content min-w-0 flex-grow-1">
                    <div class="staff-user-timeline__row">
                      <h4 class="staff-user-timeline__heading small mb-0">
                        {{ row.actor_name || "System" }}
                      </h4>
                      <time class="staff-user-timeline__time" :datetime="row.created_at">{{
                        formatDateTimeUs(row.created_at)
                      }}</time>
                    </div>
                    <p class="staff-user-timeline__body small mb-0">
                      {{ historyItemBody(row) }}
                    </p>
                  </div>
                </div>
              </div>
              <p v-else class="staff-user-timeline__empty small mb-0">
                No activity logged yet.
              </p>
            </section>
          </aside>
        </div>

        <div class="col-12 col-xl-8">
          <div
            class="staff-user-tab-panel"
            role="tabpanel"
            :aria-label="leadTabList.find((x) => x.id === activeTab)?.label"
          >
            <template v-if="activeTab === TAB_LEAD_INFO">
              <div class="staff-surface p-3 p-md-4 mb-4">
                <AccountDetailSectionHead
                  title="Personal Information"
                  icon="personal"
                  head-class="mb-3"
                />
                <div class="row g-3">
                  <div class="col-md-6">
                    <dl class="mb-0 small">
                      <dt
                        class="text-secondary text-uppercase fw-semibold mb-1"
                        style="font-size: 0.65rem"
                      >
                        Company
                      </dt>
                      <dd class="mb-0 fw-semibold text-body">
                        {{ display(lead.company_name) }}
                      </dd>
                    </dl>
                  </div>
                  <div class="col-md-6">
                    <dl class="mb-0 small">
                      <dt
                        class="text-secondary text-uppercase fw-semibold mb-1"
                        style="font-size: 0.65rem"
                      >
                        Email
                      </dt>
                      <dd class="mb-0 fw-semibold text-body text-break">
                        {{ display(lead.email) }}
                      </dd>
                    </dl>
                  </div>
                  <div class="col-md-6">
                    <dl class="mb-0 small">
                      <dt
                        class="text-secondary text-uppercase fw-semibold mb-1"
                        style="font-size: 0.65rem"
                      >
                        Name
                      </dt>
                      <dd class="mb-0 fw-semibold text-body">
                        {{ display(lead.name) }}
                      </dd>
                    </dl>
                  </div>
                  <div class="col-md-6">
                    <dl class="mb-0 small">
                      <dt
                        class="text-secondary text-uppercase fw-semibold mb-1"
                        style="font-size: 0.65rem"
                      >
                        Website
                      </dt>
                      <dd class="mb-0 fw-semibold text-body text-break">
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
                    </dl>
                  </div>
                </div>
              </div>

              <div class="staff-surface p-3 p-md-4 mb-4">
                <AccountDetailSectionHead
                  title="Comment"
                  icon="notes"
                  head-class="mb-3"
                />
                <p
                  v-if="lead.comment"
                  class="mb-0 small text-body"
                  style="white-space: pre-wrap"
                >
                  {{ lead.comment }}
                </p>
                <p v-else class="mb-0 small text-secondary">No comment yet.</p>
              </div>
            </template>

            <template v-else-if="activeTab === TAB_FEES">
              <div class="staff-surface p-3 p-md-4">
                <LeadFeesPanel
                  :lead-id="lead.id"
                  :lead="lead"
                  @fees-updated="onFeesUpdated"
                />
              </div>
            </template>

            <template v-else-if="activeTab === TAB_HISTORY">
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

            <template v-else-if="activeTab === TAB_EMAIL_TEMPLATES">
              <div class="staff-surface p-3 p-md-4">
                <AccountDetailSectionHead
                  title="Email Templates"
                  icon="notes"
                  head-class="mb-3"
                />
                <p class="text-secondary small mb-3">
                  Templates from Settings, grouped by lead status. The group for this lead's
                  current status is expanded by default.
                </p>
                <div v-if="emailTemplatesLoading" class="d-flex justify-content-center py-5">
                  <CrmLoadingSpinner />
                </div>
                <EmailTemplatesGroupedList
                  v-else
                  :groups="emailTemplateGroups"
                  :collapsed="emailTemplatesCollapsed"
                  :highlight-category="emailHighlightCategory"
                  @toggle-group="toggleEmailTemplateGroup"
                />
              </div>
            </template>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
