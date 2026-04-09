<script setup>
import { computed, onUnmounted, ref, watch } from "vue";
import {
  JOB_POSITION_OPTIONS,
  JOB_POSITION_VALUES,
} from "../../constants/jobPositions";
import { daysInMonth } from "../../utils/formatUserDates";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";

const BIRTHDAY_MONTHS = [
  { value: "1", label: "January" },
  { value: "2", label: "February" },
  { value: "3", label: "March" },
  { value: "4", label: "April" },
  { value: "5", label: "May" },
  { value: "6", label: "June" },
  { value: "7", label: "July" },
  { value: "8", label: "August" },
  { value: "9", label: "September" },
  { value: "10", label: "October" },
  { value: "11", label: "November" },
  { value: "12", label: "December" },
];

const BASE_EMPLOYMENT_OPTIONS = [
  { value: "", label: "Not Specified" },
  { value: "Full-time", label: "Full-time" },
  { value: "Part-time", label: "Part-time" },
  { value: "Contractor", label: "Contractor" },
  { value: "Temporary", label: "Temporary" },
  { value: "Intern", label: "Intern" },
];

/** When null or empty, all sections render (full edit). */
const SECTION_ORDER = [
  "avatar",
  "displayName",
  "identity",
  "access",
  "contact",
  "address",
  "employment",
  "bio",
];

const props = defineProps({
  form: { type: Object, required: true },
  roles: { type: Array, default: () => [] },
  isEdit: { type: Boolean, default: false },
  userId: { type: String, default: "" },
  avatarUrl: { type: String, default: "" },
  saving: { type: Boolean, default: false },
  firstError: { type: Function, required: true },
  clearFieldError: { type: Function, required: true },
  toggleRole: { type: Function, required: true },
  uploadAvatar: { type: Function, default: null },
  deleteAvatar: { type: Function, default: null },
  /** Keys: avatar, displayName, identity, access, contact, address, employment, bio */
  sections: { type: Array, default: null },
  showAvatar: { type: Boolean, default: true },
  /** When false, hide subsection headings (e.g. in UserEditSectionModal). */
  showSectionTitles: { type: Boolean, default: true },
});

function showSection(key) {
  if (props.sections == null || props.sections.length === 0) {
    return key !== "displayName";
  }
  return props.sections.includes(key);
}

const showAvatarBlock = computed(
  () => props.showAvatar && showSection("avatar"),
);

function sectionWrapperClass(key) {
  const visible = SECTION_ORDER.filter((k) => {
    if (k === "avatar") return showAvatarBlock.value;
    return showSection(k);
  });
  const idx = visible.indexOf(key);
  const border =
    idx > 0
      ? "border-t border-gray-100 pt-6 dark:border-gray-800 "
      : "";
  return `${border}space-y-5`;
}

const pendingAvatarFile = defineModel("pendingAvatarFile", {
  type: Object,
  default: null,
});

const avatarInputRef = ref(null);
const localPreviewObjectUrl = ref("");

function formInitials(name) {
  if (!name || typeof name !== "string") return "?";
  const parts = name.trim().split(/\s+/).slice(0, 2);
  return parts.map((p) => p[0]?.toUpperCase() ?? "").join("") || "?";
}

const avatarDisplayUrl = computed(() => {
  if (localPreviewObjectUrl.value) {
    return localPreviewObjectUrl.value;
  }
  return resolvePublicUrl(props.avatarUrl || "");
});

function openAvatarPicker() {
  avatarInputRef.value?.click();
}

async function onAvatarInputChange(e) {
  const input = e.target;
  const file = input.files?.[0];
  input.value = "";
  if (!file) return;
  if (props.isEdit && props.userId && props.uploadAvatar) {
    try {
      await props.uploadAvatar(file);
    } catch {
      /* errors surfaced by caller / API */
    }
    return;
  }
  if (localPreviewObjectUrl.value) {
    URL.revokeObjectURL(localPreviewObjectUrl.value);
  }
  localPreviewObjectUrl.value = URL.createObjectURL(file);
  pendingAvatarFile.value = file;
}

async function onRemoveAvatar() {
  if (localPreviewObjectUrl.value) {
    URL.revokeObjectURL(localPreviewObjectUrl.value);
    localPreviewObjectUrl.value = "";
  }
  if (props.isEdit && props.userId && props.deleteAvatar) {
    try {
      await props.deleteAvatar();
    } catch {
      /* errors surfaced by caller */
    }
    return;
  }
  pendingAvatarFile.value = null;
}

watch(
  () => pendingAvatarFile.value,
  (file) => {
    if (file) return;
    if (localPreviewObjectUrl.value) {
      URL.revokeObjectURL(localPreviewObjectUrl.value);
      localPreviewObjectUrl.value = "";
    }
  },
);

onUnmounted(() => {
  if (localPreviewObjectUrl.value) {
    URL.revokeObjectURL(localPreviewObjectUrl.value);
  }
});

const employmentOptions = computed(() => {
  const known = new Set(BASE_EMPLOYMENT_OPTIONS.map((o) => o.value));
  const v = props.form?.employee_type;
  if (v != null && v !== "" && !known.has(String(v))) {
    return [
      ...BASE_EMPLOYMENT_OPTIONS,
      { value: String(v), label: `${v} (current)` },
    ];
  }
  return BASE_EMPLOYMENT_OPTIONS;
});

const jobPositionOptions = computed(() => {
  const known = new Set(["", ...JOB_POSITION_VALUES]);
  const v = props.form?.job_position;
  if (v != null && v !== "" && !known.has(String(v))) {
    return [
      ...JOB_POSITION_OPTIONS,
      { value: String(v), label: `${v} (current)` },
    ];
  }
  return JOB_POSITION_OPTIONS;
});

const birthdayMaxDay = computed(() =>
  daysInMonth(Number(props.form?.birthday_month) || 1),
);

const birthdayDayChoices = computed(() => {
  const max = birthdayMaxDay.value;
  return Array.from({ length: max }, (_, i) => String(i + 1));
});

watch(
  () => props.form?.birthday_month,
  () => {
    const f = props.form;
    if (!f) return;
    const max = daysInMonth(Number(f.birthday_month) || 1);
    const d = Number(f.birthday_day);
    if (f.birthday_day && d > max) {
      f.birthday_day = String(max);
    }
  },
);
</script>

<template>
  <div class="space-y-8">
    <div v-if="showAvatarBlock" :class="sectionWrapperClass('avatar')">
      <h3
        v-if="showSectionTitles"
        class="text-sm font-semibold text-gray-900 dark:text-white"
      >
        Profile photo
      </h3>

      <div class="flex flex-wrap items-center gap-4">
        <button
          type="button"
          class="relative shrink-0 focus:outline-none focus:ring-2 focus:ring-brand-500/40 rounded-full"
          @click="openAvatarPicker"
        >
          <img
            v-if="avatarDisplayUrl"
            :src="avatarDisplayUrl"
            alt=""
            class="h-20 w-20 rounded-full object-cover ring-2 ring-gray-200 dark:ring-gray-700"
          />
          <span
            v-else
            class="flex h-20 w-20 items-center justify-center rounded-full bg-gray-100 text-lg font-semibold text-gray-600 ring-2 ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700"
          >
            {{ formInitials(form.name) }}
          </span>
        </button>
        <div class="flex flex-col gap-2">
          <input
            ref="avatarInputRef"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            class="hidden"
            @change="onAvatarInputChange"
          />
          <button
            type="button"
            class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
            @click="openAvatarPicker"
          >
            Choose Image
          </button>
          <button
            v-if="avatarDisplayUrl"
            type="button"
            class="rounded-lg px-3 py-2 text-xs font-medium text-gray-600 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400"
            @click="onRemoveAvatar"
          >
            Remove
          </button>
        </div>
      </div>
    </div>

    <div v-if="showSection('displayName')" :class="sectionWrapperClass('displayName')">
      <h3
        v-if="showSectionTitles"
        class="text-sm font-semibold text-gray-900 dark:text-white"
      >
        Name
      </h3>
      <div>
        <label
          class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
          >Full name</label
        >
        <input
          v-model="form.name"
          type="text"
          required
          autocomplete="name"
          class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
          @input="clearFieldError('name')"
        />
        <p v-if="firstError('name')" class="mt-1 text-xs text-red-600">
          {{ firstError("name") }}
        </p>
      </div>
    </div>

    <div v-if="showSection('identity')" :class="sectionWrapperClass('identity')">
      <h3
        v-if="showSectionTitles"
        class="text-sm font-semibold text-gray-900 dark:text-white"
      >
        Name &amp; login
      </h3>

      <div>
        <label
          class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
          >Name</label
        >
        <input
          v-model="form.name"
          type="text"
          required
          autocomplete="name"
          class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
          @input="clearFieldError('name')"
        />
        <p v-if="firstError('name')" class="mt-1 text-xs text-red-600">
          {{ firstError("name") }}
        </p>
      </div>

      <div>
        <label
          class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
          >Login Email</label
        >
        <input
          v-model="form.email"
          type="email"
          required
          autocomplete="email"
          class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
          @input="clearFieldError('email')"
        />
        <p v-if="firstError('email')" class="mt-1 text-xs text-red-600">
          {{ firstError("email") }}
        </p>
      </div>

      <div>
        <label
          class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
          >{{ isEdit ? "New Password (Optional)" : "Password" }}</label
        >
        <input
          v-model="form.password"
          type="password"
          :required="!isEdit"
          autocomplete="new-password"
          class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
          @input="clearFieldError('password')"
        />
        <p v-if="firstError('password')" class="mt-1 text-xs text-red-600">
          {{ firstError("password") }}
        </p>
      </div>
    </div>

    <div v-if="showSection('access')" :class="sectionWrapperClass('access')">
      <h3
        v-if="showSectionTitles"
        class="text-sm font-semibold text-gray-900 dark:text-white"
      >
        Status &amp; roles
      </h3>

      <div>
        <label
          class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
          >Status</label
        >
        <select
          v-model="form.status"
          class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
          @change="clearFieldError('status')"
        >
          <option value="pending">Pending</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
        <p v-if="firstError('status')" class="mt-1 text-xs text-red-600">
          {{ firstError("status") }}
        </p>
      </div>

      <div>
        <span
          class="mb-2 block text-xs font-medium text-gray-500 dark:text-gray-400"
          >Roles</span
        >
        <div class="flex flex-wrap gap-3">
          <label
            v-for="r in roles"
            :key="r.id"
            class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-600"
          >
            <input
              type="checkbox"
              :checked="form.role_ids.includes(Number(r.id))"
              class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"
              @change="toggleRole(r.id)"
            />
            <span>{{ r.label || r.name }}</span>
          </label>
        </div>
        <p v-if="firstError('role_ids')" class="mt-1 text-xs text-red-600">
          {{ firstError("role_ids") }}
        </p>
      </div>
    </div>

    <div v-if="showSection('contact')" :class="sectionWrapperClass('contact')">
      <h3
        v-if="showSectionTitles"
        class="mb-4 text-sm font-semibold text-gray-900 dark:text-white"
      >
        Contact
      </h3>
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
          <label
            class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
            >Phone</label
          >
          <input
            v-model="form.phone"
            type="text"
            autocomplete="tel"
            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
            @input="clearFieldError('phone')"
          />
          <p v-if="firstError('phone')" class="mt-1 text-xs text-red-600">
            {{ firstError("phone") }}
          </p>
        </div>
        <div class="sm:col-span-2">
          <label
            class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
            >Personal email</label
          >
          <input
            v-model="form.personal_email"
            type="email"
            autocomplete="off"
            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
            @input="clearFieldError('personal_email')"
          />
          <p
            v-if="firstError('personal_email')"
            class="mt-1 text-xs text-red-600"
          >
            {{ firstError("personal_email") }}
          </p>
        </div>
        <div>
          <span
            class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
            >Birthday</span
          >
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label
                class="mb-1 block text-xs text-gray-500 dark:text-gray-400"
                >Month</label
              >
              <select
                v-model="form.birthday_month"
                class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                @change="clearFieldError('birthday')"
              >
                <option value="">—</option>
                <option
                  v-for="mo in BIRTHDAY_MONTHS"
                  :key="mo.value"
                  :value="mo.value"
                >
                  {{ mo.label }}
                </option>
              </select>
            </div>
            <div>
              <label
                class="mb-1 block text-xs text-gray-500 dark:text-gray-400"
                >Day</label
              >
              <select
                v-model="form.birthday_day"
                class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                @change="clearFieldError('birthday')"
              >
                <option value="">—</option>
                <option v-for="d in birthdayDayChoices" :key="d" :value="d">
                  {{ d }}
                </option>
              </select>
            </div>
          </div>
          <p v-if="firstError('birthday')" class="mt-1 text-xs text-red-600">
            {{ firstError("birthday") }}
          </p>
        </div>
      </div>
    </div>

    <div v-if="showSection('address')" :class="sectionWrapperClass('address')">
      <h3
        v-if="showSectionTitles"
        class="mb-4 text-sm font-semibold text-gray-900 dark:text-white"
      >
        Address
      </h3>
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
          <label
            class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
            >Street Address</label
          >
          <input
            v-model="form.address"
            type="text"
            autocomplete="street-address"
            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
            @input="clearFieldError('address')"
          />
          <p v-if="firstError('address')" class="mt-1 text-xs text-red-600">
            {{ firstError("address") }}
          </p>
        </div>
        <div>
          <label
            class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
            >City</label
          >
          <input
            v-model="form.city"
            type="text"
            autocomplete="address-level2"
            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
            @input="clearFieldError('city')"
          />
          <p v-if="firstError('city')" class="mt-1 text-xs text-red-600">
            {{ firstError("city") }}
          </p>
        </div>
        <div>
          <label
            class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
            >State / province</label
          >
          <input
            v-model="form.state"
            type="text"
            autocomplete="address-level1"
            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
            @input="clearFieldError('state')"
          />
          <p v-if="firstError('state')" class="mt-1 text-xs text-red-600">
            {{ firstError("state") }}
          </p>
        </div>
        <div>
          <label
            class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
            >Postal Code</label
          >
          <input
            v-model="form.zip"
            type="text"
            autocomplete="postal-code"
            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
            @input="clearFieldError('zip')"
          />
          <p v-if="firstError('zip')" class="mt-1 text-xs text-red-600">
            {{ firstError("zip") }}
          </p>
        </div>
        <div>
          <label
            class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
            >Country</label
          >
          <input
            v-model="form.region"
            type="text"
            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
            @input="clearFieldError('region')"
          />
          <p v-if="firstError('region')" class="mt-1 text-xs text-red-600">
            {{ firstError("region") }}
          </p>
        </div>
      </div>
    </div>

    <div v-if="showSection('employment')" :class="sectionWrapperClass('employment')">
      <h3
        v-if="showSectionTitles"
        class="mb-4 text-sm font-semibold text-gray-900 dark:text-white"
      >
        Employment
      </h3>
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
          <label
            class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
            >Employment type</label
          >
          <select
            v-model="form.employee_type"
            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
            @change="clearFieldError('employee_type')"
          >
            <option
              v-for="opt in employmentOptions"
              :key="opt.value === '' ? '_empty' : opt.value"
              :value="opt.value"
            >
              {{ opt.label }}
            </option>
          </select>
          <p
            v-if="firstError('employee_type')"
            class="mt-1 text-xs text-red-600"
          >
            {{ firstError("employee_type") }}
          </p>
        </div>
        <div class="sm:col-span-2">
          <label
            class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
            >Position</label
          >
          <select
            v-model="form.job_position"
            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
            @change="clearFieldError('job_position')"
          >
            <option
              v-for="opt in jobPositionOptions"
              :key="opt.value === '' ? '_jp_empty' : opt.value"
              :value="opt.value"
            >
              {{ opt.label }}
            </option>
          </select>
          <p
            v-if="firstError('job_position')"
            class="mt-1 text-xs text-red-600"
          >
            {{ firstError("job_position") }}
          </p>
        </div>
        <div>
          <label
            class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
            >Hire Date</label
          >
          <input
            v-model="form.hire_date"
            type="date"
            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
            @change="clearFieldError('hire_date')"
          />
          <p v-if="firstError('hire_date')" class="mt-1 text-xs text-red-600">
            {{ firstError("hire_date") }}
          </p>
        </div>
        <div>
          <label
            class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
            >Termination Date</label
          >
          <input
            v-model="form.terminate_date"
            type="date"
            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
            @change="clearFieldError('terminate_date')"
          />
          <p
            v-if="firstError('terminate_date')"
            class="mt-1 text-xs text-red-600"
          >
            {{ firstError("terminate_date") }}
          </p>
        </div>
      </div>
    </div>

    <div v-if="showSection('bio')" :class="sectionWrapperClass('bio')">
      <h3
        v-if="showSectionTitles"
        class="mb-4 text-sm font-semibold text-gray-900 dark:text-white"
      >
        Bio
      </h3>
      <div>
        <label
          class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
          >Bio</label
        >
        <textarea
          v-model="form.bio"
          rows="4"
          class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
          @input="clearFieldError('bio')"
        />
        <p v-if="firstError('bio')" class="mt-1 text-xs text-red-600">
          {{ firstError("bio") }}
        </p>
      </div>
    </div>
  </div>
</template>
