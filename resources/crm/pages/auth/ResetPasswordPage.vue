<script setup>
import { reactive, ref } from "vue";
import { RouterLink } from "vue-router";
import api from "../../services/api";
import AuthVuexyShell from "../../components/auth/AuthVuexyShell.vue";

const message = ref("");
const error = ref("");
const loading = ref(false);
const showPassword = ref(false);
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
  <AuthVuexyShell>
    <h1 class="auth-vuexy-heading">Reset password 🔑</h1>
    <p class="auth-vuexy-lead">
      Enter the token from your email and choose a new password.
    </p>

    <div
      v-if="message"
      class="alert alert-success small mt-4 mb-0"
      role="status"
    >
      {{ message }}
    </div>
    <div
      v-if="error"
      class="alert alert-danger small mt-4 mb-0"
      role="alert"
    >
      {{ error }}
    </div>

    <form class="mt-4" @submit.prevent="submit">
      <div class="mb-3">
        <label class="form-label small fw-medium text-body-secondary" for="reset-email">
          Email<span class="text-danger" aria-hidden="true">*</span>
        </label>
        <input
          id="reset-email"
          v-model="form.email"
          type="email"
          required
          autocomplete="email"
          class="form-control"
        />
      </div>
      <div class="mb-3">
        <label class="form-label small fw-medium text-body-secondary" for="reset-token">
          Reset token<span class="text-danger" aria-hidden="true">*</span>
        </label>
        <input
          id="reset-token"
          v-model="form.token"
          type="text"
          required
          autocomplete="one-time-code"
          class="form-control"
        />
      </div>
      <div class="mb-3">
        <label class="form-label small fw-medium text-body-secondary" for="reset-password">
          New password<span class="text-danger" aria-hidden="true">*</span>
        </label>
        <div class="auth-vuexy-input-icon-wrap">
          <input
            id="reset-password"
            v-model="form.password"
            :type="showPassword ? 'text' : 'password'"
            required
            autocomplete="new-password"
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
                d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"
              />
            </svg>
          </button>
        </div>
      </div>
      <div class="mb-4">
        <label class="form-label small fw-medium text-body-secondary" for="reset-password-confirm">
          Confirm password<span class="text-danger" aria-hidden="true">*</span>
        </label>
        <div class="auth-vuexy-input-icon-wrap">
          <input
            id="reset-password-confirm"
            v-model="form.password_confirmation"
            :type="showPassword ? 'text' : 'password'"
            required
            autocomplete="new-password"
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
                d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"
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
        {{ loading ? "Resetting…" : "Reset password" }}
      </button>

      <p class="text-center mt-4 mb-0">
        <RouterLink to="/login" class="auth-vuexy-link small">
          Back to sign in
        </RouterLink>
      </p>
    </form>
  </AuthVuexyShell>
</template>
