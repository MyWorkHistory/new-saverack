<script setup>
import { computed, inject, onMounted, ref, watch } from "vue";
import { RouterLink } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmRichTextEditor from "../../components/common/CrmRichTextEditor.vue";
import { useToast } from "../../composables/useToast.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { allValidationMessages } from "../../utils/apiError.js";

const props = defineProps({
  id: { type: String, required: true },
});

const crmUser = inject("crmUser", ref(null));
const toast = useToast();

const loading = ref(true);
const saving = ref(false);
const editing = ref(false);
const companyName = ref("");
const body = ref("");
const draft = ref("");
const isOverride = ref(false);
const publicUrl = ref("");
const errorMsg = ref("");

const canUpdate = computed(() => {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes("clients.update");
});

const hasBody = computed(() => !!(body.value && body.value.replace(/<[^>]+>/g, "").trim()));

const accountDetailTo = computed(() => ({
  name: "client-account-detail",
  params: { id: props.id },
  query: { tab: "settings" },
}));

async function load() {
  loading.value = true;
  errorMsg.value = "";
  try {
    const [accountRes, termsRes] = await Promise.all([
      api.get(`/client-accounts/${props.id}`),
      api.get(`/client-accounts/${props.id}/terms-of-service`),
    ]);
    companyName.value =
      accountRes.data?.company_name && typeof accountRes.data.company_name === "string"
        ? accountRes.data.company_name
        : "";
    body.value = termsRes.data?.body || "";
    draft.value = body.value;
    isOverride.value = !!termsRes.data?.is_override;
    publicUrl.value = termsRes.data?.public_url || `/terms/accounts/${props.id}`;
  } catch (e) {
    const st = e.response?.status;
    if (st === 403) {
      errorMsg.value = "You don't have access to these terms.";
    } else if (st === 404) {
      errorMsg.value = "Account not found.";
    } else {
      errorMsg.value = "Could not load Terms and Conditions.";
    }
  } finally {
    loading.value = false;
  }
}

watch(
  () => companyName.value,
  (name) => {
    setCrmPageMeta({
      title: name ? `Save Rack | Terms: ${name}` : "Save Rack | Account Terms",
      description: name
        ? `Terms and Conditions for ${name}.`
        : "Account Terms and Conditions.",
    });
  },
  { immediate: true },
);

function startEdit() {
  draft.value = body.value;
  errorMsg.value = "";
  editing.value = true;
}

function cancelEdit() {
  draft.value = body.value;
  errorMsg.value = "";
  editing.value = false;
}

async function save() {
  if (!canUpdate.value) return;
  saving.value = true;
  errorMsg.value = "";
  try {
    const { data } = await api.put(`/client-accounts/${props.id}/terms-of-service`, {
      body: draft.value || "",
    });
    body.value = data?.body || "";
    draft.value = body.value;
    isOverride.value = !!data?.is_override;
    publicUrl.value = data?.public_url || publicUrl.value;
    editing.value = false;
    toast.success("Terms and Conditions saved for this account.");
  } catch (e) {
    errorMsg.value = allValidationMessages(e, "Could not save Terms and Conditions.");
    toast.errorFrom(e, "Could not save Terms and Conditions.");
  } finally {
    saving.value = false;
  }
}

function openPublic() {
  const url = publicUrl.value || `/terms/accounts/${props.id}`;
  window.open(url, "_blank", "noopener,noreferrer");
}

onMounted(load);
watch(
  () => props.id,
  () => load(),
);
</script>

<template>
  <div class="crm-terms-page">
    <nav
      class="staff-user-view__breadcrumb d-flex flex-wrap align-items-center gap-1 small mb-3"
      aria-label="Breadcrumb"
    >
      <RouterLink to="/admin/home" class="link-secondary text-decoration-none">Home</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <RouterLink
        to="/admin/clients/accounts"
        class="link-secondary text-decoration-none"
      >
        Accounts
      </RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <RouterLink :to="accountDetailTo" class="link-secondary text-decoration-none">
        {{ companyName || "Account" }}
      </RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <span class="text-body">Terms and Conditions</span>
    </nav>

    <div
      class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-3"
    >
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 mb-1 fw-semibold text-body">Terms and Conditions</h1>
        <p class="text-secondary small mb-0">
          <template v-if="isOverride">
            Custom terms for this account (overrides the Settings default).
          </template>
          <template v-else>
            Using the default from Settings. Saving here customizes only this account.
          </template>
        </p>
      </div>
      <div class="d-flex flex-wrap align-items-center gap-2 ms-md-auto flex-shrink-0">
        <button
          type="button"
          class="btn btn-outline-primary"
          :disabled="loading"
          @click="openPublic"
        >
          View Public Link
        </button>
        <button
          v-if="canUpdate && !editing"
          type="button"
          class="btn btn-primary staff-page-primary"
          :disabled="loading || !!errorMsg"
          @click="startEdit"
        >
          Edit
        </button>
      </div>
    </div>

    <div v-if="loading" class="staff-surface p-4 d-flex justify-content-center">
      <CrmLoadingSpinner message="Loading…" />
    </div>
    <div v-else-if="errorMsg && !editing" class="alert alert-danger" role="alert">
      {{ errorMsg }}
    </div>
    <div v-else class="staff-surface p-3 p-md-4 crm-terms-page__surface">
      <p v-if="errorMsg" class="small text-danger mb-3">{{ errorMsg }}</p>

      <template v-if="editing">
        <CrmRichTextEditor
          v-model="draft"
          :disabled="saving"
          aria-label="Terms and Conditions"
        />
        <div class="d-flex justify-content-end gap-2 mt-3">
          <button
            type="button"
            class="btn btn-outline-primary"
            :disabled="saving"
            @click="cancelEdit"
          >
            Cancel
          </button>
          <button
            type="button"
            class="btn btn-primary staff-page-primary"
            :disabled="saving"
            @click="save"
          >
            {{ saving ? "Saving…" : "Save" }}
          </button>
        </div>
      </template>

      <template v-else>
        <div v-if="hasBody" class="crm-terms-preview" v-html="body" />
        <p v-else class="text-secondary mb-0">No Terms and Conditions are available yet.</p>
      </template>
    </div>
  </div>
</template>

<style scoped>
.crm-terms-page__surface {
  display: flex;
  flex-direction: column;
  min-height: calc(100vh - 210px);
}
.crm-terms-preview {
  flex: 1 1 auto;
  min-height: calc(100vh - 260px);
  max-height: calc(100vh - 210px);
  overflow-y: auto;
  font-size: 0.925rem;
  line-height: 1.55;
}
.crm-terms-preview :deep(p) {
  margin: 0 0 0.65rem;
}
.crm-terms-preview :deep(ul),
.crm-terms-preview :deep(ol) {
  margin: 0 0 0.65rem;
  padding-left: 1.25rem;
}
.crm-terms-preview :deep(h2),
.crm-terms-preview :deep(h3),
.crm-terms-preview :deep(h4) {
  margin: 0.85rem 0 0.45rem;
  font-size: 1.05rem;
  line-height: 1.3;
}
.crm-terms-preview :deep(blockquote) {
  margin: 0 0 0.85rem;
  padding-left: 0.85rem;
  border-left: 3px solid rgba(47, 43, 61, 0.2);
  color: #555;
}
.crm-terms-preview :deep(a) {
  color: #2573ba;
}
</style>
