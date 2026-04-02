<script setup>
import { ref, watch } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  roles: { type: Array, default: () => [] },
  selectedCount: { type: Number, default: 0 },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "apply"]);

const changeStatus = ref(false);
const status = ref("active");
const changeRoles = ref(false);
const roleIds = ref([]);

watch(
  () => props.open,
  (o) => {
    if (!o) return;
    changeStatus.value = false;
    changeRoles.value = false;
    status.value = "active";
    roleIds.value = [];
  },
);

function close() {
  emit("update:open", false);
}

function toggleRole(id) {
  const n = Number(id);
  const i = roleIds.value.indexOf(n);
  if (i === -1) {
    roleIds.value = [...roleIds.value, n];
  } else {
    roleIds.value = roleIds.value.filter((x) => x !== n);
  }
}

function onSubmit() {
  if (!changeStatus.value && !changeRoles.value) {
    return;
  }
  const payload = {};
  if (changeStatus.value) {
    payload.status = status.value;
  }
  if (changeRoles.value) {
    payload.role_ids = [...roleIds.value];
  }
  emit("apply", payload);
}

function onBackdrop() {
  if (!props.busy) close();
}
</script>

<template>
  <Teleport to="body">
    <Transition name="modal-backdrop">
      <div
        v-if="open"
        class="fixed inset-0 z-[240] flex items-center justify-center p-4 sm:p-6"
        aria-modal="true"
        role="dialog"
      >
        <div
          class="absolute inset-0 bg-gray-900/40 backdrop-blur-[2px] dark:bg-black/55"
          aria-hidden="true"
          @click="onBackdrop"
        />
        <Transition name="modal-panel" appear>
          <div
            class="relative z-10 flex max-h-[min(90dvh,640px)] w-full max-w-md flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900"
          >
            <header
              class="shrink-0 border-b border-gray-200 px-5 py-4 dark:border-gray-800"
            >
              <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                Bulk edit
              </h2>
              <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Update {{ selectedCount }} selected
                {{ selectedCount === 1 ? "person" : "people" }}
              </p>
            </header>
            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
              <div class="space-y-5">
                <label
                  class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700"
                >
                  <input
                    v-model="changeStatus"
                    type="checkbox"
                    class="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#38bdf8] focus:ring-[#38bdf8]"
                  />
                  <span class="min-w-0 flex-1">
                    <span
                      class="block text-sm font-medium text-gray-900 dark:text-white"
                      >Status</span
                    >
                    <select
                      v-model="status"
                      class="mt-2 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                      :disabled="!changeStatus"
                    >
                      <option value="pending">Pending</option>
                      <option value="active">Active</option>
                      <option value="inactive">Inactive</option>
                    </select>
                  </span>
                </label>
                <label
                  class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700"
                >
                  <input
                    v-model="changeRoles"
                    type="checkbox"
                    class="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#38bdf8] focus:ring-[#38bdf8]"
                  />
                  <span class="min-w-0 flex-1">
                    <span
                      class="block text-sm font-medium text-gray-900 dark:text-white"
                      >Roles</span
                    >
                    <span
                      class="mt-1 block text-xs text-gray-500 dark:text-gray-400"
                      >Replaces roles for all selected accounts.</span
                    >
                    <div class="mt-2 flex flex-wrap gap-2">
                      <label
                        v-for="r in roles"
                        :key="r.id"
                        class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-600"
                        :class="
                          changeRoles ? '' : 'pointer-events-none opacity-50'
                        "
                      >
                        <input
                          type="checkbox"
                          class="rounded border-gray-300 text-[#38bdf8] focus:ring-[#38bdf8]"
                          :checked="roleIds.includes(Number(r.id))"
                          :disabled="!changeRoles"
                          @change="toggleRole(r.id)"
                        />
                        <span>{{ r.label || r.name }}</span>
                      </label>
                    </div>
                  </span>
                </label>
              </div>
            </div>
            <footer
              class="flex shrink-0 flex-wrap gap-3 border-t border-gray-200 bg-gray-50/90 px-5 py-4 dark:border-gray-800 dark:bg-gray-900/90"
            >
              <button
                type="button"
                class="inline-flex min-h-[2.75rem] flex-1 items-center justify-center rounded-xl bg-[#38bdf8] px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-95 focus:outline-none focus:ring-2 focus:ring-[#38bdf8]/40 disabled:opacity-50 sm:flex-none"
                :disabled="busy || (!changeStatus && !changeRoles)"
                @click="onSubmit"
              >
                {{ busy ? "Applying…" : "Apply" }}
              </button>
              <button
                type="button"
                class="inline-flex min-h-[2.75rem] flex-1 items-center justify-center rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 sm:flex-none"
                :disabled="busy"
                @click="close"
              >
                Cancel
              </button>
            </footer>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-backdrop-enter-active,
.modal-backdrop-leave-active {
  transition: opacity 0.2s ease;
}
.modal-backdrop-enter-from,
.modal-backdrop-leave-to {
  opacity: 0;
}
.modal-panel-enter-active {
  transition:
    opacity 0.2s ease,
    transform 0.2s ease;
}
.modal-panel-leave-active {
  transition:
    opacity 0.15s ease,
    transform 0.15s ease;
}
.modal-panel-enter-from,
.modal-panel-leave-to {
  opacity: 0;
  transform: scale(0.97) translateY(0.5rem);
}
</style>
