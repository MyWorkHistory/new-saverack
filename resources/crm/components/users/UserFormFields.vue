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
  <div class="space-y-8">
    <div class="space-y-5">
      <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
        Account
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
          >Login email</label
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

    <div class="border-t border-gray-100 pt-6 dark:border-gray-800">
      <h3 class="mb-4 text-sm font-semibold text-gray-900 dark:text-white">
        Contact &amp; location
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
          <label
            class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
            >Birthday</label
          >
          <input
            v-model="form.birthday"
            type="date"
            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
            @change="clearFieldError('birthday')"
          />
          <p v-if="firstError('birthday')" class="mt-1 text-xs text-red-600">
            {{ firstError("birthday") }}
          </p>
        </div>
        <div class="sm:col-span-2">
          <label
            class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
            >Street address</label
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
            >Postal code</label
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
            >Region</label
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

    <div class="border-t border-gray-100 pt-6 dark:border-gray-800">
      <h3 class="mb-4 text-sm font-semibold text-gray-900 dark:text-white">
        Employment
      </h3>
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
          <label
            class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
            >Employment type</label
          >
          <input
            v-model="form.employee_type"
            type="text"
            placeholder="e.g. Full-time, Contractor"
            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
            @input="clearFieldError('employee_type')"
          />
          <p
            v-if="firstError('employee_type')"
            class="mt-1 text-xs text-red-600"
          >
            {{ firstError("employee_type") }}
          </p>
        </div>
        <div>
          <label
            class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
            >Hire date</label
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
            >Termination date</label
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

    <div class="border-t border-gray-100 pt-6 dark:border-gray-800">
      <h3 class="mb-4 text-sm font-semibold text-gray-900 dark:text-white">
        Notes
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
