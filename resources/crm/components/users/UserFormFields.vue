<script setup>
defineProps({
  form: { type: Object, required: true },
  roles: { type: Array, default: () => [] },
  isEdit: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
  firstError: { type: Function, required: true },
  clearFieldError: { type: Function, required: true },
  toggleRole: { type: Function, required: true },
});
</script>

<template>
  <div class="space-y-5">
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
        >Email</label
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
        >{{ isEdit ? "New password (optional)" : "Password" }}</label
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

    <div>
      <label
        class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
        >Phone</label
      >
      <input
        v-model="form.phone"
        type="text"
        autocomplete="tel"
        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
        @input="clearField('phone')"
      />
      <p v-if="firstError('phone')" class="mt-1 text-xs text-red-600">
        {{ firstError("phone") }}
      </p>
    </div>

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
</template>
