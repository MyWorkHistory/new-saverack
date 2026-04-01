<script setup>
import { computed, reactive, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import { setUsersNavFromUser, setWebmasterNavFromUser } from "../../router";
import AuthRotatingHero from "../../components/auth/AuthRotatingHero.vue";
import { BRAND_MARK_SRC } from "../../utils/brandAssets.js";

const markSrc = computed(() => BRAND_MARK_SRC());

const route = useRoute();
const router = useRouter();
const loading = ref(false);
const error = ref("");
const showPassword = ref(false);
const remember = ref(false);

const form = reactive({
  email: "",
  password: "",
});

const submit = async () => {
  error.value = "";
  loading.value = true;
  try {
    const { data } = await api.post("/auth/login", form);
    localStorage.setItem("auth_token", data.token);
    if (remember.value) {
      localStorage.setItem("auth_remember", "1");
    } else {
      localStorage.removeItem("auth_remember");
    }
    setWebmasterNavFromUser(data.user);
    setUsersNavFromUser(data.user);
    const r = route.query.redirect;
    const dest =
      typeof r === "string" && r.startsWith("/") ? r : "/dashboard";
    router.push(dest);
  } catch (e) {
    const d = e?.response?.data;
    const v = d?.errors?.email?.[0] || d?.errors?.password?.[0];
    error.value = v || d?.message || e?.message || "Login failed.";
  } finally {
    loading.value = false;
  }
};
</script>

<template>
  <div
    class="min-h-screen flex flex-col bg-white lg:flex-row dark:bg-gray-950"
  >
    <!-- Form (matches SaveRack branded sign-in #2) -->
    <div
      class="flex flex-1 flex-col justify-center px-6 py-10 sm:px-10 lg:w-1/2 lg:px-14 xl:px-20"
    >
      <div class="mx-auto w-full max-w-md">
        <h1
          class="text-3xl font-bold tracking-tight text-[#1e3a5f] dark:text-white"
        >
          Sign In
        </h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
          Enter your SaveRack account credentials.
        </p>

        <p
          v-if="error"
          class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300"
        >
          {{ error }}
        </p>

        <form class="mt-8 space-y-5" @submit.prevent="submit">
          <div>
            <label
              class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300"
              for="login-email"
            >
              Email<span class="text-red-500" aria-hidden="true">*</span>
            </label>
            <input
              id="login-email"
              v-model="form.email"
              type="email"
              autocomplete="username"
              required
              placeholder="you@company.com"
              class="w-full rounded-lg border border-slate-200 bg-slate-50/80 px-3.5 py-2.5 text-slate-900 shadow-sm outline-none ring-slate-200 transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-slate-600 dark:bg-slate-900 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-blue-400 dark:focus:ring-blue-400/20"
            />
          </div>

          <div>
            <label
              class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300"
              for="login-password"
            >
              Password<span class="text-red-500" aria-hidden="true">*</span>
            </label>
            <div class="relative">
              <input
                id="login-password"
                v-model="form.password"
                :type="showPassword ? 'text' : 'password'"
                autocomplete="current-password"
                required
                placeholder="••••••••"
                class="w-full rounded-lg border border-slate-200 bg-slate-50/80 py-2.5 pl-3.5 pr-11 text-slate-900 shadow-sm outline-none ring-slate-200 transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-slate-600 dark:bg-slate-900 dark:text-white dark:focus:border-blue-400 dark:focus:ring-blue-400/20"
              />
              <button
                type="button"
                class="absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 dark:hover:text-slate-300"
                :aria-pressed="showPassword"
                aria-label="Toggle password visibility"
                @click="showPassword = !showPassword"
              >
                <svg
                  v-if="!showPassword"
                  class="h-5 w-5"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                  />
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                  />
                </svg>
                <svg
                  v-else
                  class="h-5 w-5"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"
                  />
                </svg>
              </button>
            </div>
          </div>

          <div
            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
          >
            <label
              class="flex cursor-pointer items-center gap-2 text-sm text-slate-600 dark:text-slate-400"
            >
              <input
                v-model="remember"
                type="checkbox"
                class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500/30 dark:border-slate-600 dark:bg-slate-900"
              />
              Keep me logged in
            </label>
            <RouterLink
              to="/forgot-password"
              class="text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
            >
              Forgot password?
            </RouterLink>
          </div>

          <button
            type="submit"
            :disabled="loading"
            class="w-full rounded-lg bg-blue-600 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:focus:ring-offset-gray-950"
          >
            {{ loading ? "Signing in…" : "Sign In" }}
          </button>
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
        SaveRack
      </h2>
      <p class="mt-2 max-w-sm text-sm text-slate-400">
        CRM Administration Portal
      </p>
    </AuthRotatingHero>
  </div>
</template>
