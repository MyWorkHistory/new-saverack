<script setup>
import { computed, reactive, ref } from "vue";
import { RouterLink } from "vue-router";
import api from "../../services/api";
import AuthRotatingHero from "../../components/auth/AuthRotatingHero.vue";
import { BRAND_MARK_SRC } from "../../utils/brandAssets.js";

const markSrc = computed(() => BRAND_MARK_SRC());

const message = ref("");
const error = ref("");
const loading = ref(false);
const form = reactive({
  email: "",
  token: "",
  password: "",
  password_confirmation: "",
});

const submit = async () => {
  loading.value = true;
  message.value = "";
  error.value = "";
  try {
    await api.post("/auth/reset-password", form);
    message.value = "Password reset successful. You can sign in now.";
  } catch (e) {
    const d = e?.response?.data;
    error.value =
      d?.message ||
      (typeof d?.error === "string" ? d.error : null) ||
      "Could not reset password.";
  } finally {
    loading.value = false;
  }
};
</script>

<template>
  <div
    class="flex min-h-screen flex-col bg-white lg:flex-row dark:bg-gray-950"
  >
    <div
      class="flex flex-1 flex-col justify-center px-6 py-10 sm:px-10 lg:w-1/2 lg:px-14 xl:px-20"
    >
      <div class="mx-auto w-full max-w-md">
        <h1
          class="text-3xl font-bold tracking-tight text-[#1e3a5f] dark:text-white"
        >
          Reset password
        </h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
          Enter the token from your email and choose a new password.
        </p>

        <p
          v-if="message"
          class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-200"
        >
          {{ message }}
        </p>
        <p
          v-if="error"
          class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300"
        >
          {{ error }}
        </p>

        <form class="mt-8 space-y-4" @submit.prevent="submit">
          <div>
            <label
              class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300"
              for="reset-email"
            >
              Email<span class="text-red-500" aria-hidden="true">*</span>
            </label>
            <input
              id="reset-email"
              v-model="form.email"
              type="email"
              required
              autocomplete="email"
              class="w-full rounded-lg border border-slate-200 bg-slate-50/80 px-3.5 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-slate-600 dark:bg-slate-900 dark:text-white dark:focus:border-blue-400 dark:focus:ring-blue-400/20"
            />
          </div>
          <div>
            <label
              class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300"
              for="reset-token"
            >
              Reset token<span class="text-red-500" aria-hidden="true">*</span>
            </label>
            <input
              id="reset-token"
              v-model="form.token"
              type="text"
              required
              autocomplete="one-time-code"
              class="w-full rounded-lg border border-slate-200 bg-slate-50/80 px-3.5 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-slate-600 dark:bg-slate-900 dark:text-white dark:focus:border-blue-400 dark:focus:ring-blue-400/20"
            />
          </div>
          <div>
            <label
              class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300"
              for="reset-password"
            >
              New password<span class="text-red-500" aria-hidden="true">*</span>
            </label>
            <input
              id="reset-password"
              v-model="form.password"
              type="password"
              required
              autocomplete="new-password"
              class="w-full rounded-lg border border-slate-200 bg-slate-50/80 px-3.5 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-slate-600 dark:bg-slate-900 dark:text-white dark:focus:border-blue-400 dark:focus:ring-blue-400/20"
            />
          </div>
          <div>
            <label
              class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300"
              for="reset-password-confirm"
            >
              Confirm password<span class="text-red-500" aria-hidden="true"
                >*</span
              >
            </label>
            <input
              id="reset-password-confirm"
              v-model="form.password_confirmation"
              type="password"
              required
              autocomplete="new-password"
              class="w-full rounded-lg border border-slate-200 bg-slate-50/80 px-3.5 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-slate-600 dark:bg-slate-900 dark:text-white dark:focus:border-blue-400 dark:focus:ring-blue-400/20"
            />
          </div>

          <button
            type="submit"
            :disabled="loading"
            class="w-full rounded-lg bg-[#38bdf8] py-3 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-[#38bdf8]/50 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:focus:ring-offset-gray-950"
          >
            {{ loading ? "Resetting…" : "Reset password" }}
          </button>

          <RouterLink
            to="/login"
            class="block text-center text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
          >
            Back to sign in
          </RouterLink>
        </form>
      </div>
    </div>

    <AuthRotatingHero>
      <img
        :src="markSrc"
        alt=""
        class="mx-auto h-24 w-24 object-contain sm:h-28 sm:w-28 lg:h-32 lg:w-32"
        width="128"
        height="128"
      />
      <h2
        class="mt-10 text-4xl font-bold tracking-tight text-white sm:text-5xl"
      >
        Save Rack
      </h2>
      <p class="mt-2 max-w-sm text-sm text-slate-400">
        CRM Administration Portal
      </p>
    </AuthRotatingHero>
  </div>
</template>
