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

/** Keys this page may assign; API user payloads also merge in role keys (e.g. dashboard) which the update endpoint rejects. */
const EDITABLE_PERMISSION_KEYS = new Set(MODULES.flatMap((m) => m.keys));

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

async function load() {
  loading.value = true;
  errorMsg.value = "";
  subject.value = null;
  try {
    const { data } = await api.get(`/users/${props.id}`);
    subject.value = data;
    const keys = Array.isArray(data.permission_keys) ? [...data.permission_keys] : [];
    draftKeys.value = keys.filter(
      (k) => typeof k === "string" && EDITABLE_PERMISSION_KEYS.has(k),
    );
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
  "h-4 w-4 shrink-0 cursor-pointer rounded border-gray-300 bg-white accent-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/30 focus:ring-offset-0 dark:border-gray-500 dark:bg-gray-900 disabled:cursor-not-allowed disabled:opacity-60";

async function save() {
  if (isAdminTarget.value || saving.value) return;
  saving.value = true;
  try {
    await api.put(`/users/${props.id}/permissions`, {
      permission_keys: draftKeys.value.filter((k) => EDITABLE_PERMISSION_KEYS.has(k)),
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

      <!-- TailAdmin pattern: wrapper card + inner table cards (see Staff list) -->
      <div
        class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900"
      >
        <div
          class="border-b border-gray-100 px-4 py-5 dark:border-gray-800 sm:px-6"
        >
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">
            Permissions
          </h2>
          <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
            Module Access For This User
          </p>
        </div>

        <div class="space-y-4 px-4 py-4 sm:px-6 sm:pb-6">
          <div
            v-for="mod in MODULES"
            :key="mod.key"
            class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]"
          >
            <div class="overflow-x-auto">
              <table
                class="w-full min-w-[60rem] border-collapse text-left text-sm sm:min-w-[68rem] lg:min-w-[72rem] xl:min-w-0 xl:table-fixed"
              >
                <colgroup>
                  <col class="xl:w-[30%]" />
                  <col class="xl:w-[17.5%]" />
                  <col class="xl:w-[17.5%]" />
                  <col class="xl:w-[17.5%]" />
                  <col class="xl:w-[17.5%]" />
                </colgroup>
                <tbody>
              <!-- Module header row = strip like table thead (gray) -->
              <tr
                class="border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/50"
              >
                <th
                  scope="row"
                  class="border border-gray-200 px-5 py-4 align-middle font-normal dark:border-gray-700 sm:px-6 sm:py-5"
                >
                  <div class="flex min-w-0 items-center gap-2.5">
                    <button
                      type="button"
                      class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700/80"
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
                    <span class="font-semibold text-gray-900 dark:text-white">
                      {{ mod.label }}
                    </span>
                  </div>
                </th>
                <td
                  v-for="(colKey, colIdx) in mod.keys"
                  :key="`h-${mod.key}-${colKey}`"
                  class="border border-gray-200 px-4 py-4 align-middle dark:border-gray-700 sm:px-5 sm:py-5"
                >
                  <div
                    class="grid grid-cols-[1.125rem_minmax(0,1fr)] items-center gap-x-3 gap-y-0"
                  >
                    <input
                      type="checkbox"
                      :class="checkboxClass"
                      :checked="hasKey(colKey)"
                      :disabled="isAdminTarget"
                      :aria-label="`${mod.label} ${ACTION_HEADERS[colIdx]}`"
                      @change="onColToggle(mod, colIdx, $event)"
                    />
                    <span
                      class="text-sm font-medium text-gray-800 dark:text-gray-200"
                    >
                      {{ ACTION_HEADERS[colIdx] }}
                    </span>
                  </div>
                </td>
              </tr>

              <!-- Sub-row = body row (white / transparent) -->
              <tr
                v-show="expanded[mod.key]"
                :id="`perm-panel-${mod.key}`"
                class="border-b border-gray-200 bg-white dark:border-gray-800 dark:bg-transparent"
              >
                <th
                  scope="row"
                  class="border border-gray-200 px-5 py-4 pl-12 align-middle text-left text-sm font-normal text-gray-700 dark:border-gray-700 dark:text-gray-300 sm:px-6 sm:pl-14 sm:py-5"
                >
                  {{ mod.rowLabel }}
                </th>
                <td
                  v-for="(colKey, colIdx) in mod.keys"
                  :key="`c-${mod.key}-${colKey}`"
                  class="border border-gray-200 px-4 py-4 align-middle dark:border-gray-700 sm:px-5 sm:py-5"
                >
                  <div
                    class="grid grid-cols-[1.125rem_minmax(0,1fr)] items-center gap-x-3"
                  >
                    <input
                      type="checkbox"
                      :class="checkboxClass"
                      :checked="hasKey(colKey)"
                      :disabled="isAdminTarget"
                      :aria-label="`${mod.rowLabel} ${ACTION_HEADERS[colIdx]}`"
                      @change="onCellToggle(colKey, $event)"
                    />
                    <span class="invisible select-none text-sm font-medium" aria-hidden="true">
                      {{ ACTION_HEADERS[colIdx] }}
                    </span>
                  </div>
                </td>
              </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div
          class="flex flex-wrap gap-3 border-t border-gray-100 px-4 py-4 dark:border-gray-800 sm:px-6 sm:py-5"
        >
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
            class="inline-flex h-10 items-center justify-center rounded-lg border border-gray-200 bg-white px-5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700/70"
            @click="router.push(`/staff/${id}`)"
          >
            Cancel
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
