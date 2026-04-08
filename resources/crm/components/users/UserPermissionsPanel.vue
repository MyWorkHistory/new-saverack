<script setup>
import { computed, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast";

const ACTION_HEADERS = ["View", "Create", "Edit", "Delete"];
const ACTION_SUFFIXES = ["view", "create", "update", "delete"];

const PAGE_META = {
  users: { moduleKey: "staff", moduleLabel: "Staff", rowLabel: "Staff", order: 10 },
  webmaster: {
    moduleKey: "webmaster",
    moduleLabel: "Webmaster",
    rowLabel: "Webmaster",
    order: 20,
  },
  clients: {
    moduleKey: "clients",
    moduleLabel: "Clients",
    rowLabel: "Accounts",
    order: 30,
  },
  client_users: {
    moduleKey: "clients",
    moduleLabel: "Clients",
    rowLabel: "Account users",
    order: 31,
  },
};

const props = defineProps({
  userId: { type: String, required: true },
  /** When true, Cancel resets draft; Save emits `saved` for parent refresh. */
  embedded: { type: Boolean, default: false },
});

const emit = defineEmits(["saved"]);

const router = useRouter();
const toast = useToast();

const loading = ref(true);
const saving = ref(false);
const errorMsg = ref("");
const subject = ref(null);
const draftKeys = ref([]);
const permissionDefs = ref([]);
const modules = computed(() => {
  const byModule = new Map();
  for (const def of permissionDefs.value) {
    const pageKey = def.page;
    const known = PAGE_META[pageKey];
    const moduleKey = known?.moduleKey ?? pageKey;
    const moduleLabel =
      known?.moduleLabel ?? def.moduleLabel ?? pageKey.replace(/_/g, " ");
    const rowLabel = known?.rowLabel ?? def.pageLabel ?? pageKey.replace(/_/g, " ");
    const order = known?.order ?? 9999;
    if (!byModule.has(moduleKey)) {
      byModule.set(moduleKey, {
        key: moduleKey,
        label: moduleLabel,
        order,
        rowsByPage: new Map(),
      });
    }
    const mod = byModule.get(moduleKey);
    if (!mod.rowsByPage.has(pageKey)) {
      mod.rowsByPage.set(pageKey, {
        key: pageKey,
        rowLabel,
        keys: [null, null, null, null],
      });
    }
    const row = mod.rowsByPage.get(pageKey);
    const actionIdx = ACTION_SUFFIXES.indexOf(def.action);
    if (actionIdx >= 0) {
      row.keys[actionIdx] = def.key;
    }
  }

  return [...byModule.values()]
    .sort((a, b) => a.order - b.order || a.label.localeCompare(b.label))
    .map((mod) => ({
      key: mod.key,
      label: mod.label,
      rows: [...mod.rowsByPage.values()].sort((a, b) =>
        a.rowLabel.localeCompare(b.rowLabel),
      ),
    }));
});
const editablePermissionKeys = computed(() =>
  new Set(
    modules.value.flatMap((m) => m.rows.flatMap((r) => r.keys.filter(Boolean))),
  ),
);
const expanded = ref({});

const isAdminTarget = computed(() => subject.value?.is_admin === true);

async function load() {
  if (!props.userId) return;
  loading.value = true;
  errorMsg.value = "";
  subject.value = null;
  try {
    const [userRes, metaRes] = await Promise.all([
      api.get(`/users/${props.userId}`),
      api.get("/users/permissions/meta"),
    ]);
    subject.value = userRes.data;
    permissionDefs.value = normalizePermissionDefs(metaRes.data?.items);
    const nextExpanded = {};
    for (const mod of modules.value) {
      nextExpanded[mod.key] = expanded.value[mod.key] ?? true;
    }
    expanded.value = nextExpanded;
    const merged = Array.isArray(userRes.data.permission_keys)
      ? [...userRes.data.permission_keys]
      : [];
    const direct = Array.isArray(userRes.data.direct_permission_keys)
      ? [...userRes.data.direct_permission_keys]
      : null;
    const source = direct !== null ? direct : merged;
    draftKeys.value = source.filter(
      (k) => typeof k === "string" && editablePermissionKeys.value.has(k),
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
  () => props.userId,
  () => {
    load();
  },
  { immediate: true },
);

function toggleExpanded(modKey) {
  expanded.value[modKey] = !expanded.value[modKey];
}

function hasKey(key) {
  if (isAdminTarget.value) return true;
  return draftKeys.value.includes(key);
}

function setKey(key, on) {
  if (!key) return;
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
  for (const row of module.rows) {
    setKey(row.keys[colIdx], on);
  }
}

function onCellToggle(key, ev) {
  if (!key) return;
  setKey(key, ev.target.checked);
}

const checkboxClass =
  "h-4 w-4 shrink-0 cursor-pointer rounded border-gray-300 bg-white accent-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/30 focus:ring-offset-0 dark:border-gray-500 dark:bg-gray-900 disabled:cursor-not-allowed disabled:opacity-60";

async function save() {
  if (isAdminTarget.value || saving.value) return;
  saving.value = true;
  try {
    await api.put(`/users/${props.userId}/permissions`, {
      permission_keys: draftKeys.value.filter((k) =>
        editablePermissionKeys.value.has(k),
      ),
    });
    toast.success("Permissions Saved.");
    await load();
    if (props.embedded) {
      emit("saved");
    }
  } catch (err) {
    toast.errorFrom(err, "Could Not Save Permissions.");
  } finally {
    saving.value = false;
  }
}

function cancel() {
  if (props.embedded) {
    load();
  } else {
    router.push(`/staff/${props.userId}`);
  }
}

function titleCase(input) {
  const s = String(input || "").replace(/[_\-]+/g, " ").trim();
  if (!s) return "Page";
  return s
    .split(/\s+/)
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(" ");
}

function normalizePermissionDefs(items) {
  if (!Array.isArray(items)) return [];
  const out = [];
  for (const row of items) {
    const key = String(row?.key || "").trim();
    const match = key.match(/^([a-z0-9_]+)\.(view|create|update|delete)$/i);
    if (!match) continue;
    const page = match[1].toLowerCase();
    const action = match[2].toLowerCase();
    out.push({
      key,
      page,
      action,
      pageLabel: titleCase(page),
      moduleLabel: titleCase(row?.module || ""),
    });
  }
  return out;
}
</script>

<template>
  <div class="w-full">
    <div v-if="loading" class="flex justify-center py-12">
      <CrmLoadingSpinner message="Loading…" />
    </div>

    <template v-else-if="errorMsg">
      <p class="text-sm text-red-600 dark:text-red-400">
        {{ errorMsg }}
      </p>
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
            v-for="mod in modules"
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
                      v-for="(col, colIdx) in ACTION_HEADERS"
                      :key="`h-${mod.key}-${col}`"
                      class="border border-gray-200 px-4 py-4 align-middle dark:border-gray-700 sm:px-5 sm:py-5"
                    >
                      <div
                        class="grid grid-cols-[1.125rem_minmax(0,1fr)] items-center gap-x-3 gap-y-0"
                      >
                        <input
                          type="checkbox"
                          :class="checkboxClass"
                          :checked="
                            mod.rows.length > 0 &&
                            mod.rows.every((r) => hasKey(r.keys[colIdx]))
                          "
                          :indeterminate.prop="
                            mod.rows.length > 0 &&
                            !mod.rows.every((r) => hasKey(r.keys[colIdx])) &&
                            mod.rows.some((r) => hasKey(r.keys[colIdx]))
                          "
                          :disabled="isAdminTarget"
                          :aria-label="`${mod.label} ${col}`"
                          @change="onColToggle(mod, colIdx, $event)"
                        />
                        <span
                          class="text-sm font-medium text-gray-800 dark:text-gray-200"
                        >
                          {{ col }}
                        </span>
                      </div>
                    </td>
                  </tr>

                  <tr
                    v-for="(row, rowIdx) in mod.rows"
                    v-show="expanded[mod.key]"
                    :id="rowIdx === 0 ? `perm-panel-${mod.key}` : undefined"
                    :key="`r-${mod.key}-${row.key}`"
                    class="border-b border-gray-200 bg-white dark:border-gray-800 dark:bg-transparent"
                  >
                    <th
                      scope="row"
                      class="border border-gray-200 px-5 py-4 pl-12 align-middle text-left text-sm font-normal text-gray-700 dark:border-gray-700 dark:text-gray-300 sm:px-6 sm:pl-14 sm:py-5"
                    >
                      {{ row.rowLabel }}
                    </th>
                    <td
                      v-for="(colKey, colIdx) in row.keys"
                      :key="`c-${mod.key}-${row.key}-${colIdx}`"
                      class="border border-gray-200 px-4 py-4 align-middle dark:border-gray-700 sm:px-5 sm:py-5"
                    >
                      <div
                        class="grid grid-cols-[1.125rem_minmax(0,1fr)] items-center gap-x-3"
                      >
                        <input
                          type="checkbox"
                          :class="checkboxClass"
                          :checked="hasKey(colKey)"
                          :disabled="isAdminTarget || !colKey"
                          :aria-label="`${row.rowLabel} ${ACTION_HEADERS[colIdx]}`"
                          @change="onCellToggle(colKey, $event)"
                        />
                        <span
                          class="invisible select-none text-sm font-medium"
                          aria-hidden="true"
                        >
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
            :disabled="saving"
            @click="cancel"
          >
            Cancel
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
