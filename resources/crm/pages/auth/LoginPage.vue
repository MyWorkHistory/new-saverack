<script setup>
import { computed, reactive, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import {
  setBillingNavFromUser,
  setClientsNavFromUser,
  setInventoryNavFromUser,
  setUsersNavFromUser,
  setWebmasterNavFromUser,
} from "../../router";
import AuthVuexyShell from "../../components/auth/AuthVuexyShell.vue";
import { getPublicSignupUrl } from "../../utils/publicSignupUrl.js";
import { prefetchPortalDashboardCounts } from "../../composables/usePortalDashboardCounts.js";
import { crmIsPortalUser, crmPortalPostAuthPath } from "../../utils/crmUser.js";
import { errorMessage } from "../../utils/apiError.js";

const publicSignupUrl = computed(() => getPublicSignupUrl());

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
    setClientsNavFromUser(data.user);
    setBillingNavFromUser(data.user);
    setInventoryNavFromUser(data.user);
    const isPortal = crmIsPortalUser(data.user);
    if (isPortal && data.user?.shiphero_ready && data.user?.portal_setup_complete) {
      prefetchPortalDashboardCounts(data.user?.client_account_id);
    }
    const r = route.query.redirect;
    let dest = isPortal ? crmPortalPostAuthPath(data.user) : "/admin/home";
    if (typeof r === "string" && r.startsWith("/")) {
      if (!isPortal || r.startsWith("/users/")) {
        dest = r;
      }
    }
    router.push(dest);
  } catch (e) {
    error.value = errorMessage(e, "Could not sign in. Check your email and password.");
  } finally {
    loading.value = false;
  }
};
</script>

<template>
  <AuthVuexyShell>
    <h1 class="auth-vuexy-heading">Login to Your Account</h1>
    <p class="auth-vuexy-lead">
      Access your fulfillment dashboard.
    </p>

    <div
      v-if="error"
      class="alert alert-danger small mt-4 mb-0"
      role="alert"
    >
      {{ error }}
    </div>

    <form class="mt-4" @submit.prevent="submit">
      <div class="mb-3">
        <label class="form-label small fw-medium text-body-secondary" for="login-email">
          Email<span class="text-danger" aria-hidden="true">*</span>
        </label>
        <input
          id="login-email"
          v-model="form.email"
          type="email"
          autocomplete="username"
          required
          placeholder="Enter your email"
          class="form-control"
        />
      </div>

      <div class="mb-3">
        <label class="form-label small fw-medium text-body-secondary" for="login-password">
          Password<span class="text-danger" aria-hidden="true">*</span>
        </label>
        <div class="auth-vuexy-input-icon-wrap">
          <input
            id="login-password"
            v-model="form.password"
            :type="showPassword ? 'text' : 'password'"
            autocomplete="current-password"
            required
            placeholder="············"
            class="form-control pe-5"
          />
          <button
            type="button"
            class="auth-vuexy-input-toggle"
            :aria-pressed="showPassword"
            aria-label="Toggle password visibility"
            @click="showPassword = !showPassword"
          >
            <svg
              v-if="!showPassword"
              width="20"
              height="20"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              viewBox="0 0 24 24"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
              />
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
              />
            </svg>
            <svg
              v-else
              width="20"
              height="20"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              viewBox="0 0 24 24"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"
              />
            </svg>
          </button>
        </div>
      </div>

      <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-2 mb-4">
        <div class="form-check">
          <input
            id="login-remember"
            v-model="remember"
            type="checkbox"
            class="form-check-input"
          />
          <label class="form-check-label small text-body-secondary" for="login-remember">
            Remember me
          </label>
        </div>
        <RouterLink to="/forgot-password" class="auth-vuexy-link small">
          Forgot password?
        </RouterLink>
      </div>

      <button
        type="submit"
        class="btn btn-primary auth-vuexy-btn-primary w-100"
        :disabled="loading"
      >
        {{ loading ? "Signing in…" : "Sign in" }}
      </button>

      <p class="text-center small text-body-secondary mt-4 mb-0">
        New on our platform?
        <a :href="publicSignupUrl" class="auth-vuexy-link">Create an account</a>
      </p>
    </form>
  </AuthVuexyShell>
</template>
