<script setup>
import { computed, ref, watch } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";

const MODULES = [
  {
    key: "users",
    label: "Staff",
    rowLabel: "Staff",
    keys: [
      "users.view",
      "users.create",
      "users.update",
      "users.delete",
    ],
  },
  {
    key: "webmaster",
    label: "Webmaster",
    rowLabel: "Webmaster",
    keys: [
      "webmaster.view",
      "webmaster.create",
      "webmaster.update",
      "webmaster.delete",
    ],
  },
];

const ACTION_HEADERS = ["View", "Create", "Edit", "Delete"];

const props = defineProps({
  id: { type: String, required: true },
});

const router = useRouter();
const toast = useToast();

const loading = ref(true);
const saving = ref(false);
const errorMsg = ref("");
const subject = ref(null);
const draftKeys = ref([]);
const expanded = ref({
  users: true,
  webmaster: true,
});

const isAdminTarget = computed(() => subject.value?.is_admin === true);

const gridTemplate = computed(
  () => "minmax(8rem,1fr) repeat(4, minmax(4.5rem, 1fr))",
);

async function load() {
  loading.value = true;
  errorMsg.value = "";
  subject.value = null;
  try {
    const { data } = await api.get(`/users/${props.id}`);
    subject.value = data;
    const keys = Array.isArray(data.permission_keys) ? [...data.permission_keys] : [];
    draftKeys.value = keys;
  } catch (e) {
    const st = e.response?.status;
    if (st === 403) {
      errorMsg.value = "You Don't Have Access To This User.";
    } else if (st === 404) {
      errorMsg.value = "User Not Found.";
    } else {
      errorMsg.value = "Could Not Load User.";
    }
  } finally {
    loading.value = false;
  }
}

watch(
  () => subject.value?.name,
  (name) => {
    if (name && typeof name === "string") {
      setCrmPageMeta({
        title: `Save Rack | User Permissions: ${name}`,
        description: `Permissions For ${name}.`,
      });
    }
  },
);

function toggleExpanded(modKey) {
  expanded.value[modKey] = !expanded.value[modKey];
}

function hasKey(key) {
  if (isAdminTarget.value) return true;
  return draftKeys.value.includes(key);
}

function setKey(key, on) {
  if (isAdminTarget.value) return;
  const next = new Set(draftKeys.value);
  if (on) {
    next.add(key);
  } else {
    next.delete(key);
  }
  draftKeys.value = [...next];
}

function onColToggle(module, colIdx, ev) {
  const on = ev.target.checked;
  const key = module.keys[colIdx];
  setKey(key, on);
}

function onCellToggle(key, ev) {
  setKey(key, ev.target.checked);
}

const checkboxClass =
  "h-4 w-4 shrink-0 rounded border-gray-300 text-[#2563eb] focus:ring-[#2563eb] disabled:cursor-not-allowed disabled:opacity-60";

async function save() {
  if (isAdminTarget.value || saving.value) return;
  saving.value = true;
  try {
    await api.put(`/users/${props.id}/permissions`, {
      permission_keys: draftKeys.value,
    });
    toast.success("Permissions Saved.");
    await load();
  } catch (err) {
    toast.errorFrom(err, "Could Not Save Permissions.");
  } finally {
    saving.value = false;
  }
}

load();
</script>

<template>
  <div class="w-full">
    <nav class="mb-4 flex flex-wrap items-center gap-1.5 text-sm">
      <RouterLink
        to="/dashboard"
        class="font-medium text-gray-500 transition hover:text-[#2563eb] dark:text-gray-400 dark:hover:text-blue-400"
      >
        Home
      </RouterLink>
      <span class="text-gray-400 dark:text-gray-600" aria-hidden="true">/</span>
      <RouterLink
        to="/staff"
        class="font-medium text-gray-500 transition hover:text-[#2563eb] dark:text-gray-400 dark:hover:text-blue-400"
      >
        Staff
      </RouterLink>
      <span class="text-gray-400 dark:text-gray-600" aria-hidden="true">/</span>
      <RouterLink
        :to="`/staff/${id}`"
        class="font-medium text-gray-500 transition hover:text-[#2563eb] dark:text-gray-400 dark:hover:text-blue-400"
      >
        Profile
      </RouterLink>
      <span class="text-gray-400 dark:text-gray-600" aria-hidden="true">/</span>
      <span class="font-medium text-gray-800 dark:text-gray-200">
        User Permissions
      </span>
    </nav>

    <div class="mb-6">
      <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
        <template v-if="subject?.name">
          User Permissions: {{ subject.name }}
        </template>
        <template v-else-if="!loading && !errorMsg">
          User Permissions
        </template>
        <template v-else>
          User Permissions
        </template>
      </h1>
    </div>

    <div v-if="loading" class="flex justify-center py-20">
      <CrmLoadingSpinner message="Loading…" />
    </div>

    <template v-else-if="errorMsg">
      <p class="text-sm text-red-600 dark:text-red-400">
        {{ errorMsg }}
      </p>
      <RouterLink
        to="/staff"
        class="mt-2 inline-block text-sm font-medium text-[#2563eb] hover:underline dark:text-blue-400"
      >
        Back To Directory
      </RouterLink>
    </template>

    <div v-else class="space-y-6">
      <p
        v-if="isAdminTarget"
        class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100"
      >
        This User Is An Administrator And Has Full Access. These Permissions
        Are Not Stored Per User.
      </p>

      <div
        class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900/40"
      >
        <div
          class="hidden border-b border-gray-100 bg-gray-50 px-4 py-3 dark:border-gray-800 dark:bg-gray-800/50 sm:grid sm:gap-3 sm:px-6"
          :style="{ gridTemplateColumns: gridTemplate }"
        >
          <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
            Module
          </div>
          <div
            v-for="h in ACTION_HEADERS"
            :key="h"
            class="text-center text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400"
          >
            {{ h }}
          </div>
        </div>

        <div
          v-for="mod in MODULES"
          :key="mod.key"
          class="border-b border-gray-100 last:border-b-0 dark:border-gray-800"
        >
          <!-- Accordion header -->
          <div
            class="flex flex-wrap items-center gap-2 px-4 py-3 sm:grid sm:gap-3 sm:px-6"
            :style="{ gridTemplateColumns: gridTemplate }"
          >
            <div class="flex min-w-0 flex-1 items-center gap-2 sm:col-span-1 sm:flex-initial">
              <button
                type="button"
                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-white/5"
                :aria-expanded="expanded[mod.key]"
                :aria-controls="`perm-panel-${mod.key}`"
                @click="toggleExpanded(mod.key)"
              >
                <svg
                  class="h-5 w-5 transition"
                  :class="expanded[mod.key] ? 'rotate-180' : ''"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M19 9l-7 7-7-7"
                  />
                </svg>
              </button>
              <span class="text-sm font-semibold text-gray-900 dark:text-white">
                {{ mod.label }}
              </span>
            </div>
            <div
              v-for="(colKey, colIdx) in mod.keys"
              :key="`h-${mod.key}-${colKey}`"
              class="flex flex-1 items-center justify-center sm:block"
            >
              <input
                type="checkbox"
                :class="checkboxClass"
                :checked="hasKey(colKey)"
                :disabled="isAdminTarget"
                :aria-label="`${mod.label} ${ACTION_HEADERS[colIdx]} (All In Section)`"
                @change="onColToggle(mod, colIdx, $event)"
              />
            </div>
          </div>

          <!-- Child row -->
          <div
            v-show="expanded[mod.key]"
            :id="`perm-panel-${mod.key}`"
            class="border-t border-gray-100 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-gray-800/20 sm:grid sm:gap-3 sm:px-6"
            :style="{ gridTemplateColumns: gridTemplate }"
          >
            <div
              class="pl-11 text-sm text-gray-700 dark:text-gray-300"
            >
              {{ mod.rowLabel }}
            </div>
            <div
              v-for="(colKey, colIdx) in mod.keys"
              :key="`c-${mod.key}-${colKey}`"
              class="flex flex-1 items-center justify-center sm:block sm:text-center"
            >
              <input
                type="checkbox"
                :class="checkboxClass"
                :checked="hasKey(colKey)"
                :disabled="isAdminTarget"
                :aria-label="`${mod.rowLabel} ${ACTION_HEADERS[colIdx]}`"
                @change="onCellToggle(colKey, $event)"
              />
            </div>
          </div>
        </div>
      </div>

      <div class="flex flex-wrap gap-3">
        <button
          type="button"
          class="inline-flex h-10 items-center justify-center rounded-lg bg-[#2563eb] px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#1d4ed8] disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="saving || isAdminTarget"
          @click="save"
        >
          {{ saving ? "Saving…" : "Save" }}
        </button>
        <button
          type="button"
          class="inline-flex h-10 items-center justify-center rounded-lg border border-gray-200 bg-white px-5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5"
          @click="router.push(`/staff/${id}`)"
        >
          Cancel
        </button>
      </div>
    </div>
  </div>
</template>
