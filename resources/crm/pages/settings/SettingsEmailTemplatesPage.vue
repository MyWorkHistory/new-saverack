<script setup>
import { computed, inject, onMounted, onUnmounted, reactive, ref } from "vue";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import EmailTemplateFormDrawer from "../../components/settings/EmailTemplateFormDrawer.vue";
import EmailTemplatesGroupedList from "../../components/settings/EmailTemplatesGroupedList.vue";
import { EMAIL_TEMPLATE_CATEGORIES } from "../../constants/emailTemplates.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin } from "../../utils/crmUser.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();

const canUpdate = computed(() => {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes("settings.update");
});

const loading = ref(true);
const groups = ref([]);
const collapsed = reactive({});
const drawerOpen = ref(false);
const drawerBusy = ref(false);
const editing = ref(null);
const deleteOpen = ref(false);
const deleteBusy = ref(false);
const deleteTarget = ref(null);
const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });

EMAIL_TEMPLATE_CATEGORIES.forEach((cat) => {
  collapsed[cat] = false;
});

setCrmPageMeta({
  title: "Save Rack | Email Templates",
  description: "Manage email templates by lead status category.",
});

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get("/settings/email-templates", {
      params: { grouped: 1 },
    });
    groups.value = Array.isArray(data?.groups) ? data.groups : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load email templates.");
    groups.value = [];
  } finally {
    loading.value = false;
  }
}

function toggleGroup(category) {
  collapsed[category] = !collapsed[category];
}

function openCreate() {
  editing.value = null;
  manageOpenId.value = null;
  drawerOpen.value = true;
}

function openManage(row, event) {
  const btn = event?.currentTarget;
  const rect = btn?.getBoundingClientRect?.();
  const MENU_W = 180;
  if (rect) {
    let left = Math.round(rect.right - MENU_W);
    left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
    manageMenuRect.value = {
      top: Math.round(rect.bottom + 4),
      left,
    };
  }
  manageOpenId.value = manageOpenId.value === row.id ? null : row.id;
}

function openEdit(row) {
  editing.value = row;
  manageOpenId.value = null;
  drawerOpen.value = true;
}

function requestDelete(row) {
  deleteTarget.value = row;
  manageOpenId.value = null;
  deleteOpen.value = true;
}

async function saveTemplate(payload) {
  drawerBusy.value = true;
  try {
    if (editing.value?.id) {
      await api.patch(`/settings/email-templates/${editing.value.id}`, payload);
      toast.success("Template updated.");
    } else {
      await api.post("/settings/email-templates", payload);
      toast.success("Template added.");
    }
    drawerOpen.value = false;
    editing.value = null;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not save template.");
  } finally {
    drawerBusy.value = false;
  }
}

async function confirmDelete() {
  if (!deleteTarget.value?.id) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/settings/email-templates/${deleteTarget.value.id}`);
    toast.success("Template deleted.");
    deleteOpen.value = false;
    deleteTarget.value = null;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not delete template.");
  } finally {
    deleteBusy.value = false;
  }
}

function onDocClick(event) {
  if (event.target.closest?.("[data-email-template-actions]")) return;
  manageOpenId.value = null;
}

onMounted(() => {
  document.addEventListener("click", onDocClick);
  load();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
      <div class="d-flex align-items-start gap-3 min-w-0">
        <span class="email-templates-page__hero-icon flex-shrink-0" aria-hidden="true">
          <svg
            width="22"
            height="22"
            fill="none"
            stroke="currentColor"
            stroke-width="1.75"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"
            />
          </svg>
        </span>
        <div class="min-w-0">
          <h1 class="h4 mb-1 fw-semibold text-body">Email Templates</h1>
        </div>
      </div>
      <button
        v-if="canUpdate"
        type="button"
        class="btn btn-primary staff-page-primary fw-semibold"
        @click="openCreate"
      >
        + New Template
      </button>
    </div>

    <div v-if="loading" class="py-5">
      <CrmLoadingSpinner message="Loading email templates…" :center="true" />
    </div>
    <EmailTemplatesGroupedList
      v-else
      :groups="groups"
      :collapsed="collapsed"
      :can-manage="canUpdate"
      :manage-open-id="manageOpenId"
      :manage-menu-rect="manageMenuRect"
      expandable
      @toggle-group="toggleGroup"
      @open-manage="openManage"
      @edit="openEdit"
      @delete="requestDelete"
    />

    <EmailTemplateFormDrawer
      v-model:open="drawerOpen"
      :busy="drawerBusy"
      :template="editing"
      @save="saveTemplate"
    />

    <ConfirmModal
      :open="deleteOpen"
      title="Delete Template"
      :message="
        deleteTarget
          ? `Delete template “${deleteTarget.name}”? This cannot be undone.`
          : ''
      "
      confirm-label="Delete"
      :busy="deleteBusy"
      danger
      @close="deleteOpen = false"
      @confirm="confirmDelete"
    />
  </div>
</template>

<style scoped>
.email-templates-page__hero-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 0.75rem;
  background: #7c3aed;
  color: #fff;
}
</style>
