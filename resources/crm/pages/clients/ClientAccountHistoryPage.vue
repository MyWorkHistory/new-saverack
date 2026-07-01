<script setup>
import { ref, watch } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { formatDateTimeUs } from "../../utils/formatUserDates";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";

const props = defineProps({
  id: { type: String, required: true },
});

const router = useRouter();

const loading = ref(true);
const errorMsg = ref("");
const subjectName = ref("");
const items = ref([]);

async function load() {
  loading.value = true;
  errorMsg.value = "";
  items.value = [];
  try {
    const [accountRes, histRes] = await Promise.all([
      api.get(`/client-accounts/${props.id}`),
      api.get(`/client-accounts/${props.id}/history`),
    ]);
    subjectName.value =
      accountRes.data?.company_name && typeof accountRes.data.company_name === "string"
        ? accountRes.data.company_name
        : "";
    const list = histRes.data?.items;
    items.value = Array.isArray(list) ? list : [];
  } catch (e) {
    const st = e.response?.status;
    if (st === 403) {
      errorMsg.value = "You don't have access to this history.";
    } else if (st === 404) {
      errorMsg.value = "Account not found.";
    } else {
      errorMsg.value = "Could not load history.";
    }
  } finally {
    loading.value = false;
  }
}

watch(
  () => subjectName.value,
  (name) => {
    if (name) {
      setCrmPageMeta({
        title: `Save Rack | History: ${name}`,
        description: `Activity history for ${name}.`,
      });
    }
  },
);

function avatarClass(seed) {
  const palettes = [
    "bg-info-subtle text-info-emphasis",
    "bg-primary-subtle text-primary-emphasis",
    "bg-warning-subtle text-warning-emphasis",
  ];
  let h = 0;
  const s = String(seed || "");
  for (let i = 0; i < s.length; i++) h = (h + s.charCodeAt(i)) % 997;
  return palettes[h % palettes.length];
}

function historyBody(row) {
  if (row?.body) return row.body;
  if (row?.line) return row.line;
  const changes = row?.changes;
  if (Array.isArray(changes) && changes.length) {
    const labels = changes.map((c) => c?.label || c?.field).filter(Boolean);
    if (labels.length) {
      return `Updated ${labels.join(", ")}`;
    }
  }
  return "";
}

load();
</script>

<template>
  <div class="staff-user-view staff-page--wide">
    <nav
      class="staff-user-view__breadcrumb d-flex flex-wrap align-items-center gap-1"
      aria-label="Breadcrumb"
    >
      <RouterLink to="/admin/home">Home</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <RouterLink to="/admin/clients/accounts">Accounts</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <RouterLink :to="`/admin/clients/accounts/${id}`">
        {{ subjectName || "Account" }}
      </RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <span class="text-body-secondary">History</span>
    </nav>

    <div class="staff-user-view__title-row d-flex flex-wrap align-items-center justify-content-between gap-2">
      <h1 class="staff-user-view__title">
        <template v-if="subjectName">History: {{ subjectName }}</template>
        <template v-else>History</template>
      </h1>
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading history…" />
    </div>

    <template v-else-if="errorMsg">
      <p class="text-danger small mb-2">{{ errorMsg }}</p>
      <RouterLink to="/admin/clients/accounts" class="small">Back to accounts</RouterLink>
    </template>

    <div v-else class="staff-surface overflow-hidden">
      <div class="border-bottom px-4 py-3 px-md-5">
        <h2 class="h6 fw-semibold mb-0">Account activity</h2>
        <p class="text-secondary small mb-0 mt-1">Changes to this client account</p>
      </div>

      <div v-if="items.length" class="staff-user-timeline staff-user-timeline--flat px-4 py-3 px-md-5">
        <div v-for="row in items" :key="row.id" class="staff-user-timeline__item">
          <img
            v-if="row.actor_avatar_url"
            :src="resolvePublicUrl(row.actor_avatar_url) || row.actor_avatar_url"
            alt=""
            class="staff-user-timeline__avatar-img rounded-circle flex-shrink-0 object-fit-cover"
            width="36"
            height="36"
          />
          <span
            v-else
            class="staff-user-timeline__avatar-img rounded-circle flex-shrink-0 d-inline-flex align-items-center justify-content-center small fw-semibold"
            style="width: 36px; height: 36px; font-size: 0.6875rem"
            :class="avatarClass(row.actor_name || row.actor_initials)"
            :title="row.actor_name || 'User'"
            aria-hidden="true"
          >{{ row.actor_initials || "?" }}</span>
          <div class="staff-user-timeline__content min-w-0 flex-grow-1">
            <div class="staff-user-timeline__row">
              <h3 class="staff-user-timeline__heading">{{ row.actor_name || "System" }}</h3>
              <time class="staff-user-timeline__time" :datetime="row.created_at">{{
                formatDateTimeUs(row.created_at)
              }}</time>
            </div>
            <p class="staff-user-timeline__body">{{ historyBody(row) }}</p>
          </div>
        </div>
      </div>
      <p v-else class="staff-user-timeline__empty px-4 py-5 text-center mb-0">
        No activity logged yet.
      </p>

      <div class="border-top px-4 py-3 px-md-5">
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm"
          @click="router.push(`/admin/clients/accounts/${id}`)"
        >
          Back to account
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.staff-user-timeline--flat::before {
  display: none;
}
.object-fit-cover {
  object-fit: cover;
}
</style>
