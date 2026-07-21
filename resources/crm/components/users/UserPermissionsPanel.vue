<script setup>
import { computed, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast";

const ACTION_HEADERS = ["View", "Create", "Edit", "Delete"];
const ACTION_SUFFIXES = ["view", "create", "update", "delete"];

const PAGE_META = {
  dashboard: {
    moduleKey: "dashboard",
    moduleLabel: "Dashboard",
    rowLabel: "Dashboard",
    order: 10,
    rowOrder: 1,
  },
  tickets: {
    moduleKey: "tickets",
    moduleLabel: "Tickets",
    rowLabel: "Tickets",
    order: 20,
    rowOrder: 1,
  },
  resources_tutorials: {
    moduleKey: "resources",
    moduleLabel: "Resources",
    rowLabel: "Tutorials",
    order: 25,
    rowOrder: 1,
  },
  resources_photos: {
    moduleKey: "resources",
    moduleLabel: "Resources",
    rowLabel: "Photos",
    order: 25,
    rowOrder: 2,
  },
  resources_calendar: {
    moduleKey: "resources",
    moduleLabel: "Resources",
    rowLabel: "Calendar",
    order: 25,
    rowOrder: 3,
  },
  resources_events: {
    moduleKey: "resources",
    moduleLabel: "Resources",
    rowLabel: "Events",
    order: 25,
    rowOrder: 4,
  },
  clients: {
    moduleKey: "clients",
    moduleLabel: "Clients",
    rowLabel: "Accounts",
    order: 30,
    rowOrder: 1,
  },
  client_users: {
    moduleKey: "clients",
    moduleLabel: "Clients",
    rowLabel: "Users",
    order: 30,
    rowOrder: 2,
  },
  stores: {
    moduleKey: "clients",
    moduleLabel: "Clients",
    rowLabel: "Stores",
    order: 30,
    rowOrder: 3,
  },
  projects: {
    moduleKey: "clients",
    moduleLabel: "Clients",
    rowLabel: "Projects",
    order: 30,
    rowOrder: 4,
  },
  billing_summary: {
    moduleKey: "billing",
    moduleLabel: "Billing",
    rowLabel: "Revenue",
    order: 40,
    rowOrder: 1,
  },
  billing_invoices: {
    moduleKey: "billing",
    moduleLabel: "Billing",
    rowLabel: "Invoices",
    order: 40,
    rowOrder: 2,
  },
  billing_custom_bills: {
    moduleKey: "billing",
    moduleLabel: "Billing",
    rowLabel: "Bills",
    order: 40,
    rowOrder: 3,
  },
  billing_asn_bills: {
    moduleKey: "billing",
    moduleLabel: "Billing",
    rowLabel: "ASN Bills",
    order: 40,
    rowOrder: 4,
  },
  billing_return_bills: {
    moduleKey: "billing",
    moduleLabel: "Billing",
    rowLabel: "Returns Bills",
    order: 40,
    rowOrder: 5,
  },
  orders_search: {
    moduleKey: "orders",
    moduleLabel: "Orders",
    rowLabel: "Search",
    order: 50,
    rowOrder: 1,
  },
  orders_fulfillment: {
    moduleKey: "orders",
    moduleLabel: "Orders",
    rowLabel: "Fulfillment",
    order: 50,
    rowOrder: 2,
  },
  orders_awaiting: {
    moduleKey: "orders",
    moduleLabel: "Orders",
    rowLabel: "Ready To Ship",
    order: 50,
    rowOrder: 3,
  },
  orders_on_hold: {
    moduleKey: "orders",
    moduleLabel: "Orders",
    rowLabel: "On-Hold",
    order: 50,
    rowOrder: 4,
  },
  orders_backorder: {
    moduleKey: "orders",
    moduleLabel: "Orders",
    rowLabel: "Backorder",
    order: 50,
    rowOrder: 5,
  },
  orders_shipped: {
    moduleKey: "orders",
    moduleLabel: "Orders",
    rowLabel: "Shipped",
    order: 50,
    rowOrder: 6,
  },
  orders_wholesale: {
    moduleKey: "orders",
    moduleLabel: "Orders",
    rowLabel: "Wholesale",
    order: 50,
    rowOrder: 7,
  },
  orders_create: {
    moduleKey: "orders",
    moduleLabel: "Orders",
    rowLabel: "Create Order",
    order: 50,
    rowOrder: 8,
  },
  inventory_products: {
    moduleKey: "inventory",
    moduleLabel: "Inventory",
    rowLabel: "Products",
    order: 60,
    rowOrder: 1,
  },
  inventory_out_of_stock: {
    moduleKey: "inventory",
    moduleLabel: "Inventory",
    rowLabel: "Out of Stock",
    order: 60,
    rowOrder: 2,
  },
  inventory_restock: {
    moduleKey: "inventory",
    moduleLabel: "Inventory",
    rowLabel: "Restock",
    order: 60,
    rowOrder: 3,
  },
  inventory_on_demand: {
    moduleKey: "inventory",
    moduleLabel: "Inventory",
    rowLabel: "On-Demand",
    order: 60,
    rowOrder: 4,
  },
  receiving_asn: {
    moduleKey: "receiving",
    moduleLabel: "Receiving",
    rowLabel: "ASN",
    order: 65,
    rowOrder: 1,
  },
  receiving_put_away: {
    moduleKey: "receiving",
    moduleLabel: "Receiving",
    rowLabel: "Put Away",
    order: 65,
    rowOrder: 2,
  },
  returns_process: {
    moduleKey: "returns",
    moduleLabel: "Returns",
    rowLabel: "Process Returns",
    order: 67,
    rowOrder: 1,
  },
  returns_orders: {
    moduleKey: "returns",
    moduleLabel: "Returns",
    rowLabel: "Returned Orders",
    order: 67,
    rowOrder: 2,
  },
  returns_items: {
    moduleKey: "returns",
    moduleLabel: "Returns",
    rowLabel: "Returned Items",
    order: 67,
    rowOrder: 3,
  },
  returns_bins: {
    moduleKey: "returns",
    moduleLabel: "Returns",
    rowLabel: "Return Bins",
    order: 67,
    rowOrder: 4,
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
/** Editable CRM matrix grants (saved as direct user permissions). */
const draftKeys = ref([]);
const permissionDefs = ref([]);
const draftKeySet = computed(() => new Set(draftKeys.value));

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
    const rowOrder = known?.rowOrder ?? 9999;
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
        rowOrder,
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
      rows: [...mod.rowsByPage.values()].sort(
        (a, b) =>
          (a.rowOrder ?? 9999) - (b.rowOrder ?? 9999) ||
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
    // Meta runs staff-role → direct migration; load user after so draft sees updated grants.
    const metaRes = await api.get("/users/permissions/meta");
    const userRes = await api.get(`/users/${props.userId}`);
    subject.value = userRes.data;
    permissionDefs.value = normalizePermissionDefs(metaRes.data?.items);
    const nextExpanded = {};
    for (const mod of modules.value) {
      nextExpanded[mod.key] = expanded.value[mod.key] ?? true;
    }
    expanded.value = nextExpanded;

    const editable = editablePermissionKeys.value;
    // Draft = direct grants only (what Save writes). Meta already migrated role → direct.
    const direct = Array.isArray(userRes.data.direct_permission_keys)
      ? userRes.data.direct_permission_keys
      : [];
    draftKeys.value = direct.filter(
      (k) => typeof k === "string" && editable.has(k),
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
  if (!key) return false;
  if (isAdminTarget.value) return true;
  return draftKeySet.value.has(key);
}

/** Batch update so column/module multi-select is one reactive write. */
function setKeys(keys, on) {
  if (isAdminTarget.value) return;
  const list = (keys || []).filter((k) => typeof k === "string" && k !== "");
  if (list.length === 0) return;
  const next = new Set(draftKeys.value);
  for (const key of list) {
    if (on) next.add(key);
    else next.delete(key);
  }
  // Always assign a new array so :checked bindings re-render.
  draftKeys.value = Array.from(next);
}

function keysForColumn(module, colIdx) {
  return (module?.rows || []).map((r) => r.keys[colIdx]).filter(Boolean);
}

function keysForModule(module) {
  return (module?.rows || []).flatMap((r) => (r.keys || []).filter(Boolean));
}

function keysForRow(row) {
  return (row?.keys || []).filter(Boolean);
}

function columnAllChecked(module, colIdx) {
  const keys = keysForColumn(module, colIdx);
  return keys.length > 0 && keys.every((k) => hasKey(k));
}

function columnSomeChecked(module, colIdx) {
  const keys = keysForColumn(module, colIdx);
  if (keys.length === 0) return false;
  const some = keys.some((k) => hasKey(k));
  return some && !columnAllChecked(module, colIdx);
}

function columnHasKeys(module, colIdx) {
  return keysForColumn(module, colIdx).length > 0;
}

function moduleAllChecked(module) {
  const keys = keysForModule(module);
  return keys.length > 0 && keys.every((k) => hasKey(k));
}

function moduleSomeChecked(module) {
  const keys = keysForModule(module);
  if (keys.length === 0) return false;
  const some = keys.some((k) => hasKey(k));
  return some && !moduleAllChecked(module);
}

function moduleHasKeys(module) {
  return keysForModule(module).length > 0;
}

function rowAllChecked(row) {
  const keys = keysForRow(row);
  return keys.length > 0 && keys.every((k) => hasKey(k));
}

/** Apply checkbox state from the native change event (avoids stuck :checked + prevent). */
function onModuleChange(module, ev) {
  if (isAdminTarget.value || !moduleHasKeys(module)) return;
  const on = Boolean(ev?.target?.checked);
  // Defer past browser click bookkeeping so :checked isn't wiped after prevent-less updates.
  setTimeout(() => setKeys(keysForModule(module), on), 0);
}

function onColumnChange(module, colIdx, ev) {
  if (isAdminTarget.value || !columnHasKeys(module, colIdx)) return;
  const on = Boolean(ev?.target?.checked);
  setTimeout(() => setKeys(keysForColumn(module, colIdx), on), 0);
}

function toggleModule(module) {
  if (isAdminTarget.value || !moduleHasKeys(module)) return;
  const on = !moduleAllChecked(module);
  setTimeout(() => setKeys(keysForModule(module), on), 0);
}

function toggleColumn(module, colIdx) {
  if (isAdminTarget.value || !columnHasKeys(module, colIdx)) return;
  const on = !columnAllChecked(module, colIdx);
  setTimeout(() => setKeys(keysForColumn(module, colIdx), on), 0);
}

function toggleRow(row) {
  if (isAdminTarget.value) return;
  const keys = keysForRow(row);
  if (keys.length === 0) return;
  const on = !rowAllChecked(row);
  setTimeout(() => setKeys(keys, on), 0);
}

const checkboxClass =
  "h-4 w-4 shrink-0 cursor-pointer rounded border-gray-300 bg-white accent-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/30 focus:ring-offset-0 dark:border-gray-500 dark:bg-gray-900 disabled:cursor-not-allowed disabled:opacity-60";

async function save() {
  if (isAdminTarget.value || saving.value) return;
  saving.value = true;
  try {
    const payloadKeys = draftKeys.value.filter((k) =>
      editablePermissionKeys.value.has(k),
    );
    const { data } = await api.put(`/users/${props.userId}/permissions`, {
      permission_keys: payloadKeys,
    });
    subject.value = data;
    const editable = editablePermissionKeys.value;
    const direct = Array.isArray(data?.direct_permission_keys)
      ? data.direct_permission_keys
      : payloadKeys;
    draftKeys.value = direct.filter(
      (k) => typeof k === "string" && editable.has(k),
    );
    toast.success("Permissions Saved.");
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
    router.push(`/admin/staff/${props.userId}`);
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
      <p
        v-else
        class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-200"
      >
        Check the module name to select all permissions in that section, use a
        column header to select one action across rows, or check boxes one by
        one. Then click Save. A dash means that action is not available for that
        row.
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
                        <template v-if="moduleHasKeys(mod) && !isAdminTarget">
                          <input
                            type="checkbox"
                            :class="checkboxClass"
                            :checked="moduleAllChecked(mod)"
                            :indeterminate="moduleSomeChecked(mod)"
                            :aria-label="`Select all ${mod.label} permissions`"
                            @change="onModuleChange(mod, $event)"
                          />
                          <button
                            type="button"
                            class="min-w-0 truncate text-left font-semibold text-gray-900 dark:text-white"
                            @click="toggleModule(mod)"
                          >
                            {{ mod.label }}
                          </button>
                        </template>
                        <template v-else-if="moduleHasKeys(mod)">
                          <input
                            type="checkbox"
                            :class="checkboxClass"
                            checked
                            disabled
                            :aria-label="`Select all ${mod.label} permissions`"
                          />
                          <span class="font-semibold text-gray-900 dark:text-white">
                            {{ mod.label }}
                          </span>
                        </template>
                        <span
                          v-else
                          class="font-semibold text-gray-900 dark:text-white"
                        >
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
                        <template v-if="columnHasKeys(mod, colIdx) && !isAdminTarget">
                          <input
                            type="checkbox"
                            :class="checkboxClass"
                            :checked="columnAllChecked(mod, colIdx)"
                            :indeterminate="columnSomeChecked(mod, colIdx)"
                            :aria-label="`${mod.label} ${col}`"
                            @change="onColumnChange(mod, colIdx, $event)"
                          />
                          <button
                            type="button"
                            class="text-left text-sm font-medium text-gray-800 dark:text-gray-200"
                            @click="toggleColumn(mod, colIdx)"
                          >
                            {{ col }}
                          </button>
                        </template>
                        <template v-else-if="columnHasKeys(mod, colIdx)">
                          <input
                            type="checkbox"
                            :class="checkboxClass"
                            checked
                            disabled
                            :aria-label="`${mod.label} ${col}`"
                          />
                          <span class="text-sm font-medium text-gray-400 dark:text-gray-500">
                            {{ col }}
                          </span>
                        </template>
                        <template v-else>
                          <span
                            class="inline-block h-4 w-4 text-center text-xs leading-4 text-gray-300 dark:text-gray-600"
                            aria-hidden="true"
                            >—</span
                          >
                          <span
                            class="text-sm font-medium text-gray-400 dark:text-gray-500"
                          >
                            {{ col }}
                          </span>
                        </template>
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
                      <button
                        type="button"
                        class="text-left font-normal text-gray-700 hover:text-gray-900 disabled:cursor-not-allowed disabled:opacity-60 dark:text-gray-300 dark:hover:text-white"
                        :disabled="isAdminTarget || keysForRow(row).length === 0"
                        @click="toggleRow(row)"
                      >
                        {{ row.rowLabel }}
                      </button>
                    </th>
                    <td
                      v-for="(colKey, colIdx) in row.keys"
                      :key="`c-${mod.key}-${row.key}-${colIdx}`"
                      class="border border-gray-200 px-4 py-4 align-middle dark:border-gray-700 sm:px-5 sm:py-5"
                    >
                      <div
                        class="grid grid-cols-[1.125rem_minmax(0,1fr)] items-center gap-x-3"
                      >
                        <template v-if="colKey && !isAdminTarget">
                          <input
                            type="checkbox"
                            :class="checkboxClass"
                            :value="colKey"
                            v-model="draftKeys"
                            :aria-label="`${row.rowLabel} ${ACTION_HEADERS[colIdx]}`"
                          />
                        </template>
                        <template v-else-if="colKey">
                          <input
                            type="checkbox"
                            :class="checkboxClass"
                            checked
                            disabled
                            :aria-label="`${row.rowLabel} ${ACTION_HEADERS[colIdx]}`"
                          />
                        </template>
                        <template v-else>
                          <span
                            class="inline-block h-4 w-4 text-center text-xs leading-4 text-gray-300 dark:text-gray-600"
                            aria-hidden="true"
                            >—</span
                          >
                        </template>
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
