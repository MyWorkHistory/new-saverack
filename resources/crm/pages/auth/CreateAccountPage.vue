<script setup>
import { reactive, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import {
  setClientsNavFromUser,
  setUsersNavFromUser,
  setWebmasterNavFromUser,
} from "../../router";
import AuthVuexyShell from "../../components/auth/AuthVuexyShell.vue";

const route = useRoute();
const router = useRouter();
const loading = ref(false);
const error = ref("");
const showPassword = ref(false);

const form = reactive({
  company_name: "",
  full_name: "",
  email: "",
  phone: "",
  password: "",
  password_confirmation: "",
});

const submit = async () => {
  error.value = "";
  loading.value = true;
  try {
    const { data } = await api.post("/auth/register", {
      company_name: form.company_name.trim(),
      full_name: form.full_name.trim(),
      email: form.email.trim(),
      phone: form.phone.trim(),
      password: form.password,
      password_confirmation: form.password_confirmation,
    });
    localStorage.setItem("auth_token", data.token);
    setWebmasterNavFromUser(data.user);
    setUsersNavFromUser(data.user);
    setClientsNavFromUser(data.user);
    const r = route.query.redirect;
    const dest =
      typeof r === "string" && r.startsWith("/") ? r : "/dashboard";
    router.push(dest);
  } catch (e) {
    const d = e?.response?.data;
    const errs = d?.errors;
    const pick =
      (errs &&
        [
          errs.company_name?.[0],
          errs.full_name?.[0],
          errs.email?.[0],
          errs.phone?.[0],
          errs.password?.[0],
        ].find(Boolean)) ||
      null;
    error.value = pick || d?.message || e?.message || "Could not create account.";
  } finally {
    loading.value = false;
  }
};
</script>

<template>
  <AuthVuexyShell>
    <h1 class="auth-vuexy-heading">Adventure starts here 🚀</h1>
    <p class="auth-vuexy-lead">
      Create your 3PL account with Save Rack and start managing your workspace.
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
        <label class="form-label small fw-medium text-body-secondary" for="create-company">
          Company name<span class="text-danger" aria-hidden="true">*</span>
        </label>
        <input
          id="create-company"
          v-model="form.company_name"
          type="text"
          required
          autocomplete="organization"
          class="form-control"
        />
      </div>

      <div class="mb-3">
        <label class="form-label small fw-medium text-body-secondary" for="create-full-name">
          Full name<span class="text-danger" aria-hidden="true">*</span>
        </label>
        <input
          id="create-full-name"
          v-model="form.full_name"
          type="text"
          required
          autocomplete="name"
          class="form-control"
        />
      </div>

      <div class="mb-3">
        <label class="form-label small fw-medium text-body-secondary" for="create-email">
          Email<span class="text-danger" aria-hidden="true">*</span>
        </label>
        <input
          id="create-email"
          v-model="form.email"
          type="email"
          autocomplete="username"
          required
          placeholder="Enter your email"
          class="form-control"
        />
      </div>

      <div class="mb-3">
        <label class="form-label small fw-medium text-body-secondary" for="create-phone">
          Phone<span class="text-danger" aria-hidden="true">*</span>
        </label>
        <input
          id="create-phone"
          v-model="form.phone"
          type="tel"
          required
          autocomplete="tel"
          class="form-control"
        />
      </div>

      <div class="mb-3">
        <label class="form-label small fw-medium text-body-secondary" for="create-password">
          Password<span class="text-danger" aria-hidden="true">*</span>
        </label>
        <div class="auth-vuexy-input-icon-wrap">
          <input
            id="create-password"
            v-model="form.password"
            :type="showPassword ? 'text' : 'password'"
            autocomplete="new-password"
            required
            minlength="8"
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

      <div class="mb-4">
        <label class="form-label small fw-medium text-body-secondary" for="create-password-confirm">
          Confirm password<span class="text-danger" aria-hidden="true">*</span>
        </label>
        <div class="auth-vuexy-input-icon-wrap">
          <input
            id="create-password-confirm"
            v-model="form.password_confirmation"
            :type="showPassword ? 'text' : 'password'"
            autocomplete="new-password"
            required
            minlength="8"
            placeholder="············"
            class="form-control pe-5"
          />
          <button
            type="button"
            class="auth-vuexy-input-toggle"
            :aria-pressed="showPassword"
            aria-label="Toggle confirm password visibility"
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

      <button
        type="submit"
        class="btn btn-primary auth-vuexy-btn-primary w-100"
        :disabled="loading"
      >
        {{ loading ? "Creating…" : "Sign up" }}
      </button>

      <p class="text-center small text-body-secondary mt-4 mb-0">
        Already have an account?
        <RouterLink to="/login" class="auth-vuexy-link">Sign in instead</RouterLink>
      </p>

      <div class="auth-vuexy-divider"><span>or</span></div>

      <div class="auth-vuexy-social">
        <button type="button" aria-label="Facebook (coming soon)">
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
          </svg>
        </button>
        <button type="button" aria-label="Twitter (coming soon)">
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z" />
          </svg>
        </button>
        <button type="button" aria-label="GitHub (coming soon)">
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd" />
          </svg>
        </button>
        <button type="button" aria-label="Google (coming soon)">
          <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
            <path fill="#EA4335" d="M12 10.2v3.9h5.5c-.2 1.5-1.8 4.4-5.5 4.4-3.3 0-6-2.7-6-6s2.7-6 6-6c1.9 0 3.2.8 3.9 1.5l2.6-2.5C16.6 3.6 14.5 2.7 12 2.7 6.9 2.7 2.7 6.9 2.7 12s4.2 9.3 9.3 9.3c5.4 0 8.9-3.8 8.9-9.3 0-.6-.1-1.1-.2-1.6H12z" />
            <path fill="#4285F4" d="M3.3 7.1l3.3 2.4C7.4 8 9.5 6.7 12 6.7c1.9 0 3.2.8 3.9 1.5l2.6-2.5C16.6 3.6 14.5 2.7 12 2.7 8 2.7 4.7 4.6 3.3 7.1z" />
            <path fill="#FBBC05" d="M12 21.3c2.4 0 4.5-.8 6-2.2l-2.8-2.2c-.8.5-1.8.9-3.2.9-2.5 0-4.6-1.7-5.4-4l-3.2 2.5c1.4 2.8 4.3 5 8.4 5z" />
            <path fill="#34A853" d="M21.6 12.2c0-.6-.1-1.1-.2-1.6H12v3.9h5.5c-.2 1.5-1.8 4.4-5.5 4.4-1.5 0-2.9-.5-3.9-1.5l-3.3 2.4c1.5 2.2 4 3.7 7.2 3.7 5.2 0 9.6-3.8 9.6-8.9 0-.6-.1-1.3-.2-1.9z" />
          </svg>
        </button>
      </div>
    </form>
  </AuthVuexyShell>
</template>
