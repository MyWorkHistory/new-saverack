<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";

const props = defineProps({
  modelValue: { type: String, default: "" },
  /** @type {{ id: number, name: string, email?: string }[]} */
  options: { type: Array, default: () => [] },
  label: { type: String, default: "" },
  placeholder: { type: String, default: "Select…" },
  searchPlaceholder: { type: String, default: "Search…" },
  allowEmpty: { type: Boolean, default: true },
  emptyLabel: { type: String, default: "— None —" },
  disabled: { type: Boolean, default: false },
  buttonId: { type: String, default: "" },
  listboxId: { type: String, default: "" },
  /** For filters without a visible label (e.g. list toolbar). */
  ariaLabel: { type: String, default: "" },
  /** `staff`: match CRM staff/datatable filter controls (Bootstrap-ish). */
  appearance: {
    type: String,
    default: "default",
    validator: (v) => v === "default" || v === "staff",
  },
});

const emit = defineEmits(["update:modelValue"]);

const root = ref(null);
const open = ref(false);
const filter = ref("");

const selectedOption = computed(() => {
  const v = props.modelValue;
  if (v === "" || v === null || v === undefined) return null;
  const idNum = Number(v);
  return (
    props.options.find(
      (o) => String(o.id) === String(v) || o.id === idNum,
    ) ?? null
  );
});

const triggerSubtitle = computed(() => {
  const o = selectedOption.value;
  return o?.email ? String(o.email) : "";
});

const filteredOptions = computed(() => {
  const q = filter.value.trim().toLowerCase();
  const list = props.options;
  if (!q) return list;
  return list.filter((o) => {
    const name = String(o.name ?? "").toLowerCase();
    const email = String(o.email ?? "").toLowerCase();
    return name.includes(q) || email.includes(q);
  });
});

function toggle() {
  if (props.disabled) return;
  open.value = !open.value;
  if (open.value) filter.value = "";
}

function close() {
  open.value = false;
  filter.value = "";
}

function selectNone() {
  emit("update:modelValue", "");
  close();
}

function selectOption(opt) {
  emit("update:modelValue", String(opt.id));
  close();
}

function onDocClick(e) {
  if (!root.value?.contains(e.target)) {
    close();
  }
}

watch(
  () => props.modelValue,
  () => {
    if (!open.value) return;
  },
);

onMounted(() => document.addEventListener("click", onDocClick));
onUnmounted(() => document.removeEventListener("click", onDocClick));
</script>

<template>
  <div
    ref="root"
    class="relative w-full"
    :class="{ 'crm-searchable-select--staff': appearance === 'staff' }"
  >
    <label
      v-if="label"
      class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
      >{{ label }}</label
    >
    <button
      :id="buttonId || undefined"
      type="button"
      class="crm-searchable-select__trigger flex h-11 w-full min-w-0 items-center gap-2.5 rounded-xl border border-gray-200 bg-white px-3 text-left shadow-sm transition hover:border-gray-300 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#2563eb]/25 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-gray-600 dark:hover:bg-gray-800/80"
      :aria-expanded="open"
      aria-haspopup="listbox"
      :aria-label="ariaLabel || undefined"
      :disabled="disabled"
      @click.stop="toggle"
    >
      <span class="min-w-0 flex-1">
        <span
          class="block truncate text-sm font-semibold text-gray-900 dark:text-white"
        >
          {{
            selectedOption
              ? selectedOption.name
              : allowEmpty
                ? emptyLabel
                : placeholder
          }}
        </span>
        <span
          v-if="triggerSubtitle"
          class="block truncate text-xs text-gray-500 dark:text-gray-400"
        >
          {{ triggerSubtitle }}
        </span>
      </span>
      <svg
        class="h-4 w-4 shrink-0 text-gray-400 transition dark:text-gray-500"
        :class="{ 'rotate-180': open }"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        stroke-width="2"
        aria-hidden="true"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          d="M19 9l-7 7-7-7"
        />
      </svg>
    </button>

    <div
      v-if="open"
      :id="listboxId || undefined"
      class="crm-searchable-select__panel absolute left-0 right-0 z-[140] mt-2 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900"
      role="listbox"
    >
      <div class="border-b border-gray-100 p-2 dark:border-gray-800">
        <input
          v-model="filter"
          type="search"
          autocomplete="off"
          :placeholder="searchPlaceholder"
          class="h-10 w-full rounded-lg border border-gray-200 bg-gray-50 px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-[#2563eb] focus:outline-none focus:ring-2 focus:ring-[#2563eb]/20 dark:border-gray-600 dark:bg-gray-800/80 dark:text-white dark:placeholder:text-gray-500"
          @click.stop
        />
      </div>
      <ul
        class="max-h-56 overflow-y-auto py-1 bg-white dark:bg-gray-900"
        role="presentation"
      >
        <li v-if="allowEmpty">
          <button
            type="button"
            class="flex w-full flex-col items-start gap-0.5 px-3 py-2.5 text-left text-sm transition bg-white hover:bg-blue-50 dark:bg-gray-900 dark:hover:bg-white/5"
            :class="[
              modelValue === '' ? 'bg-blue-50 dark:bg-white/[0.06]' : '',
            ]"
            role="option"
            :aria-selected="modelValue === ''"
            @click.stop="selectNone"
          >
            <span class="font-medium text-gray-900 dark:text-white">{{
              emptyLabel
            }}</span>
          </button>
        </li>
        <li
          v-if="options.length && !filteredOptions.length"
        >
          <p
            class="px-3 py-4 text-center text-sm text-gray-500 dark:text-gray-400"
          >
            No matches.
          </p>
        </li>
        <li
          v-if="!options.length"
        >
          <p
            class="px-3 py-4 text-center text-sm text-gray-500 dark:text-gray-400"
          >
            No people in directory yet.
          </p>
        </li>
        <li v-for="opt in filteredOptions" :key="opt.id">
          <button
            type="button"
            class="flex w-full flex-col items-start gap-0.5 px-3 py-2.5 text-left text-sm transition bg-white hover:bg-blue-50 dark:bg-gray-900 dark:hover:bg-white/5"
            :class="[
              String(modelValue) === String(opt.id)
                ? 'bg-blue-50 dark:bg-white/[0.06]'
                : '',
            ]"
            role="option"
            :aria-selected="String(modelValue) === String(opt.id)"
            @click.stop="selectOption(opt)"
          >
            <span class="font-medium text-gray-900 dark:text-white">{{
              opt.name
            }}</span>
            <span
              v-if="opt.email"
              class="text-xs text-gray-500 dark:text-gray-400"
              >{{ opt.email }}</span
            >
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>
